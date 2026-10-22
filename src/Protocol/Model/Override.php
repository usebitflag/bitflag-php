<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Override
{
    /**
     * @param  int|null  $expiresAtMs  epoch milliseconds, or null when the override never expires
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $variation,
        public ?int $expiresAtMs,
        public ?string $expiresAt,
    ) {}
}
