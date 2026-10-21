<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Slot
{
    public function __construct(public string $variation, public int $weight) {}
}
