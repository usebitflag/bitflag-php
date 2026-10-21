<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use DateTimeImmutable;

final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
