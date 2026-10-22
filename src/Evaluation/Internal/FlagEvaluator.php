<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Evaluation\Bucketing\BucketSha256V1;
use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\Reason;
use BitFlag\Evaluation\Trace\ConditionTrace;
use BitFlag\Evaluation\Trace\TraceStep;
use BitFlag\Protocol\Model\FixedServe;
use BitFlag\Protocol\Model\Flag;
use BitFlag\Protocol\Model\RolloutServe;
use BitFlag\Protocol\Model\Snapshot;

/**
 * Decides which variation one flag serves for one context at one instant: capabilities, enabled, prerequisites,
 * overrides, rules, default. Aborts with an error kind when the configuration or the input is unusable.
 *
 * One instance serves one top-level evaluation; it remembers what each prerequisite flag served. When asked to
 * trace, it records the steps of the evaluated flag (not those of its prerequisites).
 *
 * @internal
 */
final class FlagEvaluator
{
    /** The deepest chain of prerequisites: the evaluated flag plus this many levels below it. */
    public const MAX_PREREQUISITE_DEPTH = 10;

    /** @var array<string, string> variation served by a prerequisite flag, by flag key */
    private array $served = [];

    /** @var list<TraceStep> */
    private array $steps = [];

    private readonly ConditionMatcher $matcher;

    /**
     * @param  list<string>  $supportedCapabilities
     */
    public function __construct(
        private readonly Snapshot $snapshot,
        private readonly Context $context,
        private readonly int $atMs,
        private readonly array $supportedCapabilities,
        private readonly bool $trace = false,
    ) {
        $segments = new SegmentMatcher($snapshot->segments, $context);
        $this->matcher = new ConditionMatcher($context, $segments->isMember(...));
    }

    /**
     * The steps recorded so far for the evaluated flag; useful after an abort.
     *
     * @return list<TraceStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @param  list<string>  $visiting  keys of the flags on the path from the evaluated flag to this one
     *
     * @throws Abort
     */
    public function decide(Flag $flag, array $visiting): Decision
    {
        $top = count($visiting) === 1;
        $record = $this->trace && $top;

        if ($flag->requires !== []) {
            $unsupported = array_values(array_diff($flag->requires, $this->supportedCapabilities));
            if ($record) {
                $this->steps[] = new TraceStep('capability', $unsupported === [] ? 'supported' : 'unsupported');
            }
            if ($unsupported !== []) {
                throw new Abort(ErrorKind::UnsupportedCapability);
            }
        }

        if (! $flag->enabled) {
            if ($record) {
                $this->steps[] = new TraceStep('off', 'served');
            }

            return new Decision($this->variation($flag, $flag->offVariation), Reason::Off);
        }

        foreach ($flag->prerequisites as $prerequisite) {
            if (in_array($prerequisite->flagKey, $visiting, true) || count($visiting) > self::MAX_PREREQUISITE_DEPTH) {
                throw new Abort(ErrorKind::InvalidConfig);
            }
            $path = [...$visiting, $prerequisite->flagKey];
            $served = $this->servedBy($prerequisite->flagKey, $path);
            $met = in_array($served, $prerequisite->variations, true);
            if ($record) {
                $this->steps[] = new TraceStep('prerequisite', $met ? 'met' : 'unmet', prerequisitePath: array_slice($path, 1));
            }
            if (! $met) {
                return new Decision($this->variation($flag, $flag->offVariation), Reason::Prerequisite, prerequisiteKey: $prerequisite->flagKey);
            }
        }

        foreach ($flag->overrides as $override) {
            if ($this->context->key === null || $override->kind !== $this->context->kind || $override->key !== $this->context->key) {
                continue;
            }
            $active = $override->expiresAtMs === null || $this->atMs < $override->expiresAtMs;
            if ($record) {
                $this->steps[] = new TraceStep('override', $active ? 'applied' : 'expired', overrideExpiresAt: $override->expiresAt);
            }
            if ($active) {
                return new Decision($this->variation($flag, $override->variation), Reason::Override);
            }
        }

        foreach ($flag->rules as $rule) {
            if (! $rule->enabled) {
                if ($record) {
                    $this->steps[] = new TraceStep('rule', 'disabled', ruleId: $rule->id);
                }

                continue;
            }

            $conditions = $record ? [] : null;
            $matched = $this->matcher->matches($rule->match, $conditions);
            if (! $matched) {
                if ($record) {
                    $this->steps[] = new TraceStep('rule', 'not_matched', ruleId: $rule->id, conditions: $conditions);
                }

                continue;
            }
            if ($record) {
                $this->steps[] = new TraceStep('rule', 'matched', ruleId: $rule->id, conditions: $conditions);
            }

            if ($rule->serve instanceof FixedServe) {
                return new Decision($this->variation($flag, $rule->serve->variation), Reason::Rule, ruleId: $rule->id);
            }
            if ($rule->serve instanceof RolloutServe) {
                return $this->rollout($flag, $rule->id, $rule->serve, $conditions, $record);
            }

            throw new Abort(ErrorKind::InvalidConfig);
        }

        if ($record) {
            $this->steps[] = new TraceStep('default', 'served');
        }

        return new Decision($this->variation($flag, $flag->defaultVariation), Reason::Default);
    }

    /**
     * @param  list<ConditionTrace>|null  $conditions
     */
    private function rollout(Flag $flag, string $ruleId, RolloutServe $rollout, ?array $conditions, bool $record = false): Decision
    {
        $subject = $rollout->bucketBy === 'key' ? $this->context->key : ($this->context->attributes[$rollout->bucketBy] ?? null);
        if (! is_string($subject) || $subject === '') {
            throw new Abort(ErrorKind::MissingSubjectKey);
        }

        $bucket = BucketSha256V1::bucket($rollout->salt, $this->context->kind, $subject);
        $slot = SlotSelector::select($rollout->slots, $bucket);
        $variation = $slot['variation'] ?? $rollout->remainderVariation ?? throw new Abort(ErrorKind::InvalidConfig);

        if ($record) {
            // Replace the "matched" step of this rule with one that also names the bucket and the interval.
            array_pop($this->steps);
            $this->steps[] = new TraceStep('rule', 'matched', ruleId: $ruleId, conditions: $conditions, bucket: $bucket, slot: ['variation' => $variation, 'start' => $slot['start'], 'end' => $slot['end']]);
        }

        return new Decision(
            $this->variation($flag, $variation),
            Reason::Rule,
            ruleId: $ruleId,
            bucket: $bucket,
            experimentId: $rollout->experimentId,
            allocationId: $rollout->allocationId,
        );
    }

    /**
     * What a prerequisite flag serves, computed once per top-level call.
     *
     * @param  list<string>  $visiting
     */
    private function servedBy(string $flagKey, array $visiting): string
    {
        if (isset($this->served[$flagKey])) {
            return $this->served[$flagKey];
        }

        $flag = $this->snapshot->flag($flagKey) ?? throw new Abort(ErrorKind::InvalidConfig);

        return $this->served[$flagKey] = $this->decide($flag, $visiting)->variation;
    }

    /**
     * @throws Abort when the flag has no such variation
     */
    private function variation(Flag $flag, string $key): string
    {
        return array_key_exists($key, $flag->variations) ? $key : throw new Abort(ErrorKind::InvalidConfig);
    }
}
