<?php

declare(strict_types=1);

use BitFlag\Protocol\SnapshotParser;
use BitFlag\Protocol\SnapshotValidator;
use BitFlag\Protocol\ValidationIssue;
use BitFlag\Tests\Support\Snapshots;

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 * @return list<array{string, string}> code and path of every issue
 */
function issuesOf(callable $mutate): array
{
    $snapshot = SnapshotParser::parse(Snapshots::valid($mutate));

    return array_map(static fn(ValidationIssue $i): array => [$i->code, $i->path], SnapshotValidator::validate($snapshot));
}

function flagWith(array $overrides): array
{
    return Snapshots::flag($overrides);
}

it('finds nothing wrong in a sound snapshot', function () {
    $issues = issuesOf(function (array $s) {
        $s['flags'] = [
            'base' => flagWith(['requires' => ['rollouts.v1'], 'rules' => [['id' => 'r1', 'match' => ['all' => [['op' => 'in_segment', 'values' => ['beta']]]], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => 's1', 'slots' => [['variation' => 'on', 'weight' => 10000]], 'remainderVariation' => 'off']]]]]),
            'child' => flagWith(['requires' => ['prerequisites.v1'], 'prerequisites' => [['flagKey' => 'base', 'variations' => ['on', 'off']]]]),
        ];
        $s['segments'] = ['beta' => ['version' => 1, 'match' => ['all' => []]]];
        $s['requiredCapabilities'] = ['prerequisites.v1', 'rollouts.v1'];

        return $s;
    });

    expect($issues)->toBe([]);
});

it('reports a dangling variation wherever one is referenced', function () {
    $issues = issuesOf(function (array $s) {
        $s['flags'] = [
            'f' => flagWith([
                'offVariation' => 'x1',
                'defaultVariation' => 'x2',
                'overrides' => [['kind' => 'user', 'key' => 'u', 'variation' => 'x3', 'expiresAt' => null]],
                'rules' => [
                    ['id' => 'a', 'match' => ['all' => []], 'serve' => ['variation' => 'x4']],
                    ['id' => 'b', 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => 's', 'slots' => [['variation' => 'x5', 'weight' => 10]], 'remainderVariation' => 'x6']]],
                ],
            ]),
            'g' => flagWith(['prerequisites' => [['flagKey' => 'f', 'variations' => ['on', 'x7']]]]),
        ];

        return $s;
    });

    expect($issues)->toBe([
        [ValidationIssue::DANGLING_VARIATION, '/flags/f/offVariation'],
        [ValidationIssue::DANGLING_VARIATION, '/flags/f/defaultVariation'],
        [ValidationIssue::DANGLING_VARIATION, '/flags/f/overrides/0/variation'],
        [ValidationIssue::DANGLING_VARIATION, '/flags/f/rules/0/serve/variation'],
        [ValidationIssue::DANGLING_VARIATION, '/flags/f/rules/1/serve/rollout/slots/0/variation'],
        [ValidationIssue::DANGLING_VARIATION, '/flags/f/rules/1/serve/rollout/remainderVariation'],
        [ValidationIssue::DANGLING_VARIATION, '/flags/g/prerequisites/0/variations/1'],
    ]);
});

it('reports missing segments in rules and in segments', function () {
    $issues = issuesOf(function (array $s) {
        $s['flags'] = ['f' => flagWith(['rules' => [['id' => 'r', 'match' => ['all' => [['any' => [['op' => 'in_segment', 'values' => ['ok', 'ghost']]]]]], 'serve' => ['variation' => 'on']]]])];
        $s['segments'] = [
            'ok' => ['version' => 1, 'match' => ['all' => []]],
            'inner' => ['version' => 1, 'match' => ['all' => [['op' => 'not_in_segment', 'values' => ['missing']]]]],
        ];

        return $s;
    });

    expect($issues)->toBe([
        [ValidationIssue::DANGLING_SEGMENT, '/flags/f/rules/0/match/all/0/any/0/values/1'],
        [ValidationIssue::DANGLING_SEGMENT, '/segments/inner/match/all/0/values/0'],
    ]);
});

it('reports a missing prerequisite flag', function () {
    $issues = issuesOf(function (array $s) {
        $s['flags'] = ['f' => flagWith(['prerequisites' => [['flagKey' => 'ghost', 'variations' => ['on']]]])];

        return $s;
    });

    expect($issues)->toBe([[ValidationIssue::DANGLING_PREREQUISITE, '/flags/f/prerequisites/0/flagKey']]);
});

it('reports prerequisite cycles, including a flag that requires itself', function () {
    $link = static fn(string $to): array => flagWith(['prerequisites' => [['flagKey' => $to, 'variations' => ['on']]]]);

    $issues = issuesOf(function (array $s) use ($link) {
        $s['flags'] = ['self' => $link('self'), 'a' => $link('b'), 'b' => $link('c'), 'c' => $link('a'), 'fine' => $link('self')];

        return $s;
    });

    expect($issues)->toContain([ValidationIssue::PREREQUISITE_CYCLE, '/flags/self/prerequisites/0'])
        ->and($issues)->toContain([ValidationIssue::PREREQUISITE_CYCLE, '/flags/c/prerequisites/0'])
        ->and(array_filter($issues, static fn(array $i): bool => $i[0] === ValidationIssue::PREREQUISITE_CYCLE))->toHaveCount(2);
});

it('does not call a shared prerequisite a cycle', function () {
    $needs = static fn(string ...$keys): array => flagWith(['prerequisites' => array_map(static fn(string $k): array => ['flagKey' => $k, 'variations' => ['on']], $keys)]);

    $issues = issuesOf(function (array $s) use ($needs) {
        $s['flags'] = ['a' => flagWith([]), 'b' => $needs('a'), 'c' => $needs('a'), 'd' => $needs('b', 'c')];

        return $s;
    });

    expect($issues)->toBe([]);
});

it('reports flags whose prerequisite chain is longer than ten links', function () {
    $chain = static fn(int $length): array => array_combine(
        array_map(static fn(int $i): string => "f$i", range(0, $length)),
        array_map(static fn(int $i): array => $i < $length ? flagWith(['prerequisites' => [['flagKey' => 'f' . ($i + 1), 'variations' => ['on']]]]) : flagWith([]), range(0, $length)),
    );

    $ok = issuesOf(function (array $s) use ($chain) {
        $s['flags'] = $chain(10);

        return $s;
    });
    $deep = issuesOf(function (array $s) use ($chain) {
        $s['flags'] = $chain(11);

        return $s;
    });

    expect($ok)->toBe([])->and($deep)->toBe([[ValidationIssue::PREREQUISITE_DEPTH, '/flags/f0']]);
});

it('reports segments that lead back to themselves', function () {
    $issues = issuesOf(function (array $s) {
        $s['segments'] = [
            'a' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['b']]]]],
            'b' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['a']]]]],
            'self' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['self']]]]],
            'ok' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['a']]]]],
        ];

        return $s;
    });

    expect($issues)->toBe([
        [ValidationIssue::SEGMENT_CYCLE, '/segments/b/match'],
        [ValidationIssue::SEGMENT_CYCLE, '/segments/self/match'],
    ]);
});

it('requires the capability list to be the union of what flags require', function (array $required, bool $mismatch) {
    $issues = issuesOf(function (array $s) use ($required) {
        $s['flags'] = ['a' => flagWith(['requires' => ['segments.v1']]), 'b' => flagWith(['requires' => ['segments.v1', 'dates.v1']])];
        $s['requiredCapabilities'] = $required;

        return $s;
    });

    expect($issues)->toBe($mismatch ? [[ValidationIssue::CAPABILITY_UNION_MISMATCH, '/requiredCapabilities']] : []);
})->with([
    'exact' => [['dates.v1', 'segments.v1'], false],
    'other order' => [['segments.v1', 'dates.v1'], false],
    'missing one' => [['segments.v1'], true],
    'extra one' => [['segments.v1', 'dates.v1', 'rollouts.v1'], true],
    'empty' => [[], true],
]);

it('reports a salt shared by unrelated rollouts but not by linked ones', function () {
    $rollout = static fn(string $salt, array $extra = []): array => [['id' => 'r', 'match' => ['all' => []], 'serve' => ['rollout' => ['bucketBy' => 'key', 'salt' => $salt, 'slots' => [['variation' => 'on', 'weight' => 10000]], 'remainderVariation' => 'off', ...$extra]]]];

    $unrelated = issuesOf(function (array $s) use ($rollout) {
        $s['flags'] = ['a' => flagWith(['rules' => $rollout('same')]), 'b' => flagWith(['rules' => $rollout('same')]), 'c' => flagWith(['rules' => $rollout('other')])];

        return $s;
    });
    $linked = issuesOf(function (array $s) use ($rollout) {
        $linkedExtra = ['experimentId' => 'exp_1', 'allocationId' => 'alc_1'];
        $s['flags'] = ['a' => flagWith(['rules' => $rollout('same', $linkedExtra)]), 'b' => flagWith(['rules' => $rollout('same', $linkedExtra)])];

        return $s;
    });
    $different = issuesOf(function (array $s) use ($rollout) {
        $s['flags'] = ['a' => flagWith(['rules' => $rollout('same', ['experimentId' => 'e1', 'allocationId' => 'a1'])]), 'b' => flagWith(['rules' => $rollout('same', ['experimentId' => 'e2', 'allocationId' => 'a2'])])];

        return $s;
    });

    expect($unrelated)->toBe([[ValidationIssue::DUPLICATE_SALT, '/flags/b/rules/0/serve/rollout/salt']])
        ->and($linked)->toBe([])
        ->and($different)->toBe([[ValidationIssue::DUPLICATE_SALT, '/flags/b/rules/0/serve/rollout/salt']]);
});

it('escapes JSON Pointer characters in paths', function () {
    $issues = issuesOf(function (array $s) {
        $s['segments'] = ['a-b' => ['version' => 1, 'match' => ['all' => [['op' => 'in_segment', 'values' => ['ghost']]]]]];

        return $s;
    });

    expect($issues[0][1])->toBe('/segments/a-b/match/all/0/values/0');
});
