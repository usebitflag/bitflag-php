<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Prerequisite
{
    /**
     * @param  list<string>  $variations  variation keys of the prerequisite flag that count as met
     */
    public function __construct(public string $flagKey, public array $variations) {}
}
