<?php

declare(strict_types=1);

use BitFlag\Protocol\Checksum;
use BitFlag\Protocol\Json\JsonDecoder;
use BitFlag\Protocol\MalformedSnapshot;
use BitFlag\Protocol\Problem;

function snapshotObject(string $json): stdClass
{
    $value = JsonDecoder::decode($json);
    assert($value instanceof stdClass);

    return $value;
}

it('is sha256: and the hash of the canonical form without the checksum member', function () {
    $object = snapshotObject('{"b":1,"a":[true],"checksum":"sha256:ignored"}');

    expect(Checksum::compute($object))->toBe('sha256:' . hash('sha256', '{"a":[true],"b":1}'));
});

it('does not depend on member order, whitespace or the checksum itself', function () {
    $a = snapshotObject('{"a":1,"b":{"c":2,"d":3}}');
    $b = snapshotObject(" {\n \"b\" : {\"d\":3, \"c\":2}, \"a\":1.0, \"checksum\":\"x\" }");

    expect(Checksum::compute($a))->toBe(Checksum::compute($b));
});

it('changes when any other member changes', function () {
    expect(Checksum::compute(snapshotObject('{"revision":42}')))->not->toBe(Checksum::compute(snapshotObject('{"revision":43}')));
});

it('keeps a checksum member that is not at the top level', function () {
    expect(Checksum::compute(snapshotObject('{"a":{"checksum":"x"}}')))->not->toBe(Checksum::compute(snapshotObject('{"a":{}}')));
});

it('verifies a matching checksum', function () {
    $object = snapshotObject('{"a":1}');
    $object->checksum = Checksum::compute($object);

    Checksum::verify($object);

    expect($object->checksum)->toStartWith('sha256:');
});

it('rejects a mismatching or missing checksum', function (string $json) {
    try {
        Checksum::verify(snapshotObject($json));
    } catch (MalformedSnapshot $e) {
        expect($e->problem)->toBe(Problem::CHECKSUM_MISMATCH)->and($e->path)->toBe('/checksum');

        return;
    }
    throw new RuntimeException('Expected a mismatch.');
})->with([
    'wrong' => ['{"a":1,"checksum":"sha256:' . '0000000000000000000000000000000000000000000000000000000000000000' . '"}'],
    'missing' => ['{"a":1}'],
    'not a string' => ['{"a":1,"checksum":5}'],
]);
