<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Rule
{
    public function __construct(
        public string $id,
        public bool $enabled,
        public ConditionGroup $match,
        public Serve $serve,
    ) {}
}
