<?php

declare(strict_types=1);

use BitFlag\Evaluation\Bucketing;
use BitFlag\Protocol\ProtocolVersion;

it('identifies the bucketing algorithm and bucket range', function () {
    expect(Bucketing::ALGORITHM)->toBe('bucket-sha256-v1')
        ->and(Bucketing::BUCKET_COUNT)->toBe(100_000);
});

it('implements protocol major version 1', function () {
    expect(ProtocolVersion::MAJOR)->toBe(1);
});
