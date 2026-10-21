<?php

declare(strict_types=1);

use BitFlag\Evaluation\Context;
use BitFlag\Protocol\Json\JsonDecoder;

function decodedContext(string $json): Context
{
    return Context::fromDecoded(JsonDecoder::decode($json));
}

it('accepts a minimal and a full context', function () {
    expect(Context::fromArray(['kind' => 'user'])->isValid())->toBeTrue()
        ->and(Context::fromArray(['kind' => 'user', 'key' => 'u_1', 'attributes' => ['plan' => 'pro', 'n' => 1.5, 'b' => false, 'z' => null, 'tags' => ['a', 'b']]])->isValid())->toBeTrue()
        ->and((new Context('organization', 'o_1'))->isValid())->toBeTrue();
});

it('exposes kind, key and attributes', function () {
    $context = Context::fromArray(['kind' => 'user', 'key' => 'u_1', 'attributes' => ['plan' => 'pro']]);

    expect($context->kind)->toBe('user')->and($context->key)->toBe('u_1')->and($context->attributes)->toBe(['plan' => 'pro']);
});

it('rejects contexts that break the rules', function (string $json) {
    $context = decodedContext($json);

    expect($context->isValid())->toBeFalse()->and($context->validate())->not->toBeEmpty();
})->with([
    'no kind' => '{"key":"u_1"}',
    'empty object' => '{}',
    'kind is null' => '{"kind":null}',
    'kind is a number' => '{"kind":1}',
    'kind in upper case' => '{"kind":"User"}',
    'kind starts with a digit' => '{"kind":"1user"}',
    'empty kind' => '{"kind":""}',
    'kind of 65 characters' => '{"kind":"' . str_repeat('a', 65) . '"}',
    'type instead of kind' => '{"type":"user","key":"u_1"}',
    'unknown member' => '{"kind":"user","email":"x"}',
    'numeric key' => '{"kind":"user","key":20931}',
    'boolean key' => '{"kind":"user","key":true}',
    'null key' => '{"kind":"user","key":null}',
    'empty key' => '{"kind":"user","key":""}',
    'key of 257 bytes' => '{"kind":"user","key":"' . str_repeat('k', 257) . '"}',
    'key of 258 bytes in 129 characters' => '{"kind":"user","key":"' . str_repeat('é', 129) . '"}',
    'attributes as a list' => '{"kind":"user","attributes":[]}',
    'attributes as text' => '{"kind":"user","attributes":"a"}',
    'attributes null' => '{"kind":"user","attributes":null}',
    'attribute named key' => '{"kind":"user","attributes":{"key":"x"}}',
    'attribute named kind' => '{"kind":"user","attributes":{"kind":"x"}}',
    'empty attribute name' => '{"kind":"user","attributes":{"":1}}',
    'attribute name of 129 bytes' => '{"kind":"user","attributes":{"' . str_repeat('n', 129) . '":1}}',
    'attribute name of 130 bytes in 65 characters' => '{"kind":"user","attributes":{"' . str_repeat('é', 65) . '":1}}',
    'object value' => '{"kind":"user","attributes":{"v":{"a":1}}}',
    'empty object value' => '{"kind":"user","attributes":{"v":{}}}',
    'mixed array' => '{"kind":"user","attributes":{"v":["a",1]}}',
    'number and boolean array' => '{"kind":"user","attributes":{"v":[1,true]}}',
    'array with null' => '{"kind":"user","attributes":{"v":["a",null]}}',
    'nested array' => '{"kind":"user","attributes":{"v":[["a"]]}}',
    'array of objects' => '{"kind":"user","attributes":{"v":[{"a":1}]}}',
    'string of 1025 bytes' => '{"kind":"user","attributes":{"v":"' . str_repeat('s', 1025) . '"}}',
    'string of 1026 bytes in 513 characters' => '{"kind":"user","attributes":{"v":"' . str_repeat('é', 513) . '"}}',
    'array of 101 items' => '{"kind":"user","attributes":{"v":[' . implode(',', array_fill(0, 101, '1')) . ']}}',
    'array item over 1024 bytes' => '{"kind":"user","attributes":{"v":["' . str_repeat('s', 1025) . '"]}}',
    'not an object: null' => 'null',
    'not an object: text' => '"u_1"',
    'not an object: list' => '[{"kind":"user"}]',
    'not an object: number' => '7',
]);

it('accepts contexts at the limits', function (string $json) {
    expect(decodedContext($json)->isValid())->toBeTrue();
})->with([
    'kind of 64 characters' => '{"kind":"' . str_repeat('a', 64) . '"}',
    'key of 256 bytes' => '{"kind":"user","key":"' . str_repeat('k', 256) . '"}',
    'key of 128 two-byte characters' => '{"kind":"user","key":"' . str_repeat('é', 128) . '"}',
    'key of 64 four-byte characters' => '{"kind":"user","key":"' . str_repeat('😀', 64) . '"}',
    'attribute name of 128 bytes' => '{"kind":"user","attributes":{"' . str_repeat('n', 128) . '":1}}',
    'string of 1024 bytes' => '{"kind":"user","attributes":{"v":"' . str_repeat('s', 1024) . '"}}',
    'array of 100 items' => '{"kind":"user","attributes":{"v":[' . implode(',', array_fill(0, 100, '"a"')) . ']}}',
    'empty array value' => '{"kind":"user","attributes":{"v":[]}}',
    'integers beyond the safe range are valid values' => '{"kind":"user","attributes":{"v":9007199254740993}}',
    'Key and Kind are ordinary names' => '{"kind":"user","attributes":{"Key":"a","Kind":"b"}}',
]);

it('accepts 100 attributes and rejects 101', function () {
    $attributes = static fn(int $count): array => array_combine(array_map(static fn(int $i): string => "a$i", range(1, $count)), range(1, $count));

    expect(Context::fromArray(['kind' => 'user', 'attributes' => $attributes(100)])->isValid())->toBeTrue()
        ->and(Context::fromArray(['kind' => 'user', 'attributes' => $attributes(101)])->isValid())->toBeFalse();
});

it('treats an empty PHP array as an empty object but a decoded empty list as invalid', function () {
    expect(Context::fromArray(['kind' => 'user', 'attributes' => []])->isValid())->toBeTrue()
        ->and(decodedContext('{"kind":"user","attributes":[]}')->isValid())->toBeFalse();
});

it('refuses non-finite numbers and objects built in PHP', function () {
    expect(Context::fromArray(['kind' => 'user', 'attributes' => ['v' => NAN]])->isValid())->toBeFalse()
        ->and(Context::fromArray(['kind' => 'user', 'attributes' => ['v' => INF]])->isValid())->toBeFalse()
        ->and(Context::fromArray(['kind' => 'user', 'attributes' => ['v' => ['a' => 1]]])->isValid())->toBeFalse()
        ->and(Context::fromArray(['kind' => 'user', 'attributes' => ['v' => new stdClass()]])->isValid())->toBeFalse();
});

it('keeps numeric-looking attribute names as names', function () {
    $context = decodedContext('{"kind":"user","attributes":{"1":"a","01":"b"}}');

    expect($context->isValid())->toBeTrue()->and(array_map(strval(...), array_keys($context->attributes)))->toBe(['1', '01']);
});
