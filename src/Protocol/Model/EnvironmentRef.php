<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class EnvironmentRef
{
    public function __construct(public string $id, public string $key) {}
}
