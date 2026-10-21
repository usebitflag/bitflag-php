<?php

declare(strict_types=1);

use BitFlag\Protocol\Json\JsonDecoder;
use BitFlag\Protocol\MalformedSnapshot;
use BitFlag\Protocol\Problem;

function decodeFails(string $json, string $problem, int $maxBytes = JsonDecoder::MAX_BYTES): void
{
    try {
        JsonDecoder::decode($json, $maxBytes);
    } catch (MalformedSnapshot $e) {
        expect($e->problem)->toBe($problem)->and($e->path)->toBe('');

        return;
    }
    throw new RuntimeException('Expected the JSON to be rejected.');
}

it('decodes objects as stdClass so an empty object differs from an empty list', function () {
    $value = JsonDecoder::decode('{"a":{},"b":[]}');

    expect($value)->toBeInstanceOf(stdClass::class)
        ->and($value->a)->toBeInstanceOf(stdClass::class)
        ->and($value->b)->toBe([]);
});

it('keeps the last of duplicate members', function () {
    $value = JsonDecoder::decode('{"a":1,"a":2}');

    expect($value->a)->toBe(2);
});

it('keeps numeric-looking member names as strings', function () {
    $value = JsonDecoder::decode('{"1":"one","01":"zero-one"}');

    expect(array_map(strval(...), array_keys(get_object_vars($value))))->toBe(['1', '01']);
});

it('rejects text that is not JSON', function (string $json) {
    decodeFails($json, Problem::INVALID_JSON);
})->with(['', '{', '{"a":1,}', "{'a':1}", 'NaN', '[1,]', "\"\xB1\"", '{"a":1} x']);

it('accepts nesting up to 64 levels and rejects 65', function () {
    $nest = static fn(int $levels): string => str_repeat('[', $levels) . str_repeat(']', $levels);

    expect(JsonDecoder::decode($nest(64)))->toBeArray();
    decodeFails($nest(65), Problem::TOO_DEEP);
});

it('rejects text over the size limit', function () {
    decodeFails('"' . str_repeat('a', 20) . '"', Problem::TOO_LARGE, maxBytes: 10);
});

it('knows which numbers are outside the safe integer range', function (int|float $number, bool $unsafe) {
    expect(JsonDecoder::isUnsafeNumber($number))->toBe($unsafe);
})->with([
    'zero' => [0, false],
    'infinity' => [INF, true],
    'negative infinity' => [-INF, true],
    'max safe' => [9007199254740991, false],
    'min safe' => [-9007199254740991, false],
    'one over' => [9007199254740992, true],
    'one under' => [-9007199254740992, true],
    'beyond int64 reads as float' => [9.2233720368547758e18, true],
    'huge float' => [1e300, true],
    'safe float with fraction' => [0.5, false],
    'integral float at the limit' => [9007199254740991.0, false],
    'large float above the limit' => [9007199254740994.0, true],
    'negative zero' => [-0.0, false],
]);
