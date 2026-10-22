<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class RolloutServe implements Serve
{
    /**
     * @param  list<Slot>  $slots
     */
    public function __construct(
        public string $bucketBy,
        public string $salt,
        public array $slots,
        public ?string $remainderVariation,
        public ?string $allocationId,
        public ?string $experimentId,
    ) {}
}
