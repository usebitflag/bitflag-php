<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Condition
{
    /**
     * @param  list<string|int|float|bool>  $values
     */
    public function __construct(
        public Operator $operator,
        public ?string $attribute,
        public array $values,
        public bool $ignoreCase,
    ) {}
}
