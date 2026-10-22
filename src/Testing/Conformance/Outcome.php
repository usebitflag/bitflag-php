<?php

declare(strict_types=1);

namespace BitFlag\Testing\Conformance;

/**
 * The outcome of one conformance case: what went wrong, and what the evaluator actually did.
 */
final readonly class Outcome
{
    /**
     * @param  list<string>  $failures  empty when the case passed
     * @param  array<string, mixed>|null  $actual  the report entry for the case
     */
    public function __construct(
        public string $fixture,
        public string $name,
        public array $failures,
        public ?array $actual = null,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }

    public function describe(): string
    {
        return $this->fixture . ' / ' . $this->name . ($this->passed() ? '' : ":\n  - " . implode("\n  - ", $this->failures));
    }
}
