<?php

declare(strict_types=1);

use BitFlag\Evaluation\ErrorKind;
use BitFlag\Protocol\Json\JsonDecoder;
use BitFlag\Protocol\MalformedSnapshot;
use BitFlag\Protocol\Model\FixedServe;
use BitFlag\Protocol\Model\FlagType;
use BitFlag\Protocol\Model\GroupKind;
use BitFlag\Protocol\Model\Operator;
use BitFlag\Protocol\Model\Projection;
use BitFlag\Protocol\Model\RolloutServe;
use BitFlag\Protocol\Problem;
use BitFlag\Protocol\SnapshotParser;
use BitFlag\Tests\Support\Snapshots;

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 */
function rejection(callable $mutate, bool $withChecksum = true): MalformedSnapshot
{
    $snapshot = $mutate(Snapshots::base());
    try {
        SnapshotParser::parse(Snapshots::json($snapshot, $withChecksum));
    } catch (MalformedSnapshot $e) {
        return $e;
    }
    throw new RuntimeException('Expected the snapshot to be rejected.');
}

/**
 * @return array<string, mixed>
 */
function ruleOn(string $id, array|object $match, array|object $serve = ["variation" => "on"]): array
{
    return ['id' => $id, 'match' => $match, 'serve' => $serve];
}

it('parses a snapshot into models', function () {
    $json = Snapshots::valid(function (array $s) {
        $s['flags']['checkout'] = Snapshots::flag([
            'requires' => ['rollouts.v1'],
            'prerequisites' => [['flagKey' => 'base', 'variations' => ['on']]],
            'overrides' => [['kind' => 'user', 'key' => 'u_vip', 'variation' => 'on', 'expiresAt' => '2026-10-06T09:00:00+01:00']],
            'rules' => [
                ruleOn('r1', ['all' => [['attribute' => 'country', 'op' => 'in', 'values' => ['PH', 'SG'], 'ignoreCase' => true], ['any' => [['op' => 'in_segment', 'values' => ['beta']]]]]]),
                ['id' => 'r2', 'enabled' => false, 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 20000]], 'remainderVariation' => 'off', 'allocationId' => 'alc_1', 'experimentId' => 'exp_1']]],
            ],
        ]);
        $s['flags']['base'] = Snapshots::flag();
        $s['segments'] = ['beta' => ['version' => 3, 'match' => ['all' => []], 'include' => [['kind' => 'user', 'key' => 'u_1']]]];

        return $s;
    });

    $snapshot = SnapshotParser::parse($json);
    $flag = $snapshot->flag('checkout');

    expect($snapshot->revision)->toBe(42)
        ->and($snapshot->generation)->toBe(1)
        ->and($snapshot->projection)->toBe(Projection::Server)
        ->and($snapshot->environment->key)->toBe('production')
        ->and($flag?->type)->toBe(FlagType::Boolean)
        ->and($flag?->variations)->toBe(['on' => true, 'off' => false])
        ->and($flag?->requires)->toBe(['rollouts.v1'])
        ->and($flag?->prerequisites[0]->flagKey)->toBe('base')
        ->and($flag?->overrides[0]->expiresAtMs)->toBe(1791273600000)
        ->and($flag?->rules[0]->match->kind)->toBe(GroupKind::All)
        ->and($flag?->rules[0]->match->members[0]->operator ?? null)->toBe(Operator::In)
        ->and($flag?->rules[0]->serve)->toBeInstanceOf(FixedServe::class)
        ->and($flag?->rules[1]->enabled)->toBeFalse()
        ->and($flag?->rules[1]->serve)->toBeInstanceOf(RolloutServe::class)
        ->and($snapshot->segment('beta')?->include[0]->key)->toBe('u_1')
        ->and($snapshot->segment('ghost'))->toBeNull();
});

it('accepts an empty snapshot', function () {
    $snapshot = SnapshotParser::parse(Snapshots::valid(function (array $s) {
        $s['flags'] = (object) [];

        return $s;
    }));

    expect($snapshot->flags)->toBe([]);
});

it('rejects a snapshot with the kind, path and problem', function (callable $mutate, string $path, string $problem, ErrorKind $kind = ErrorKind::MalformedSnapshot) {
    $e = rejection($mutate);

    expect($e->errorKind)->toBe($kind)->and($e->path)->toBe($path)->and($e->problem)->toBe($problem);
})->with([
    'protocol version 2' => [fn(array $s) => [...$s, 'protocolVersion' => 2], '/protocolVersion', Problem::UNSUPPORTED_VERSION],
    'protocol version as text' => [fn(array $s) => [...$s, 'protocolVersion' => '1'], '/protocolVersion', Problem::INVALID_TYPE],
    'unknown algorithm' => [fn(array $s) => [...$s, 'algorithmVersion' => 'bucket-sha256-v2'], '/algorithmVersion', Problem::UNSUPPORTED_ALGORITHM, ErrorKind::UnsupportedCapability],
    'missing member' => [function (array $s) {
        unset($s['generation']);
        return $s;
    }, '', Problem::MISSING_FIELD],
    'unknown member' => [fn(array $s) => [...$s, 'extra' => 1], '/extra', Problem::UNKNOWN_FIELD],
    'generation 0' => [fn(array $s) => [...$s, 'generation' => 0], '/generation', Problem::INVALID_VALUE],
    'revision as fraction' => [fn(array $s) => [...$s, 'revision' => 1.5], '/revision', Problem::INVALID_TYPE],
    'revision beyond the safe range' => [fn(array $s) => [...$s, 'revision' => 9007199254740993], '/revision', Problem::UNSAFE_NUMBER],
    'unknown projection' => [fn(array $s) => [...$s, 'projection' => 'both'], '/projection', Problem::INVALID_VALUE],
    'published at without time' => [fn(array $s) => [...$s, 'publishedAt' => '2026-10-06'], '/publishedAt', Problem::INVALID_VALUE],
    'published at on a day that does not exist' => [fn(array $s) => [...$s, 'publishedAt' => '2026-02-30T00:00:00Z'], '/publishedAt', Problem::INVALID_VALUE],
    'published at with an offset' => [fn(array $s) => [...$s, 'publishedAt' => '2026-10-06T08:00:00+01:00'], '/publishedAt', Problem::INVALID_VALUE],
    'environment id' => [fn(array $s) => [...$s, 'environment' => ['id' => 'x', 'key' => 'production']], '/environment/id', Problem::INVALID_VALUE],
    'repeated required capability' => [fn(array $s) => [...$s, 'requiredCapabilities' => ['segments.v1', 'segments.v1']], '/requiredCapabilities/1', Problem::INVALID_VALUE],
    'capability name' => [fn(array $s) => [...$s, 'requiredCapabilities' => ['Segments']], '/requiredCapabilities/0', Problem::INVALID_VALUE],
    'flag key' => [function (array $s) {
        $s['flags'] = ['Bad_Key' => Snapshots::flag()];
        return $s;
    }, '/flags/Bad_Key', Problem::INVALID_VALUE],
    'flag type' => [function (array $s) {
        $s['flags']['checkout']['type'] = 'float';
        return $s;
    }, '/flags/checkout/type', Problem::INVALID_VALUE],
    'flag enabled as text' => [function (array $s) {
        $s['flags']['checkout']['enabled'] = 'yes';
        return $s;
    }, '/flags/checkout/enabled', Problem::INVALID_TYPE],
    'flag member missing' => [function (array $s) {
        unset($s['flags']['checkout']['offVariation']);
        return $s;
    }, '/flags/checkout', Problem::MISSING_FIELD],
    'flag member unknown' => [function (array $s) {
        $s['flags']['checkout']['color'] = 'red';
        return $s;
    }, '/flags/checkout/color', Problem::UNKNOWN_FIELD],
    'variation of the wrong type' => [function (array $s) {
        $s['flags']['checkout']['variations']['on'] = 'yes';
        return $s;
    }, '/flags/checkout/variations/on', Problem::INVALID_TYPE],
    'variation key' => [function (array $s) {
        $s['flags']['checkout']['variations']['-x'] = true;
        return $s;
    }, '/flags/checkout/variations/-x', Problem::INVALID_VALUE],
    'no variations' => [function (array $s) {
        $s['flags']['checkout']['variations'] = (object) [];
        return $s;
    }, '/flags/checkout/variations', Problem::INVALID_VALUE],
    'json variation over 32 KB' => [function (array $s) {
        $s['flags']['checkout'] = Snapshots::flag(['type' => 'json', 'variations' => ['big' => ['t' => str_repeat('x', 33000)], 'off' => null]]);
        return $s;
    }, '/flags/checkout/variations/big', Problem::LIMIT_EXCEEDED],
    'number variation beyond the safe range' => [function (array $s) {
        $s['flags']['checkout'] = Snapshots::flag(['type' => 'number', 'variations' => ['big' => 1e300, 'off' => 0]]);
        return $s;
    }, '/flags/checkout/variations/big', Problem::UNSAFE_NUMBER],
    'too many prerequisites' => [function (array $s) {
        $s['flags']['checkout']['prerequisites'] = array_fill(0, 21, ['flagKey' => 'a', 'variations' => ['on']]);
        return $s;
    }, '/flags/checkout/prerequisites', Problem::LIMIT_EXCEEDED],
    'prerequisite without variations' => [function (array $s) {
        $s['flags']['checkout']['prerequisites'] = [['flagKey' => 'a', 'variations' => []]];
        return $s;
    }, '/flags/checkout/prerequisites/0/variations', Problem::INVALID_VALUE],
    'too many overrides' => [function (array $s) {
        $s['flags']['checkout']['overrides'] = array_map(fn(int $i) => ['kind' => 'user', 'key' => "u_$i", 'variation' => 'on', 'expiresAt' => null], range(1, 501));
        return $s;
    }, '/flags/checkout/overrides', Problem::LIMIT_EXCEEDED],
    'duplicate override' => [function (array $s) {
        $o = ['kind' => 'user', 'key' => 'u_1', 'variation' => 'on', 'expiresAt' => null];
        $s['flags']['checkout']['overrides'] = [$o, $o];
        return $s;
    }, '/flags/checkout/overrides/1', Problem::DUPLICATE_OVERRIDE],
    'override key over 256 bytes' => [function (array $s) {
        $s['flags']['checkout']['overrides'] = [['kind' => 'user', 'key' => str_repeat('é', 129), 'variation' => 'on', 'expiresAt' => null]];
        return $s;
    }, '/flags/checkout/overrides/0/key', Problem::INVALID_VALUE],
    'override expiry' => [function (array $s) {
        $s['flags']['checkout']['overrides'] = [['kind' => 'user', 'key' => 'u', 'variation' => 'on', 'expiresAt' => 'tomorrow']];
        return $s;
    }, '/flags/checkout/overrides/0/expiresAt', Problem::INVALID_VALUE],
    'too many rules' => [function (array $s) {
        $s['flags']['checkout']['rules'] = array_map(fn(int $i) => ruleOn("r$i", ['all' => []]), range(1, 201));
        return $s;
    }, '/flags/checkout/rules', Problem::LIMIT_EXCEEDED],
    'rule id over 64 characters' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn(str_repeat('r', 65), ['all' => []])];
        return $s;
    }, '/flags/checkout/rules/0/id', Problem::INVALID_VALUE],
    'group with both all and any' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [], 'any' => []])];
        return $s;
    }, '/flags/checkout/rules/0/match', Problem::INVALID_VALUE],
    'group with neither' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', (object) [])];
        return $s;
    }, '/flags/checkout/rules/0/match', Problem::MISSING_FIELD],
    'group nested six deep' => [function (array $s) {
        $g = ['all' => []];
        for ($i = 1; $i < 6; $i++) {
            $g = ['all' => [$g]];
        } $s['flags']['checkout']['rules'] = [ruleOn('r1', $g)];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/all/0/all/0/all/0/all/0', Problem::LIMIT_EXCEEDED],
    'group with 51 members' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => array_fill(0, 51, ['attribute' => 'a', 'op' => 'exists'])])];
        return $s;
    }, '/flags/checkout/rules/0/match/all', Problem::LIMIT_EXCEEDED],
    'unknown operator' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'like', 'values' => ['x']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/op', Problem::INVALID_VALUE],
    'condition without attribute' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['op' => 'eq', 'values' => ['x']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0', Problem::MISSING_FIELD],
    'condition without values' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'eq']]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0', Problem::MISSING_FIELD],
    'exists with values' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'exists', 'values' => [1]]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values', Problem::INVALID_VALUE],
    'segment condition with an attribute' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'in_segment', 'values' => ['x']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/attribute', Problem::INVALID_VALUE],
    'eq with two values' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'eq', 'values' => ['x', 'y']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values', Problem::INVALID_VALUE],
    'too many values' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'in', 'values' => array_map(strval(...), range(1, 1001))]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values', Problem::LIMIT_EXCEEDED],
    'mixed in values' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'in', 'values' => ['x', 1]]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values/1', Problem::INVALID_TYPE],
    'numeric operator with text' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'a', 'op' => 'gt', 'values' => ['5']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values/0', Problem::INVALID_TYPE],
    'invalid SemVer literal' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'v', 'op' => 'semver_gte', 'values' => ['4.2']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values/0', Problem::INVALID_VALUE],
    'invalid date literal' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => [['attribute' => 'v', 'op' => 'before', 'values' => ['2026-10-06']]]])];
        return $s;
    }, '/flags/checkout/rules/0/match/all/0/values/0', Problem::INVALID_VALUE],
    'serve with both' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['variation' => 'on', 'rollout' => []])];
        return $s;
    }, '/flags/checkout/rules/0/serve', Problem::INVALID_VALUE],
    'rollout weights over 100000' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 60000], ['variation' => 'off', 'weight' => 50000]]]])];
        return $s;
    }, '/flags/checkout/rules/0/serve/rollout/slots', Problem::WEIGHT_TOTAL_EXCEEDED],
    'rollout weight 100001' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 100001]]]])];
        return $s;
    }, '/flags/checkout/rules/0/serve/rollout/slots/0/weight', Problem::INVALID_VALUE],
    'rollout weight as fraction' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 50000.5]], 'remainderVariation' => 'off']])];
        return $s;
    }, '/flags/checkout/rules/0/serve/rollout/slots/0/weight', Problem::INVALID_TYPE],
    'rollout without remainder' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 20000]]]])];
        return $s;
    }, '/flags/checkout/rules/0/serve/rollout', Problem::MISSING_REMAINDER],
    'rollout salt over 128 bytes' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['rollout' => ['bucketBy' => 'key', 'salt' => str_repeat('é', 65), 'slots' => [['variation' => 'on', 'weight' => 100000]]]])];
        return $s;
    }, '/flags/checkout/rules/0/serve/rollout/salt', Problem::INVALID_VALUE],
    'allocation without experiment' => [function (array $s) {
        $s['flags']['checkout']['rules'] = [ruleOn('r1', ['all' => []], ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 100000]], 'allocationId' => 'a']])];
        return $s;
    }, '/flags/checkout/rules/0/serve/rollout', Problem::MISSING_FIELD],
    'segment version 0' => [function (array $s) {
        $s['segments'] = ['s' => ['version' => 0, 'match' => ['all' => []]]];
        return $s;
    }, '/segments/s/version', Problem::INVALID_VALUE],
    'segment key' => [function (array $s) {
        $s['segments'] = ['Bad' => ['version' => 1, 'match' => ['all' => []]]];
        return $s;
    }, '/segments/Bad', Problem::INVALID_VALUE],
    'public projection with overrides' => [function (array $s) {
        $s['projection'] = 'public';
        $s['flags']['checkout']['overrides'] = [['kind' => 'user', 'key' => 'u', 'variation' => 'on', 'expiresAt' => null]];
        return $s;
    }, '/flags/checkout/overrides', Problem::PROJECTION_VIOLATION],
    'public projection with an include list' => [function (array $s) {
        $s['projection'] = 'public';
        $s['segments'] = ['s' => ['version' => 1, 'match' => ['all' => []], 'include' => []]];
        return $s;
    }, '/segments/s/include', Problem::PROJECTION_VIOLATION],
    'revision 0 with flags' => [fn(array $s) => [...$s, 'revision' => 0], '/flags', Problem::EMPTY_SNAPSHOT_REQUIRED],
]);

it('rejects a checksum that does not match, after the structure was accepted', function () {
    $e = rejection(fn(array $s) => [...$s, 'checksum' => 'sha256:' . str_repeat('f', 64)], withChecksum: false);

    expect($e->problem)->toBe(Problem::CHECKSUM_MISMATCH)->and($e->path)->toBe('/checksum');
});

it('rejects a checksum of the wrong shape', function () {
    $e = rejection(fn(array $s) => [...$s, 'checksum' => 'sha256:ABC'], withChecksum: false);

    expect($e->problem)->toBe(Problem::INVALID_VALUE)->and($e->path)->toBe('/checksum');
});

it('can skip the checksum check', function () {
    $json = Snapshots::json(Snapshots::base(), withChecksum: false);

    expect(SnapshotParser::parse($json, verifyChecksum: false)->revision)->toBe(42);
});

it('rejects the top level when it is not an object', function (string $json) {
    try {
        SnapshotParser::parse($json);
    } catch (MalformedSnapshot $e) {
        expect($e->path)->toBe('')->and($e->problem)->toBeIn([Problem::INVALID_TYPE, Problem::INVALID_JSON]);

        return;
    }
    throw new RuntimeException('Expected rejection.');
})->with(['[]', '"text"', '5', 'null', '{']);

it('accepts the limits exactly', function () {
    $json = Snapshots::valid(function (array $s) {
        $s['flags']['checkout']['rules'] = array_map(fn(int $i) => ruleOn("r$i", ['all' => [['attribute' => 'a', 'op' => 'in', 'values' => array_map(strval(...), range(1, 1000))]]]), range(1, 200));
        $s['flags']['checkout']['overrides'] = array_map(fn(int $i) => ['kind' => 'user', 'key' => str_repeat('é', 128), 'variation' => 'on', 'expiresAt' => null] + ['n' => $i], range(1, 1));
        unset($s['flags']['checkout']['overrides'][0]['n']);
        $g = ['all' => []];
        for ($i = 1; $i < 5; $i++) {
            $g = ['all' => [$g]];
        }
        $s['flags']['other'] = Snapshots::flag(['rules' => [ruleOn('r1', $g)]]);

        return $s;
    });

    expect(count(SnapshotParser::parse($json)->flag('checkout')?->rules ?? []))->toBe(200);
});

it('treats an integer written with a fraction part as an integer', function () {
    $json = Snapshots::json([...Snapshots::base(), 'revision' => 42.0]);

    expect(SnapshotParser::parse($json)->revision)->toBe(42);
});

it('reports JSON Pointer escapes in paths', function () {
    $e = rejection(function (array $s) {
        $s['flags']['checkout']['variations'] = ['a~b' => true, 'a/b' => true];

        return $s;
    });

    expect($e->path)->toBe('/flags/checkout/variations/a~0b');
});

it('decodes snapshots that nest json values deeply', function () {
    $value = 0;
    for ($i = 0; $i < 60; $i++) {
        $value = [$value];
    }
    $json = Snapshots::valid(function (array $s) use ($value) {
        $s['flags']['checkout'] = Snapshots::flag(['type' => 'json', 'variations' => ['v' => $value, 'off' => null]]);

        return $s;
    });

    expect(SnapshotParser::parse($json)->flag('checkout')?->type)->toBe(FlagType::Json)
        ->and(JsonDecoder::decode($json))->toBeInstanceOf(stdClass::class);
});
