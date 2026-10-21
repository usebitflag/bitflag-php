<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class SubjectRef
{
    public function __construct(public string $kind, public string $key) {}
}
