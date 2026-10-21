<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class FixedServe implements Serve
{
    public function __construct(public string $variation) {}
}
