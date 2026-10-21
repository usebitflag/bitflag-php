<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Evaluation\Bucketing\BucketSha256V1;
use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\Reason;
use BitFlag\Protocol\Model\FixedServe;
use BitFlag\Protocol\Model\Flag;
use BitFlag\Protocol\Model\RolloutServe;
use BitFlag\Protocol\Model\Snapshot;

/**
 * Decides which variation one flag serves for one context at one instant: capabilities, enabled, prerequisites,
 * overrides, rules, default. Aborts with an error kind when the configuration or the input is unusable.
 *
 * One instance serves one top-level evaluation; it remembers what each prerequisite flag served.
 *
 * @internal
 */
final class FlagEvaluator
{
    /** The deepest chain of prerequisites: the evaluated flag plus this many levels below it. */
    public const MAX_PREREQUISITE_DEPTH = 10;

    /** @var array<string, string> variation served by a prerequisite flag, by flag key */
    private array $served = [];

    private readonly ConditionMatcher $matcher;

    /**
     * @param  list<string>  $supportedCapabilities
     */
    public function __construct(
        private readonly Snapshot $snapshot,
        private readonly Context $context,
        private readonly int $atMs,
        private readonly array $supportedCapabilities,
    ) {
        $segments = new SegmentMatcher($snapshot->segments, $context);
        $this->matcher = new ConditionMatcher($context, $segments->isMember(...));
    }

    /**
     * @param  list<string>  $visiting  keys of the flags on the path from the evaluated flag to this one
     *
     * @throws Abort
     */
    public function decide(Flag $flag, array $visiting): Decision
    {
        foreach ($flag->requires as $capability) {
            if (! in_array($capability, $this->supportedCapabilities, true)) {
                throw new Abort(ErrorKind::UnsupportedCapability);
            }
        }

        if (! $flag->enabled) {
            return new Decision($this->variation($flag, $flag->offVariation), Reason::Off);
        }

        foreach ($flag->prerequisites as $prerequisite) {
            if (in_array($prerequisite->flagKey, $visiting, true) || count($visiting) > self::MAX_PREREQUISITE_DEPTH) {
                throw new Abort(ErrorKind::InvalidConfig);
            }
            $served = $this->servedBy($prerequisite->flagKey, [...$visiting, $prerequisite->flagKey]);
            if (! in_array($served, $prerequisite->variations, true)) {
                return new Decision($this->variation($flag, $flag->offVariation), Reason::Prerequisite, prerequisiteKey: $prerequisite->flagKey);
            }
        }

        foreach ($flag->overrides as $override) {
            if ($this->context->key !== null
                && $override->kind === $this->context->kind
                && $override->key === $this->context->key
                && ($override->expiresAtMs === null || $this->atMs < $override->expiresAtMs)) {
                return new Decision($this->variation($flag, $override->variation), Reason::Override);
            }
        }

        foreach ($flag->rules as $rule) {
            if (! $rule->enabled || ! $this->matcher->matches($rule->match)) {
                continue;
            }

            return match (true) {
                $rule->serve instanceof FixedServe => new Decision($this->variation($flag, $rule->serve->variation), Reason::Rule, ruleId: $rule->id),
                $rule->serve instanceof RolloutServe => $this->rollout($flag, $rule->id, $rule->serve),
                default => throw new Abort(ErrorKind::InvalidConfig),
            };
        }

        return new Decision($this->variation($flag, $flag->defaultVariation), Reason::Default);
    }

    private function rollout(Flag $flag, string $ruleId, RolloutServe $rollout): Decision
    {
        $subject = $rollout->bucketBy === 'key' ? $this->context->key : ($this->context->attributes[$rollout->bucketBy] ?? null);
        if (! is_string($subject) || $subject === '') {
            throw new Abort(ErrorKind::MissingSubjectKey);
        }

        $bucket = BucketSha256V1::bucket($rollout->salt, $this->context->kind, $subject);
        $slot = SlotSelector::select($rollout->slots, $bucket);
        $variation = $slot['variation'] ?? $rollout->remainderVariation ?? throw new Abort(ErrorKind::InvalidConfig);

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
