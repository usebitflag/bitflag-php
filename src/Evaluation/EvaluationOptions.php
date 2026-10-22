<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use DateTimeImmutable;

/**
 * Per-call options. Immutable: each wither returns a changed copy.
 */
final readonly class EvaluationOptions
{
    private function __construct(
        public ?DateTimeImmutable $time,
        public bool $details,
        public bool $trace,
    ) {}

    public static function defaults(): self
    {
        return new self(null, false, false);
    }

    /** Evaluate at this instant instead of the clock's now (fixtures, the simulator). */
    public function at(DateTimeImmutable $time): self
    {
        return new self($time, $this->details, $this->trace);
    }

    /** Report the bucket of a rollout decision. */
    public function withDetails(): self
    {
        return new self($this->time, true, $this->trace);
    }

    /** Attach the trace. For trusted callers only; never on a public path. */
    public function withTrace(): self
    {
        return new self($this->time, $this->details, true);
    }
}
