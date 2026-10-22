<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Flag
{
    /**
     * @param  array<string, mixed>  $variations  variation key to value; json values are decoded JSON (objects as stdClass)
     * @param  list<string>  $requires
     * @param  list<Prerequisite>  $prerequisites
     * @param  list<Override>  $overrides
     * @param  list<Rule>  $rules
     */
    public function __construct(
        public string $key,
        public FlagType $type,
        public bool $enabled,
        public array $variations,
        public string $offVariation,
        public string $defaultVariation,
        public array $requires,
        public array $prerequisites,
        public array $overrides,
        public array $rules,
    ) {}
}
