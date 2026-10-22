<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Trace;

/**
 * Why a result came out as it did. Names attributes and outcomes, never context values.
 */
final readonly class EvaluationTrace
{
    /**
     * @param  list<TraceStep>  $steps
     */
    public function __construct(public array $steps) {}

    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['steps' => array_map(static fn(TraceStep $s): array => $s->toArray(), $this->steps)];
    }
}
