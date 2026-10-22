<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use BitFlag\Evaluation\Trace\EvaluationTrace;

/**
 * The outcome of evaluating one flag for one context.
 */
final readonly class EvaluationResult
{
    /**
     * @param  mixed  $value  the served value, or the caller's fallback for FALLBACK and ERROR
     */
    public function __construct(
        public string $flagKey,
        public mixed $value,
        public ?string $variationKey,
        public Reason $reason,
        public ?string $ruleId,
        public ?string $prerequisiteKey,
        public ?int $revision,
        public ?int $generation,
        public ?int $bucket,
        public ?string $experimentId,
        public ?string $allocationId,
        public ?ErrorKind $errorKind,
        public string $evaluatedAt,
        public ?EvaluationTrace $trace = null,
    ) {}

    /**
     * Whether the value is one the snapshot serves, as opposed to the caller's fallback.
     */
    public function isServed(): bool
    {
        return $this->variationKey !== null;
    }

    /**
     * The shape of result.schema.json.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'flagKey' => $this->flagKey,
            'value' => $this->value,
            'variationKey' => $this->variationKey,
            'reason' => $this->reason->value,
            'ruleId' => $this->ruleId,
            'prerequisiteKey' => $this->prerequisiteKey,
            'revision' => $this->revision,
            'generation' => $this->generation,
            'bucket' => $this->bucket,
            'experimentId' => $this->experimentId,
            'allocationId' => $this->allocationId,
            'errorKind' => $this->errorKind?->value,
            'evaluatedAt' => $this->evaluatedAt,
        ];
        if ($this->trace !== null) {
            $out['trace'] = $this->trace->toArray();
        }

        return $out;
    }
}
