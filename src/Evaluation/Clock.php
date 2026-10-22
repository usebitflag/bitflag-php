<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

use DateTimeImmutable;

/**
 * The source of "now" for override expiry. Injected so that results are reproducible.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
