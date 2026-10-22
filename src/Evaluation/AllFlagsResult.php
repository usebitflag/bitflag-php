<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

/**
 * Every flag of a snapshot evaluated for one context, each as its declared type.
 *
 * A result has the fields of an {@see EvaluationResult}, except that value and variationKey are null whenever the
 * reason is ERROR, because there is no caller fallback to return. FALLBACK cannot occur. For bootstrapping and
 * debugging only: never feed these values into typed reads.
 */
final readonly class AllFlagsResult
{
    /**
     * @param  array<string, EvaluationResult>  $results  by flag key
     */
    public function __construct(
        public array $results,
        public ?int $revision,
        public ?int $generation,
    ) {}

    /**
     * @return array{results: array<string, array<string, mixed>>, revision: int|null, generation: int|null}
     */
    public function toArray(): array
    {
        return [
            'results' => array_map(static fn(EvaluationResult $r): array => $r->toArray(), $this->results),
            'revision' => $this->revision,
            'generation' => $this->generation,
        ];
    }
}
