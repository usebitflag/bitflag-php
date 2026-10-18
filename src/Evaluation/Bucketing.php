<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

/**
 * Constants of the stable rollout algorithm. The evaluation logic itself is added on top of
 * the conformance fixtures published by bitflag-protocol.
 */
final class Bucketing
{
    /** Identifier of the bucketing algorithm. */
    public const ALGORITHM = 'bucket-sha256-v1';

    /** Buckets are numbered 0 through BUCKET_COUNT - 1. */
    public const BUCKET_COUNT = 100_000;
}
