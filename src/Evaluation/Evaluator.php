<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use BitFlag\Evaluation\Exception\FallbackTypeError;
use BitFlag\Evaluation\Internal\Abort;
use BitFlag\Evaluation\Internal\Decision;
use BitFlag\Evaluation\Internal\FlagEvaluator;
use BitFlag\Evaluation\Internal\Values\Instant;
use BitFlag\Evaluation\Trace\EvaluationTrace;
use BitFlag\Protocol\Model\Capability;
use BitFlag\Protocol\Model\Flag;
use BitFlag\Protocol\Model\FlagType;
use BitFlag\Protocol\Model\Snapshot;
use DateTimeImmutable;
use stdClass;

/**
 * Evaluates the flags of one snapshot. Pure: no I/O, no randomness, no global state; the only clock is the one
 * given to the constructor, and a time in the options overrides it.
 *
 * It throws only {@see FallbackTypeError}, for a fallback that does not have the requested type. Every other
 * problem is a result with a reason and an error kind.
 */
final class Evaluator
{
    /**
     * @param  list<string>|null  $supportedCapabilities  defaults to every capability of protocol version 1
     */
    public function __construct(
        private readonly Snapshot $snapshot,
        private readonly Clock $clock = new SystemClock(),
        private readonly ?array $supportedCapabilities = null,
    ) {}

    /**
     * @return list<string>
     */
    public static function supportedCapabilities(): array
    {
        return array_map(static fn(Capability $c): string => $c->value, Capability::cases());
    }

    /**
     * @throws FallbackTypeError when $fallback does not have the requested type
     */
    public function evaluate(string $flagKey, Context $context, FlagType $type, mixed $fallback, ?EvaluationOptions $options = null): EvaluationResult
    {
        if (! self::hasType($fallback, $type)) {
            throw new FallbackTypeError(sprintf('The fallback for "%s" must be of type %s, %s given.', $flagKey, $type->value, get_debug_type($fallback)));
        }

        $options ??= EvaluationOptions::defaults();
        $atMs = $this->millis($options->time ?? $this->clock->now());

        if (! $context->isValid()) {
            return $this->failure($flagKey, $fallback, Reason::Error, ErrorKind::InvalidContext, $atMs);
        }

        $flag = $this->snapshot->flag($flagKey);
        if ($flag === null) {
            return $this->failure($flagKey, $fallback, Reason::Fallback, ErrorKind::FlagNotFound, $atMs);
        }
        if ($flag->type !== $type) {
            return $this->failure($flagKey, $fallback, Reason::Fallback, ErrorKind::TypeMismatch, $atMs);
        }

        return $this->evaluateFlag($flag, $context, $fallback, $options, $atMs);
    }

    /**
     * Evaluates every flag of the snapshot as its declared type. There is no caller fallback, so a flag that cannot
     * be served has a null value and a null variation key.
     */
    public function evaluateAll(Context $context, ?EvaluationOptions $options = null): AllFlagsResult
    {
        $options ??= EvaluationOptions::defaults();
        $atMs = $this->millis($options->time ?? $this->clock->now());

        $results = [];
        foreach ($this->snapshot->flags as $key => $flag) {
            $results[$key] = $context->isValid()
                ? $this->evaluateFlag($flag, $context, null, $options, $atMs)
                : $this->failure($key, null, Reason::Error, ErrorKind::InvalidContext, $atMs);
        }

        return new AllFlagsResult($results, $this->snapshot->revision, $this->snapshot->generation);
    }

    private function evaluateFlag(Flag $flag, Context $context, mixed $fallback, EvaluationOptions $options, int $atMs): EvaluationResult
    {
        $evaluator = new FlagEvaluator($this->snapshot, $context, $atMs, $this->supportedCapabilities ?? self::supportedCapabilities(), $options->trace);

        try {
            $decision = $evaluator->decide($flag, [$flag->key]);
        } catch (Abort $abort) {
            return $this->failure($flag->key, $fallback, Reason::Error, $abort->errorKind, $atMs, $options->trace ? new EvaluationTrace($evaluator->steps()) : null);
        }

        return $this->served($flag, $decision, $options, $atMs, $options->trace ? new EvaluationTrace($evaluator->steps()) : null);
    }

    public function evaluateBoolean(string $flagKey, Context $context, bool $fallback, ?EvaluationOptions $options = null): EvaluationResult
    {
        return $this->evaluate($flagKey, $context, FlagType::Boolean, $fallback, $options);
    }

    public function evaluateString(string $flagKey, Context $context, string $fallback, ?EvaluationOptions $options = null): EvaluationResult
    {
        return $this->evaluate($flagKey, $context, FlagType::String, $fallback, $options);
    }

    public function evaluateNumber(string $flagKey, Context $context, int|float $fallback, ?EvaluationOptions $options = null): EvaluationResult
    {
        return $this->evaluate($flagKey, $context, FlagType::Number, $fallback, $options);
    }

    public function evaluateJson(string $flagKey, Context $context, mixed $fallback, ?EvaluationOptions $options = null): EvaluationResult
    {
        return $this->evaluate($flagKey, $context, FlagType::Json, $fallback, $options);
    }

    private function served(Flag $flag, Decision $decision, EvaluationOptions $options, int $atMs, ?EvaluationTrace $trace): EvaluationResult
    {
        return new EvaluationResult(
            $flag->key,
            $flag->variations[$decision->variation],
            $decision->variation,
            $decision->reason,
            $decision->ruleId,
            $decision->prerequisiteKey,
            $this->snapshot->revision,
            $this->snapshot->generation,
            $options->details ? $decision->bucket : null,
            $decision->experimentId,
            $decision->allocationId,
            null,
            Instant::format($atMs),
            $trace,
        );
    }

    private function failure(string $flagKey, mixed $fallback, Reason $reason, ErrorKind $kind, int $atMs, ?EvaluationTrace $trace = null): EvaluationResult
    {
        return new EvaluationResult($flagKey, $fallback, null, $reason, null, null, $this->snapshot->revision, $this->snapshot->generation, null, null, null, $kind, Instant::format($atMs), $trace);
    }

    private function millis(DateTimeImmutable $time): int
    {
        return (int) $time->format('U') * 1000 + (int) $time->format('v');
    }

    private static function hasType(mixed $value, FlagType $type): bool
    {
        return match ($type) {
            FlagType::Boolean => is_bool($value),
            FlagType::String => is_string($value),
            FlagType::Number => is_int($value) || (is_float($value) && is_finite($value)),
            FlagType::Json => self::isJson($value),
        };
    }

    private static function isJson(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_string($value) || is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! self::isJson($item)) {
                return false;
            }
        }

        return true;
    }
}
