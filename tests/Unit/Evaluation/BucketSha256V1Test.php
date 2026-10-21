<?php

declare(strict_types=1);

use BitFlag\Evaluation\Bucketing\BucketSha256V1;
use BitFlag\Evaluation\Internal\SlotSelector;
use BitFlag\Protocol\Model\Slot;

it('computes the known buckets', function (string $salt, string $kind, string $value, string $digestPrefix, int $bucket) {
    $digest = hash('sha256', BucketSha256V1::input($salt, $kind, $value));

    expect(substr($digest, 0, 16))->toBe($digestPrefix)
        ->and(BucketSha256V1::bucket($salt, $kind, $value))->toBe($bucket);
})->with([
    'the worked example' => ['s_9f2c', 'user', 'u_20931', '9f9eda90f9168dca', 76138],
    'NFC' => ['s_9f2c', 'user', "Jos\u{00E9}", '1ebaefa16e2bd815', 60341],
    'NFD' => ['s_9f2c', 'user', "Jose\u{0301}", '7a436b95850c54c4', 99140],
    'bucket 0' => ['s_edge', 'user', 'edge_39583', 'feb3d030fe02b0e0', 0],
    'bucket 1' => ['s_edge', 'user', 'edge_20598', 'b5924978038ba9e1', 1],
    'bucket 51615' => ['s_edge', 'user', 'edge_25328', '4edd27ed6b6388df', 51615],
    'bucket 51616' => ['s_edge', 'user', 'edge_417199', '29e465c24e13bae0', 51616],
    'bucket 99998' => ['s_edge', 'user', 'edge_37973', 'bbcee41b34a68f5e', 99998],
    'bucket 99999' => ['s_edge', 'user', 'edge_28533', 'e8872bc027301eff', 99999],
    'bucket 19999' => ['s_slot', 'user', 'slot_49913', 'f6dfdbfadbabb9bf', 19999],
    'bucket 20000' => ['s_slot', 'user', 'slot_54281', '7c126833e7433ca0', 20000],
    'bucket 49999' => ['s_slot', 'user', 'slot_483823', 'e1823fb0f5874d8f', 49999],
    'bucket 50000' => ['s_slot', 'user', 'slot_50277', '310465671e352df0', 50000],
    'a key of 1,024 bytes' => ['s_len', 'user', str_repeat('k', 1024), 'ed5d3c07d222e00d', 50413],
    'an emoji' => ['s_text', 'user', "\u{1F600}", '26ecd6bd9eadc732', 28242],
]);

it('hashes length-prefixed fields so that no split is ambiguous', function () {
    $splits = [['ab', 'c', 'd'], ['a', 'bc', 'd'], ['a', 'b', 'cd']];
    $inputs = array_map(static fn(array $s): string => BucketSha256V1::input(...$s), $splits);

    expect(array_unique($inputs))->toHaveCount(3)
        ->and(bin2hex(BucketSha256V1::input('s_9f2c', 'user', 'u_20931')))->toBe('00000011626974666c61672d6275636b65742d763100000006735f39663263000000047573657200000007755f3230393331');
});

it('treats NFC and NFD spellings as different subjects', function () {
    expect(BucketSha256V1::bucket('s_9f2c', 'user', "Jos\u{00E9}"))->not->toBe(BucketSha256V1::bucket('s_9f2c', 'user', "Jose\u{0301}"));
});

it('depends on the salt, the kind and the value only', function () {
    $base = BucketSha256V1::bucket('salt', 'user', 'u_1');

    expect(BucketSha256V1::bucket('salt', 'user', 'u_1'))->toBe($base)
        ->and(BucketSha256V1::bucket('salt2', 'user', 'u_1'))->not->toBe($base)
        ->and(BucketSha256V1::bucket('salt', 'organization', 'u_1'))->not->toBe($base)
        ->and(BucketSha256V1::bucket('salt', 'user', 'u_2'))->not->toBe($base);
});

it('always lands in range and spreads subjects evenly', function () {
    $counts = array_fill(0, 20, 0);
    for ($i = 0; $i < 100_000; $i++) {
        $bucket = BucketSha256V1::bucket('s_uniform', 'user', "user_$i");
        expect($bucket)->toBeGreaterThanOrEqual(0)->toBeLessThan(100_000);
        $counts[intdiv($bucket, 5000)]++;
    }

    // Chi-square over 20 equal intervals; 19 degrees of freedom, p = 0.001 is 43.8.
    $expected = 100_000 / 20;
    $chi = array_sum(array_map(static fn(int $c): float => (($c - $expected) ** 2) / $expected, $counts));
    expect($chi)->toBeLessThan(43.8);
});

it('selects slots by half-open intervals', function (int $bucket, ?string $variation, int $start, int $end) {
    $slots = [new Slot('a', 20000), new Slot('zero', 0), new Slot('b', 30000)];

    expect(SlotSelector::select($slots, $bucket))->toBe(['variation' => $variation, 'start' => $start, 'end' => $end]);
})->with([
    'first bucket' => [0, 'a', 0, 20000],
    'last bucket of the first slot' => [19999, 'a', 0, 20000],
    'first bucket of the next slot' => [20000, 'b', 20000, 50000],
    'a zero-weight slot covers nothing' => [20000, 'b', 20000, 50000],
    'last bucket of the second slot' => [49999, 'b', 20000, 50000],
    'first bucket of the remainder' => [50000, null, 50000, 100000],
    'last bucket' => [99999, null, 50000, 100000],
]);

it('keeps every subject in a slot that only grows', function () {
    $narrow = [new Slot('on', 20000)];
    $wide = [new Slot('on', 50000)];
    $moved = 0;

    for ($i = 0; $i < 10_000; $i++) {
        $bucket = BucketSha256V1::bucket('s_monotonic', 'user', "u_$i");
        $before = SlotSelector::select($narrow, $bucket)['variation'];
        $after = SlotSelector::select($wide, $bucket)['variation'];
        if ($before === 'on') {
            expect($after)->toBe('on');
        }
        if ($before === null && $after === 'on') {
            $moved++;
        }
    }

    expect($moved)->toBeGreaterThan(2500)->toBeLessThan(3500);
});

it('moves only subjects between the old and new boundary when a weight changes', function () {
    $old = [new Slot('a', 30000), new Slot('b', 30000)];
    $new = [new Slot('a', 40000), new Slot('b', 20000)];

    for ($i = 0; $i < 10_000; $i++) {
        $bucket = BucketSha256V1::bucket('s_boundary', 'user', "u_$i");
        $before = SlotSelector::select($old, $bucket)['variation'];
        $after = SlotSelector::select($new, $bucket)['variation'];
        if ($before !== $after) {
            expect($bucket)->toBeGreaterThanOrEqual(30000)->toBeLessThan(40000);
        }
    }
});
