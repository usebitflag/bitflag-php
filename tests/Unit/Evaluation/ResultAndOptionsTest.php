<?php

declare(strict_types=1);

use BitFlag\Evaluation\Clock;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\EvaluationOptions;
use BitFlag\Evaluation\EvaluationResult;
use BitFlag\Evaluation\FixedClock;
use BitFlag\Evaluation\Internal\Values\Instant;
use BitFlag\Evaluation\Reason;
use BitFlag\Evaluation\SystemClock;
use BitFlag\Evaluation\Trace\ConditionOutcome;
use BitFlag\Evaluation\Trace\ConditionTrace;
use BitFlag\Evaluation\Trace\EvaluationTrace;
use BitFlag\Evaluation\Trace\TraceStep;

it('has options that are immutable and off by default', function () {
    $defaults = EvaluationOptions::defaults();
    $at = new DateTimeImmutable('2026-10-06T08:00:00Z');
    $changed = $defaults->at($at)->withDetails()->withTrace();

    expect($defaults->time)->toBeNull()->and($defaults->details)->toBeFalse()->and($defaults->trace)->toBeFalse()
        ->and($changed->time)->toBe($at)->and($changed->details)->toBeTrue()->and($changed->trace)->toBeTrue()
        ->and($defaults->withDetails()->trace)->toBeFalse()
        ->and($defaults->withTrace()->details)->toBeFalse();
});

it('has clocks that return fixed and current time', function () {
    $fixed = new FixedClock($at = new DateTimeImmutable('2026-10-06T08:00:00Z'));
    $system = new SystemClock();

    expect($fixed)->toBeInstanceOf(Clock::class)->and($fixed->now())->toBe($at)
        ->and(abs($system->now()->getTimestamp() - time()))->toBeLessThan(5);
});

it('writes a result in the shape of the result schema', function () {
    $result = new EvaluationResult('checkout', true, 'on', Reason::Rule, 'r1', null, 42, 1, 76138, 'exp_1', 'alc_1', null, '2026-10-06T08:00:00Z');

    expect($result->toArray())->toBe([
        'flagKey' => 'checkout', 'value' => true, 'variationKey' => 'on', 'reason' => 'RULE', 'ruleId' => 'r1',
        'prerequisiteKey' => null, 'revision' => 42, 'generation' => 1, 'bucket' => 76138, 'experimentId' => 'exp_1',
        'allocationId' => 'alc_1', 'errorKind' => null, 'evaluatedAt' => '2026-10-06T08:00:00Z',
    ])->and($result->isServed())->toBeTrue();
});

it('marks a fallback as not served and carries the error kind', function () {
    $result = new EvaluationResult('f', false, null, Reason::Fallback, null, null, 42, 1, null, null, null, ErrorKind::FlagNotFound, '2026-10-06T08:00:00Z');

    expect($result->isServed())->toBeFalse()->and($result->toArray()['errorKind'])->toBe('FLAG_NOT_FOUND')->and($result->toArray())->not->toHaveKey('trace');
});

it('writes a trace without context values', function () {
    $trace = new EvaluationTrace([
        new TraceStep('override', 'skipped', overrideExpiresAt: '2026-10-06T09:00:00Z'),
        new TraceStep('rule', 'matched', ruleId: 'r1', conditions: [new ConditionTrace('/all/0', 'eq', 'country', null, ConditionOutcome::Match), new ConditionTrace('/all/1', 'in_segment', null, 'beta', ConditionOutcome::NotEvaluated)], bucket: 14203, slot: ['variation' => 'on', 'start' => 0, 'end' => 20000]),
    ]);
    $result = new EvaluationResult('f', true, 'on', Reason::Rule, 'r1', null, 42, 1, 14203, null, null, null, '2026-10-06T08:00:00Z', $trace);

    expect($result->toArray()['trace'])->toBe(['steps' => [
        ['step' => 'override', 'outcome' => 'skipped', 'overrideExpiresAt' => '2026-10-06T09:00:00Z'],
        ['step' => 'rule', 'outcome' => 'matched', 'ruleId' => 'r1', 'conditions' => [
            ['path' => '/all/0', 'op' => 'eq', 'attribute' => 'country', 'outcome' => 'match'],
            ['path' => '/all/1', 'op' => 'in_segment', 'segment' => 'beta', 'outcome' => 'not_evaluated'],
        ], 'bucket' => 14203, 'slot' => ['variation' => 'on', 'start' => 0, 'end' => 20000]],
    ]]);
});

it('formats epoch milliseconds as an RFC 3339 UTC instant', function (int $millis, string $expected) {
    expect(Instant::format($millis))->toBe($expected);
})->with([
    'epoch' => [0, '1970-01-01T00:00:00Z'],
    'with milliseconds' => [1, '1970-01-01T00:00:00.001Z'],
    'before the epoch' => [-1, '1969-12-31T23:59:59.999Z'],
    'a known date' => [1791273600000, '2026-10-06T08:00:00Z'],
    'leap day' => [1835395200000, '2028-02-29T00:00:00Z'],
    'year 0' => [-62167219200000, '0000-01-01T00:00:00Z'],
    'year 9999' => [253402300799999, '9999-12-31T23:59:59.999Z'],
]);

it('formats and parses instants as inverses', function () {
    mt_srand(2026);
    for ($i = 0; $i < 2000; $i++) {
        $millis = mt_rand(-62167219200, 253402300799) * 1000 + mt_rand(0, 999);
        $text = Instant::format($millis);
        expect(Instant::parse($text))->toBe($millis, $text);

    }
});
