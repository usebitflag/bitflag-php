<?php

declare(strict_types=1);

use BitFlag\Evaluation\Bucketing\BucketSha256V1;
use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\EvaluationOptions;
use BitFlag\Evaluation\Evaluator;
use BitFlag\Evaluation\FixedClock;
use BitFlag\Evaluation\Reason;
use BitFlag\Protocol\SnapshotParser;
use BitFlag\Tests\Support\Snapshots;

/**
 * @param  array<string, array<string, mixed>>  $flags
 * @param  array<string, mixed>  $segments
 */
function tracingEvaluator(array $flags, array $segments = []): Evaluator
{
    $json = Snapshots::valid(function (array $s) use ($flags, $segments) {
        $s['flags'] = $flags;
        $s['segments'] = $segments === [] ? (object) [] : $segments;

        return $s;
    });

    return new Evaluator(SnapshotParser::parse($json), new FixedClock(new DateTimeImmutable('2026-10-06T08:00:00Z')));
}

/**
 * @return list<array<string, mixed>>
 */
function stepsOf(Evaluator $evaluator, string $flag, Context $context): array
{
    $result = $evaluator->evaluateBoolean($flag, $context, false, EvaluationOptions::defaults()->withTrace());
    expect($result->trace)->not->toBeNull();

    return $result->trace?->toArray()['steps'] ?? [];
}

it('attaches a trace only on request', function () {
    $evaluator = tracingEvaluator(['f' => Snapshots::flag()]);

    expect($evaluator->evaluateBoolean('f', new Context('user', 'u_1'), false)->trace)->toBeNull()
        ->and($evaluator->evaluateBoolean('f', new Context('user', 'u_1'), false, EvaluationOptions::defaults()->withDetails())->trace)->toBeNull();
});

it('traces the steps of a default decision', function () {
    $flag = Snapshots::flag(['rules' => [['id' => 'r1', 'match' => ['all' => [['attribute' => 'plan', 'op' => 'eq', 'values' => ['pro']]]], 'serve' => ['variation' => 'on']]]]);

    $steps = stepsOf(tracingEvaluator(['f' => $flag]), 'f', new Context('user', 'u_1', ['plan' => 'free']));

    expect($steps)->toBe([
        ['step' => 'rule', 'outcome' => 'not_matched', 'ruleId' => 'r1', 'conditions' => [['path' => '/all/0', 'op' => 'eq', 'attribute' => 'plan', 'outcome' => 'no_match']]],
        ['step' => 'default', 'outcome' => 'served'],
    ]);
});

it('traces capability, off, prerequisite and override steps', function () {
    $evaluator = tracingEvaluator([
        'base' => Snapshots::flag(['defaultVariation' => 'on']),
        'off' => Snapshots::flag(['enabled' => false, 'requires' => ['operators.v1']]),
        'child' => Snapshots::flag(['defaultVariation' => 'on', 'prerequisites' => [['flagKey' => 'base', 'variations' => ['on']]], 'overrides' => [['kind' => 'user', 'key' => 'u_1', 'variation' => 'off', 'expiresAt' => '2026-10-06T07:00:00Z']]]),
        'live' => Snapshots::flag(['overrides' => [['kind' => 'user', 'key' => 'u_1', 'variation' => 'on', 'expiresAt' => '2026-10-06T09:00:00Z']]]),
    ]);
    $context = new Context('user', 'u_1');

    expect(stepsOf($evaluator, 'off', $context))->toBe([['step' => 'capability', 'outcome' => 'supported'], ['step' => 'off', 'outcome' => 'served']])
        ->and(stepsOf($evaluator, 'child', $context))->toBe([
            ['step' => 'prerequisite', 'outcome' => 'met', 'prerequisitePath' => ['base']],
            ['step' => 'override', 'outcome' => 'expired', 'overrideExpiresAt' => '2026-10-06T07:00:00Z'],
            ['step' => 'default', 'outcome' => 'served'],
        ])
        ->and(stepsOf($evaluator, 'live', $context))->toBe([['step' => 'override', 'outcome' => 'applied', 'overrideExpiresAt' => '2026-10-06T09:00:00Z']]);
});

it('traces the bucket and the slot of a rollout', function () {
    $rule = ['id' => 'r1', 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => 's_trace', 'slots' => [['variation' => 'on', 'weight' => 20000]], 'remainderVariation' => 'off']]];
    $evaluator = tracingEvaluator(['f' => Snapshots::flag(['rules' => [$rule]])]);
    $bucket = BucketSha256V1::bucket('s_trace', 'user', 'u_20931');

    $steps = stepsOf($evaluator, 'f', new Context('user', 'u_20931'));

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['bucket'])->toBe($bucket)
        ->and($steps[0]['slot'])->toBe($bucket < 20000 ? ['variation' => 'on', 'start' => 0, 'end' => 20000] : ['variation' => 'off', 'start' => 20000, 'end' => 100000]);
});

it('keeps the steps taken before an error', function () {
    $rule = ['id' => 'r1', 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 100000]]]]];
    $evaluator = tracingEvaluator(['f' => Snapshots::flag(['rules' => [$rule]])]);

    $result = $evaluator->evaluateBoolean('f', new Context('user'), true, EvaluationOptions::defaults()->withTrace());

    expect($result->errorKind)->toBe(ErrorKind::MissingSubjectKey)
        ->and($result->trace?->toArray()['steps'])->toBe([['step' => 'rule', 'outcome' => 'matched', 'ruleId' => 'r1', 'conditions' => []]]);
});

it('records disabled rules without evaluating them', function () {
    $rules = [['id' => 'off', 'enabled' => false, 'match' => ['all' => []], 'serve' => ['variation' => 'on']]];
    $steps = stepsOf(tracingEvaluator(['f' => Snapshots::flag(['rules' => $rules])]), 'f', new Context('user', 'u_1'));

    expect($steps[0])->toBe(['step' => 'rule', 'outcome' => 'disabled', 'ruleId' => 'off']);
});

it('never puts context values in a trace', function () {
    $rule = ['id' => 'r1', 'match' => ['all' => [['attribute' => 'email', 'op' => 'ends_with', 'values' => ['@acme.dev']], ['attribute' => 'plan', 'op' => 'in', 'values' => ['pro']]]], 'serve' => ['variation' => 'on']];
    $evaluator = tracingEvaluator(['f' => Snapshots::flag(['rules' => [$rule]])]);
    $context = new Context('user', 'SECRET-KEY', ['email' => 'secret.person@acme.dev', 'plan' => 'SECRET-PLAN']);

    $json = json_encode($evaluator->evaluateBoolean('f', $context, false, EvaluationOptions::defaults()->withTrace())->trace?->toArray(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('SECRET')->and($json)->not->toContain('secret.person');
});

it('evaluates every flag as its declared type', function () {
    $evaluator = tracingEvaluator([
        'b' => Snapshots::flag(['defaultVariation' => 'on']),
        's' => Snapshots::flag(['type' => 'string', 'variations' => ['hello' => 'Hello', 'off' => ''], 'defaultVariation' => 'hello']),
        'broken' => Snapshots::flag(['defaultVariation' => 'ghost']),
        'cycle' => Snapshots::flag(['prerequisites' => [['flagKey' => 'cycle', 'variations' => ['on']]]]),
    ]);

    $all = $evaluator->evaluateAll(new Context('user', 'u_1'));

    expect(array_keys($all->results))->toBe(['b', 's', 'broken', 'cycle'])
        ->and($all->revision)->toBe(42)->and($all->generation)->toBe(1)
        ->and($all->results['b']->value)->toBeTrue()
        ->and($all->results['s']->value)->toBe('Hello')
        ->and($all->results['broken']->reason)->toBe(Reason::Error)
        ->and($all->results['broken']->value)->toBeNull()
        ->and($all->results['broken']->variationKey)->toBeNull()
        ->and($all->results['broken']->errorKind)->toBe(ErrorKind::InvalidConfig)
        ->and($all->results['cycle']->errorKind)->toBe(ErrorKind::InvalidConfig)
        ->and($all->toArray()['results']['s']['value'])->toBe('Hello');
});

it('evaluates nothing for an invalid context in bulk, and lists no fallback results', function () {
    $evaluator = tracingEvaluator(['b' => Snapshots::flag()]);

    $all = $evaluator->evaluateAll(Context::fromArray(['key' => 'u_1']));

    expect($all->results['b']->errorKind)->toBe(ErrorKind::InvalidContext)->and($all->results['b']->value)->toBeNull();
});

it('honours details and trace options in bulk', function () {
    $rule = ['id' => 'r1', 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'on', 'weight' => 100000]]]]];
    $evaluator = tracingEvaluator(['f' => Snapshots::flag(['rules' => [$rule]])]);

    $all = $evaluator->evaluateAll(new Context('user', 'u_1'), EvaluationOptions::defaults()->withDetails()->withTrace());

    expect($all->results['f']->bucket)->toBe(BucketSha256V1::bucket('s', 'user', 'u_1'))->and($all->results['f']->trace)->not->toBeNull();
});
