<?php

declare(strict_types=1);

use BitFlag\Evaluation\Bucketing\BucketSha256V1;
use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\EvaluationOptions;
use BitFlag\Evaluation\Evaluator;
use BitFlag\Evaluation\Exception\FallbackTypeError;
use BitFlag\Evaluation\FixedClock;
use BitFlag\Evaluation\Reason;
use BitFlag\Protocol\Model\FlagType;
use BitFlag\Protocol\SnapshotParser;
use BitFlag\Tests\Support\Snapshots;

/**
 * @param  array<string, array<string, mixed>>  $flags
 * @param  array<string, mixed>  $segments
 * @param  array<string, mixed>  $extra
 */
function evaluatorFor(array $flags, array $segments = [], array $extra = [], ?DateTimeImmutable $now = null): Evaluator
{
    $json = Snapshots::valid(function (array $s) use ($flags, $segments, $extra) {
        $s['flags'] = $flags === [] ? (object) [] : $flags;
        $s['segments'] = $segments === [] ? (object) [] : $segments;

        return [...$s, ...$extra];
    });

    return new Evaluator(SnapshotParser::parse($json), new FixedClock($now ?? new DateTimeImmutable('2026-10-06T08:00:00Z')));
}

function user(?string $key = 'u_1', array $attributes = [], string $kind = 'user'): Context
{
    return new Context($kind, $key, $attributes);
}

/**
 * @return array<string, mixed>
 */
function matchAll(string $variation = 'on', string $id = 'r1'): array
{
    return ['id' => $id, 'match' => ['all' => []], 'serve' => ['variation' => $variation]];
}

/**
 * @return array<string, mixed>
 */
function when(string $attribute, mixed $value, string $variation = 'on', string $id = 'r1'): array
{
    return ['id' => $id, 'match' => ['all' => [['attribute' => $attribute, 'op' => 'eq', 'values' => [$value]]]], 'serve' => ['variation' => $variation]];
}

/**
 * @return array<string, mixed>
 */
function rolloutRule(string $salt, int $weight, string $id = 'r1', array $extra = []): array
{
    return ['id' => $id, 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => $salt, 'slots' => [['variation' => 'on', 'weight' => $weight]], 'remainderVariation' => 'off', ...$extra]]];
}

function keyInBucket(string $salt, int $low, int $high): string
{
    for ($i = 0;; $i++) {
        $bucket = BucketSha256V1::bucket($salt, 'user', "k_$i");
        if ($bucket >= $low && $bucket < $high) {
            return "k_$i";
        }
    }
}

it('serves the default variation when no rule matches', function () {
    $result = evaluatorFor(['f' => Snapshots::flag(['defaultVariation' => 'on'])])->evaluateBoolean('f', user(), false);

    expect($result->value)->toBeTrue()->and($result->variationKey)->toBe('on')->and($result->reason)->toBe(Reason::Default)
        ->and($result->revision)->toBe(42)->and($result->generation)->toBe(1)->and($result->evaluatedAt)->toBe('2026-10-06T08:00:00Z');
});

it('serves the off variation of a disabled flag whatever else is configured', function () {
    $flag = Snapshots::flag(['enabled' => false, 'rules' => [matchAll()], 'overrides' => [['kind' => 'user', 'key' => 'u_1', 'variation' => 'on', 'expiresAt' => null]]]);
    $result = evaluatorFor(['f' => $flag])->evaluateBoolean('f', user(), true);

    expect($result->reason)->toBe(Reason::Off)->and($result->variationKey)->toBe('off')->and($result->value)->toBeFalse();
});

it('decides in order: override, then the first matching enabled rule, then the default', function () {
    $flag = Snapshots::flag([
        'overrides' => [['kind' => 'user', 'key' => 'u_vip', 'variation' => 'on', 'expiresAt' => null]],
        'rules' => [['id' => 'off-rule', 'enabled' => false] + matchAll('on', 'x'), when('plan', 'pro', 'on', 'pro'), when('plan', 'pro', 'off', 'shadowed')],
    ]);
    $evaluator = evaluatorFor(['f' => $flag]);

    expect($evaluator->evaluateBoolean('f', user('u_vip', ['plan' => 'pro']), false)->reason)->toBe(Reason::Override)
        ->and($evaluator->evaluateBoolean('f', user('u_x', ['plan' => 'pro']), false)->ruleId)->toBe('pro')
        ->and($evaluator->evaluateBoolean('f', user('u_x', ['plan' => 'free']), true)->reason)->toBe(Reason::Default);
});

it('matches overrides on kind and key exactly and not for a context without a key', function () {
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['overrides' => [['kind' => 'user', 'key' => 'u_vip', 'variation' => 'on', 'expiresAt' => null]]])]);

    expect($evaluator->evaluateBoolean('f', user('u_vip'), false)->reason)->toBe(Reason::Override)
        ->and($evaluator->evaluateBoolean('f', user('U_VIP'), false)->reason)->toBe(Reason::Default)
        ->and($evaluator->evaluateBoolean('f', user('u_vip', kind: 'organization'), false)->reason)->toBe(Reason::Default)
        ->and($evaluator->evaluateBoolean('f', user(null), false)->reason)->toBe(Reason::Default);
});

it('expires overrides at the instant, strictly', function (string $at, Reason $expected) {
    $flag = Snapshots::flag(['overrides' => [['kind' => 'user', 'key' => 'u_1', 'variation' => 'on', 'expiresAt' => '2026-10-06T08:00:00Z']]]);
    $evaluator = evaluatorFor(['f' => $flag]);

    $result = $evaluator->evaluateBoolean('f', user(), false, EvaluationOptions::defaults()->at(new DateTimeImmutable($at)));

    expect($result->reason)->toBe($expected);
})->with([
    'a millisecond before' => ['2026-10-06T07:59:59.999Z', Reason::Override],
    'at the instant' => ['2026-10-06T08:00:00Z', Reason::Default],
    'a millisecond after' => ['2026-10-06T08:00:00.001Z', Reason::Default],
]);

it('uses the clock when the options give no time and reports the instant it used', function () {
    $flag = Snapshots::flag(['overrides' => [['kind' => 'user', 'key' => 'u_1', 'variation' => 'on', 'expiresAt' => '2026-10-06T09:00:00Z']]]);

    $before = evaluatorFor(['f' => $flag], now: new DateTimeImmutable('2026-10-06T08:59:59.5Z'))->evaluateBoolean('f', user(), false);
    $after = evaluatorFor(['f' => $flag], now: new DateTimeImmutable('2026-10-06T09:00:00Z'))->evaluateBoolean('f', user(), false);

    expect($before->reason)->toBe(Reason::Override)->and($before->evaluatedAt)->toBe('2026-10-06T08:59:59.500Z')
        ->and($after->reason)->toBe(Reason::Default);
});

it('returns the caller fallback for an unknown flag or the wrong type', function () {
    $evaluator = evaluatorFor(['f' => Snapshots::flag()]);

    $missing = $evaluator->evaluateString('nope', user(), 'x');
    $mismatch = $evaluator->evaluateString('f', user(), 'x');

    expect($missing->value)->toBe('x')->and($missing->variationKey)->toBeNull()->and($missing->reason)->toBe(Reason::Fallback)->and($missing->errorKind)->toBe(ErrorKind::FlagNotFound)
        ->and($mismatch->value)->toBe('x')->and($mismatch->errorKind)->toBe(ErrorKind::TypeMismatch)
        ->and($mismatch->revision)->toBe(42);
});

it('returns the fallback with INVALID_CONTEXT for an invalid context, before the flag is looked up', function () {
    $evaluator = evaluatorFor(['f' => Snapshots::flag()]);

    $result = $evaluator->evaluateBoolean('missing', Context::fromArray(['key' => 'u_1']), true);

    expect($result->reason)->toBe(Reason::Error)->and($result->errorKind)->toBe(ErrorKind::InvalidContext)->and($result->value)->toBeTrue();
});

it('throws only for a fallback of the wrong type, before anything else', function (FlagType $type, mixed $fallback) {
    $evaluator = evaluatorFor(['f' => Snapshots::flag()]);

    expect(fn() => $evaluator->evaluate('missing', Context::fromArray([]), $type, $fallback))->toThrow(FallbackTypeError::class);
})->with([
    'string for boolean' => [FlagType::Boolean, 'yes'],
    'int for boolean' => [FlagType::Boolean, 1],
    'null for string' => [FlagType::String, null],
    'number for string' => [FlagType::String, 5],
    'text for number' => [FlagType::Number, '5'],
    'NaN for number' => [FlagType::Number, NAN],
    'infinity for number' => [FlagType::Number, INF],
    'object for json' => [FlagType::Json, new DateTimeImmutable()],
    'NaN inside json' => [FlagType::Json, [NAN]],
]);

it('accepts fallbacks of every type', function () {
    $evaluator = evaluatorFor([]);

    expect($evaluator->evaluateNumber('x', user(), 5)->value)->toBe(5)
        ->and($evaluator->evaluateNumber('x', user(), 5.5)->value)->toBe(5.5)
        ->and($evaluator->evaluateJson('x', user(), ['a' => [1, null]])->value)->toBe(['a' => [1, null]])
        ->and($evaluator->evaluateJson('x', user(), null)->value)->toBeNull()
        ->and($evaluator->evaluateJson('x', user(), (object) ['a' => 1])->value)->toEqual((object) ['a' => 1]);
});

it('serves string, number and json values', function () {
    $evaluator = evaluatorFor([
        's' => Snapshots::flag(['type' => 'string', 'variations' => ['hello' => 'Hello', 'off' => ''], 'defaultVariation' => 'hello']),
        'n' => Snapshots::flag(['type' => 'number', 'variations' => ['ten' => 10, 'off' => 0], 'defaultVariation' => 'ten']),
        'j' => Snapshots::flag(['type' => 'json', 'variations' => ['cfg' => ['a' => [1, 2]], 'off' => null], 'defaultVariation' => 'cfg']),
    ]);

    expect($evaluator->evaluateString('s', user(), '')->value)->toBe('Hello')
        ->and($evaluator->evaluateNumber('n', user(), 0)->value)->toBe(10)
        ->and($evaluator->evaluateJson('j', user(), null)->value)->toEqual((object) ['a' => [1, 2]]);
});

it('treats a dangling variation as a configuration error only when it is served', function () {
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['defaultVariation' => 'ghost', 'rules' => [when('plan', 'pro')]])]);

    expect($evaluator->evaluateBoolean('f', user(attributes: ['plan' => 'pro']), false)->reason)->toBe(Reason::Rule)
        ->and($evaluator->evaluateBoolean('f', user(attributes: ['plan' => 'free']), true)->errorKind)->toBe(ErrorKind::InvalidConfig);
});

it('decides segment membership: exclude, then include, then conditions', function () {
    $segment = ['version' => 1, 'match' => ['all' => [['attribute' => 'country', 'op' => 'eq', 'values' => ['PH']]]], 'include' => [['kind' => 'user', 'key' => 'u_in'], ['kind' => 'user', 'key' => 'u_both']], 'exclude' => [['kind' => 'user', 'key' => 'u_out'], ['kind' => 'user', 'key' => 'u_both']]];
    $rule = ['id' => 'r1', 'match' => ['all' => [['op' => 'in_segment', 'values' => ['ph']]]], 'serve' => ['variation' => 'on']];
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['rules' => [$rule]])], ['ph' => $segment]);
    $isOn = static fn(Context $c): bool => $evaluator->evaluateBoolean('f', $c, false)->value === true;

    expect($isOn(user('u_in', ['country' => 'US'])))->toBeTrue()
        ->and($isOn(user('u_x', ['country' => 'PH'])))->toBeTrue()
        ->and($isOn(user('u_x', ['country' => 'US'])))->toBeFalse()
        ->and($isOn(user('u_out', ['country' => 'PH'])))->toBeFalse()
        ->and($isOn(user('u_both', ['country' => 'PH'])))->toBeFalse()
        ->and($isOn(user(null, ['country' => 'PH'])))->toBeTrue()
        ->and($isOn(user('u_in', ['country' => 'US'], 'organization')))->toBeFalse();
});

it('reports a missing segment as a configuration error when it is evaluated', function () {
    $rule = ['id' => 'r1', 'match' => ['all' => [['op' => 'in_segment', 'values' => ['ghost']]]], 'serve' => ['variation' => 'on']];
    $result = evaluatorFor(['f' => Snapshots::flag(['rules' => [$rule]])])->evaluateBoolean('f', user(), true);

    expect($result->errorKind)->toBe(ErrorKind::InvalidConfig)->and($result->reason)->toBe(Reason::Error)->and($result->value)->toBeTrue();
});

it('stops a segment that refers back to itself', function () {
    $segments = [
        'a' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['b']]]]],
        'b' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['a']]]]],
    ];
    $rule = ['id' => 'r1', 'match' => ['all' => [['op' => 'in_segment', 'values' => ['a']]]], 'serve' => ['variation' => 'on']];

    expect(evaluatorFor(['f' => Snapshots::flag(['rules' => [$rule]])], $segments)->evaluateBoolean('f', user(), false)->errorKind)->toBe(ErrorKind::InvalidConfig);
});

it('allows a segment to use another segment', function () {
    $segments = [
        'outer' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['inner']]]]],
        'inner' => ['version' => 1, 'match' => ['all' => [['attribute' => 'plan', 'op' => 'eq', 'values' => ['pro']]]]],
    ];
    $rule = ['id' => 'r1', 'match' => ['all' => [['op' => 'in_segment', 'values' => ['outer']]]], 'serve' => ['variation' => 'on']];
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['rules' => [$rule]])], $segments);

    expect($evaluator->evaluateBoolean('f', user(attributes: ['plan' => 'pro']), false)->value)->toBeTrue()
        ->and($evaluator->evaluateBoolean('f', user(attributes: ['plan' => 'free']), true)->value)->toBeFalse();
});

it('rolls out by bucket and reports the bucket only on request', function () {
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['rules' => [rolloutRule('s_roll', 50000)]])]);
    $in = keyInBucket('s_roll', 0, 50000);
    $out = keyInBucket('s_roll', 50000, 100000);
    $details = EvaluationOptions::defaults()->withDetails();

    $withDetails = $evaluator->evaluateBoolean('f', user($in), false, $details);

    expect($withDetails->value)->toBeTrue()->and($withDetails->reason)->toBe(Reason::Rule)->and($withDetails->ruleId)->toBe('r1')
        ->and($withDetails->bucket)->toBe(BucketSha256V1::bucket('s_roll', 'user', $in))
        ->and($evaluator->evaluateBoolean('f', user($in), false)->bucket)->toBeNull()
        ->and($evaluator->evaluateBoolean('f', user($out), true, $details)->value)->toBeFalse()
        ->and($evaluator->evaluateBoolean('f', user($out), true, $details)->variationKey)->toBe('off');
});

it('copies experiment fields from the deciding rollout', function () {
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['rules' => [rolloutRule('s_exp', 30000, extra: ['experimentId' => 'exp_1', 'allocationId' => 'alc_1'])]])]);

    foreach ([keyInBucket('s_exp', 0, 30000), keyInBucket('s_exp', 30000, 100000)] as $key) {
        $result = $evaluator->evaluateBoolean('f', user($key), false);
        expect($result->experimentId)->toBe('exp_1')->and($result->allocationId)->toBe('alc_1');
    }
});

it('returns MISSING_SUBJECT_KEY when a rollout cannot bucket', function (Context $context) {
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['rules' => [rolloutRule('s_roll', 50000)]])]);

    $result = $evaluator->evaluateBoolean('f', $context, true);

    expect($result->reason)->toBe(Reason::Error)->and($result->errorKind)->toBe(ErrorKind::MissingSubjectKey)->and($result->value)->toBeTrue()->and($result->variationKey)->toBeNull();
})->with([
    'no key' => [new Context('user')],
]);

it('buckets by an attribute, which must be a non-empty string', function (mixed $value, bool $ok) {
    $rule = ['id' => 'r1', 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'org', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 100000]]]]];
    $evaluator = evaluatorFor(['f' => Snapshots::flag(['rules' => [$rule]])]);

    $result = $evaluator->evaluateBoolean('f', new Context('user', null, ['org' => $value]), false);

    expect($result->errorKind)->toBe($ok ? null : ErrorKind::MissingSubjectKey);
})->with([
    'a string' => ['acme', true],
    'a number' => [123, false],
    'a boolean' => [true, false],
    'an empty string' => ['', false],
    'null' => [null, false],
    'a list' => [['a'], false],
]);

it('supports exactly the capabilities of protocol version 1 unless told otherwise', function () {
    $json = Snapshots::valid(function (array $s) {
        $s['flags'] = ['f' => Snapshots::flag(['requires' => ['future.v9'], 'defaultVariation' => 'on']), 'g' => Snapshots::flag(['requires' => ['segments.v1'], 'defaultVariation' => 'on'])];

        return $s;
    });
    $snapshot = SnapshotParser::parse($json);
    $default = new Evaluator($snapshot);
    $future = new Evaluator($snapshot, supportedCapabilities: [...Evaluator::supportedCapabilities(), 'future.v9']);
    $none = new Evaluator($snapshot, supportedCapabilities: []);

    expect(Evaluator::supportedCapabilities())->toBe(['operators.v1', 'semver.v1', 'dates.v1', 'segments.v1', 'prerequisites.v1', 'rollouts.v1', 'experiments.v1'])
        ->and($default->evaluateBoolean('f', user(), false)->errorKind)->toBe(ErrorKind::UnsupportedCapability)
        ->and($default->evaluateBoolean('g', user(), false)->value)->toBeTrue()
        ->and($future->evaluateBoolean('f', user(), false)->value)->toBeTrue()
        ->and($none->evaluateBoolean('g', user(), true)->errorKind)->toBe(ErrorKind::UnsupportedCapability);
});

it('serves the off variation for an unmet prerequisite, and a prerequisite error is an error', function () {
    $base = Snapshots::flag(['rules' => [when('plan', 'pro')]]);
    $child = Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'base', 'variations' => ['on']]]]);
    $broken = Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'ghost', 'variations' => ['on']]]]);
    $parent = Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'broken', 'variations' => ['on']]]]);
    $evaluator = evaluatorFor(['base' => $base, 'child' => $child, 'broken' => $broken, 'parent' => $parent]);

    $unmet = $evaluator->evaluateBoolean('child', user(attributes: ['plan' => 'free']), true);

    expect($evaluator->evaluateBoolean('child', user(attributes: ['plan' => 'pro']), false)->reason)->toBe(Reason::Default)
        ->and($unmet->reason)->toBe(Reason::Prerequisite)->and($unmet->prerequisiteKey)->toBe('base')->and($unmet->variationKey)->toBe('off')->and($unmet->value)->toBeFalse()
        ->and($evaluator->evaluateBoolean('parent', user(), true)->errorKind)->toBe(ErrorKind::InvalidConfig);
});

it('rejects cycles, self-references and chains that are too deep', function () {
    $link = static fn(string $to): array => Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => $to, 'variations' => ['on']]]]);
    $chain = [];
    for ($i = 0; $i < 12; $i++) {
        $chain["c$i"] = $i < 11 ? $link('c' . ($i + 1)) : Snapshots::flag(['defaultVariation' => 'on']);
    }
    $evaluator = evaluatorFor(['self' => $link('self'), 'a' => $link('b'), 'b' => $link('a'), ...$chain]);

    expect($evaluator->evaluateBoolean('self', user(), true)->errorKind)->toBe(ErrorKind::InvalidConfig)
        ->and($evaluator->evaluateBoolean('a', user(), true)->errorKind)->toBe(ErrorKind::InvalidConfig)
        ->and($evaluator->evaluateBoolean('b', user(), true)->errorKind)->toBe(ErrorKind::InvalidConfig)
        ->and($evaluator->evaluateBoolean('c1', user(), false)->reason)->toBe(Reason::Default)
        ->and($evaluator->evaluateBoolean('c0', user(), true)->errorKind)->toBe(ErrorKind::InvalidConfig);
});

it('evaluates a prerequisite once per call', function () {
    $base = Snapshots::flag(['rules' => [when('plan', 'pro')]]);
    $left = Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'base', 'variations' => ['on']]]]);
    $right = Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'base', 'variations' => ['on']]]]);
    $top = Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'left', 'variations' => ['on']], ['flagKey' => 'right', 'variations' => ['on']]]]);

    $result = evaluatorFor(['base' => $base, 'left' => $left, 'right' => $right, 'top' => $top])->evaluateBoolean('top', user(attributes: ['plan' => 'pro']), false);

    expect($result->reason)->toBe(Reason::Default)->and($result->value)->toBeTrue();
});
