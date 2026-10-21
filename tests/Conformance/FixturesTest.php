<?php

declare(strict_types=1);

use BitFlag\Tests\Support\Protocol;

/*
 * Runs every fixture of the pinned protocol release. The release comes from build/protocol/<version>, filled by
 * scripts/fetch-protocol-fixtures.sh, or from the directory in BITFLAG_PROTOCOL_DIR. When it is missing the suite
 * fails; it never skips.
 */

dataset('evaluation cases', function () {
    foreach (Protocol::runner()->evaluationCaseIds() as $id) {
        yield $id['fixture'] . ' / ' . $id['case'] => [$id['fixture'], $id['case']];
    }
});

dataset('snapshot fixtures', function () {
    foreach (Protocol::runner()->snapshotFixtureIds() as $id) {
        yield $id => [$id];
    }
});

it('uses an intact release', function () {
    expect(Protocol::runner()->verifyManifest())->toBe([]);
});

it('passes the evaluation fixture', function (string $fixture, string $case) {
    $outcome = Protocol::runner()->runEvaluationCase($fixture, $case);

    expect($outcome->failures)->toBe([], $outcome->describe());
})->with('evaluation cases');

it('handles the snapshot fixture', function (string $fixture) {
    $outcome = Protocol::runner()->runSnapshotFixture($fixture);

    expect($outcome->failures)->toBe([], $outcome->describe());
})->with('snapshot fixtures');

it('computes every bucket vector', function () {
    $failed = array_map(static fn($o): string => $o->describe(), array_filter(Protocol::runner()->runBucketVectors(), static fn($o): bool => ! $o->passed()));

    expect(count(Protocol::runner()->runBucketVectors()))->toBeGreaterThanOrEqual(200)->and($failed)->toBe([]);
});

it('canonicalizes every canonical vector', function () {
    $outcomes = Protocol::runner()->runCanonicalVectors();
    $failed = array_map(static fn($o): string => $o->describe(), array_filter($outcomes, static fn($o): bool => ! $o->passed()));

    expect(count($outcomes))->toBeGreaterThan(0)->and($failed)->toBe([]);
});

it('covers every reason and error kind in at least one fixture', function () {
    $report = Protocol::runner()->report('bitflag-php', 'test');
    $reasons = [];
    $errors = [];
    foreach ($report['results'] as $entry) {
        $result = $entry['result'] ?? null;
        if (is_array($result)) {
            $reasons[$result['reason']] = true;
            if ($result['errorKind'] !== null) {
                $errors[$result['errorKind']] = true;
            }
        }
    }
    foreach ($report['snapshots'] as $entry) {
        if (($entry['errorKind'] ?? null) !== null) {
            $errors[$entry['errorKind']] = true;
        }
    }

    // SNAPSHOT_TOO_OLD, CLIENT_NOT_READY and AUTHORIZATION_REJECTED are produced by SDK clients, not by the evaluator.
    expect(array_keys($reasons))->toContain('OFF', 'PREREQUISITE', 'OVERRIDE', 'RULE', 'DEFAULT', 'FALLBACK', 'ERROR')
        ->and(array_keys($errors))->toContain('FLAG_NOT_FOUND', 'TYPE_MISMATCH', 'MISSING_SUBJECT_KEY', 'INVALID_CONFIG', 'INVALID_CONTEXT', 'UNSUPPORTED_CAPABILITY', 'MALFORMED_SNAPSHOT');
});
