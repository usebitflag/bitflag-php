<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use BitFlag\Evaluation\Exception\FallbackTypeError;
use BitFlag\Evaluation\Internal\Abort;
use BitFlag\Evaluation\Internal\Decision;
use BitFlag\Evaluation\Internal\FlagEvaluator;
use BitFlag\Evaluation\Internal\Values\Instant;
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

        try {
            $decision = $this->decide($flag, $context, $atMs);
        } catch (Abort $abort) {
            return $this->failure($flagKey, $fallback, Reason::Error, $abort->errorKind, $atMs);
        }

        return $this->served($flag, $decision, $options, $atMs);
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

    private function decide(Flag $flag, Context $context, int $atMs): Decision
    {
        $evaluator = new FlagEvaluator($this->snapshot, $context, $atMs, $this->supportedCapabilities ?? self::supportedCapabilities());

        return $evaluator->decide($flag, [$flag->key]);
    }

    private function served(Flag $flag, Decision $decision, EvaluationOptions $options, int $atMs): EvaluationResult
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
        );
    }

    private function failure(string $flagKey, mixed $fallback, Reason $reason, ErrorKind $kind, int $atMs): EvaluationResult
    {
        return new EvaluationResult($flagKey, $fallback, null, $reason, null, null, $this->snapshot->revision, $this->snapshot->generation, null, null, null, $kind, Instant::format($atMs));
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
