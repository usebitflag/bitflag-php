<?php

declare(strict_types=1);

namespace BitFlag\Testing\Conformance;

use BitFlag\Evaluation\Bucketing\BucketSha256V1;
use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\EvaluationOptions;
use BitFlag\Evaluation\EvaluationResult;
use BitFlag\Evaluation\Evaluator;
use BitFlag\Evaluation\Exception\FallbackTypeError;
use BitFlag\Evaluation\FixedClock;
use BitFlag\Protocol\Json\CanonicalJson;
use BitFlag\Protocol\Json\JsonDecoder;
use BitFlag\Protocol\MalformedSnapshot;
use BitFlag\Protocol\Model\FlagType;
use BitFlag\Protocol\Model\Snapshot;
use BitFlag\Protocol\SnapshotParser;
use DateTimeImmutable;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Runs a release of the protocol's conformance fixtures against this evaluator.
 *
 * The directory is an extracted release archive: it holds fixtures/ with the evaluation and snapshot fixtures,
 * the bucket vectors, the canonical vectors and manifest.json. The runner reads files, so it lives outside the
 * pure evaluation code and is meant for tests and tooling.
 */
final class FixtureRunner
{
    private const BIG = 1_000_000_000;

    private const DEEP = 10_000;

    /** @var array<string, Snapshot> */
    private array $snapshots = [];

    /** @var list<stdClass>|null */
    private ?array $evaluation = null;

    /** @var list<stdClass>|null */
    private ?array $snapshotLevel = null;

    public function __construct(private readonly string $releaseDir) {}

    /**
     * Checks that the fixture files are the ones the manifest lists.
     *
     * @return list<string> problems; empty when the release is intact
     */
    public function verifyManifest(): array
    {
        $manifest = $this->readJson($this->path('fixtures/manifest.json'));
        $files = $manifest instanceof stdClass ? ($manifest->files ?? null) : null;
        if (! $manifest instanceof stdClass || ! is_array($files)) {
            return ['fixtures/manifest.json has no file list.'];
        }

        $problems = [];
        $lines = '';
        $listed = [];
        foreach ($files as $entry) {
            if (! $entry instanceof stdClass || ! is_string($entry->path ?? null) || ! is_string($entry->sha256 ?? null)) {
                return ['fixtures/manifest.json has a malformed entry.'];
            }
            $listed[] = $entry->path;
            $file = $this->path('fixtures/' . $entry->path);
            if (! is_file($file)) {
                $problems[] = 'Missing fixture file: ' . $entry->path;

                continue;
            }
            if (hash_file('sha256', $file) !== $entry->sha256) {
                $problems[] = 'Fixture file changed: ' . $entry->path;
            }
            $lines .= $entry->path . ':' . $entry->sha256 . "\n";
        }
        if (hash('sha256', $lines) !== ($manifest->checksum ?? null)) {
            $problems[] = 'The manifest checksum does not match its file list.';
        }

        foreach ($this->listJson($this->path('fixtures')) as $relative) {
            if ($relative !== 'manifest.json' && ! in_array($relative, $listed, true)) {
                $problems[] = 'Fixture file not in the manifest: ' . $relative;
            }
        }

        return $problems;
    }

    /**
     * @return list<array{fixture: string, case: string}> every evaluation case, by fixture ID and case name
     */
    public function evaluationCaseIds(): array
    {
        $ids = [];
        foreach ($this->evaluationFixtures() as $fixture) {
            foreach ($this->cases($fixture) as $case) {
                $ids[] = ['fixture' => $this->string($fixture, 'id'), 'case' => $this->string($case, 'name')];
            }
        }

        return $ids;
    }

    /**
     * @return list<string> the IDs of the snapshot-level fixtures
     */
    public function snapshotFixtureIds(): array
    {
        return array_map(fn(stdClass $f): string => $this->string($f, 'id'), $this->snapshotFixtures());
    }

    public function runEvaluationCase(string $fixtureId, string $caseName): Outcome
    {
        foreach ($this->evaluationFixtures() as $fixture) {
            if ($this->string($fixture, 'id') !== $fixtureId) {
                continue;
            }
            foreach ($this->cases($fixture) as $case) {
                if ($this->string($case, 'name') === $caseName) {
                    return $this->evaluate($fixture, $case);
                }
            }
        }

        throw new RuntimeException("No case \"$caseName\" in fixture \"$fixtureId\".");
    }

    public function runSnapshotFixture(string $fixtureId): Outcome
    {
        foreach ($this->snapshotFixtures() as $fixture) {
            if ($this->string($fixture, 'id') === $fixtureId) {
                return $this->parseSnapshot($fixture);
            }
        }

        throw new RuntimeException("No snapshot fixture \"$fixtureId\".");
    }

    /**
     * @return list<Outcome> one outcome per bucket vector
     */
    public function runBucketVectors(): array
    {
        $vectors = $this->readJson($this->path('fixtures/bucketing/bucket-sha256-v1.json'));
        if (! is_array($vectors)) {
            throw new RuntimeException('The bucket vectors file is not a list.');
        }

        $outcomes = [];
        foreach ($vectors as $index => $vector) {
            if (! $vector instanceof stdClass) {
                continue;
            }
            $salt = $this->string($vector, 'salt');
            $kind = $this->string($vector, 'kind');
            $value = $this->string($vector, 'value');
            $failures = [];

            $input = BucketSha256V1::input($salt, $kind, $value);
            if (bin2hex($input) !== $this->string($vector, 'inputHex')) {
                $failures[] = 'hashed bytes differ';
            }
            if (substr(hash('sha256', $input), 0, 16) !== $this->string($vector, 'digestPrefixHex')) {
                $failures[] = 'digest prefix differs';
            }
            $bucket = BucketSha256V1::bucket($salt, $kind, $value);
            if ($bucket !== ($vector->bucket ?? null)) {
                $failures[] = 'bucket is ' . $bucket . ', expected ' . json_encode($vector->bucket ?? null);
            }
            $outcomes[] = new Outcome('bucketing/bucket-sha256-v1', "#$index $salt/$kind", $failures, ['bucket' => $bucket]);
        }

        return $outcomes;
    }

    /**
     * @return list<Outcome> one outcome per canonical vector
     */
    public function runCanonicalVectors(): array
    {
        $outcomes = [];
        foreach ($this->listJson($this->path('fixtures/canonical')) as $relative) {
            $vector = $this->readJson($this->path('fixtures/canonical/' . $relative));
            $failures = [];
            try {
                $canonical = CanonicalJson::encode($vector instanceof stdClass ? ($vector->input ?? null) : null);
            } catch (Throwable $e) {
                $failures[] = 'cannot canonicalize: ' . $e->getMessage();
                $canonical = '';
            }
            if ($vector instanceof stdClass) {
                if ($canonical !== ($vector->canonical ?? null)) {
                    $failures[] = 'canonical form is ' . $canonical;
                }
                if ('sha256:' . hash('sha256', $canonical) !== ($vector->checksum ?? null)) {
                    $failures[] = 'checksum differs';
                }
            }
            $outcomes[] = new Outcome('canonical', $relative, $failures, ['canonical' => $canonical]);
        }

        return $outcomes;
    }

    /**
     * Runs everything and returns a conformance report in the format of the protocol's conformance specification.
     *
     * @return array{protocol: string, evaluator: array{name: string, version: string}, results: list<array<string, mixed>>, snapshots: list<array<string, mixed>>, failures: list<string>}
     */
    public function report(string $evaluatorName, string $evaluatorVersion): array
    {
        $results = [];
        $snapshots = [];
        $failures = [];

        foreach ($this->evaluationCaseIds() as $id) {
            $outcome = $this->runEvaluationCase($id['fixture'], $id['case']);
            $results[] = ['fixture' => $id['fixture'], 'case' => $id['case']] + ($outcome->actual ?? []);
            if (! $outcome->passed()) {
                $failures[] = $outcome->describe();
            }
        }
        foreach ($this->snapshotFixtureIds() as $id) {
            $outcome = $this->runSnapshotFixture($id);
            $snapshots[] = ['fixture' => $id] + ($outcome->actual ?? []);
            if (! $outcome->passed()) {
                $failures[] = $outcome->describe();
            }
        }
        foreach ([...$this->runBucketVectors(), ...$this->runCanonicalVectors()] as $outcome) {
            if (! $outcome->passed()) {
                $failures[] = $outcome->describe();
            }
        }

        return [
            'protocol' => is_file($this->path('VERSION')) ? trim((string) file_get_contents($this->path('VERSION'))) : 'unversioned',
            'evaluator' => ['name' => $evaluatorName, 'version' => $evaluatorVersion],
            'results' => $results,
            'snapshots' => $snapshots,
            'failures' => $failures,
        ];
    }

    private function evaluate(stdClass $fixture, stdClass $case): Outcome
    {
        $id = $this->string($fixture, 'id');
        $name = $this->string($case, 'name');
        $failures = [];

        $snapshot = $this->snapshotFor($fixture, $case);
        $options = EvaluationOptions::defaults()->at(new DateTimeImmutable($this->string($case, 'evaluatedAt')));
        $caseOptions = $case->options ?? null;
        if ($caseOptions instanceof stdClass) {
            if (($caseOptions->details ?? false) === true) {
                $options = $options->withDetails();
            }
            if (($caseOptions->trace ?? false) === true) {
                $options = $options->withTrace();
            }
        }

        $evaluator = new Evaluator($snapshot, new FixedClock(new DateTimeImmutable('1970-01-01T00:00:00Z')));
        $expect = $case->expect ?? null;
        if (! $expect instanceof stdClass) {
            return new Outcome($id, $name, ['the case has no expect']);
        }

        try {
            $result = $evaluator->evaluate(
                $this->string($case, 'flagKey'),
                Context::fromDecoded($case->context ?? null),
                FlagType::from($this->string($case, 'type')),
                $case->fallback ?? null,
                $options,
            );
        } catch (FallbackTypeError) {
            if (($expect->throws ?? null) !== 'FALLBACK_TYPE_ERROR') {
                $failures[] = 'threw FALLBACK_TYPE_ERROR';
            }

            return new Outcome($id, $name, $failures, ['threw' => 'FALLBACK_TYPE_ERROR']);
        }

        if (isset($expect->throws)) {
            return new Outcome($id, $name, ['expected ' . json_encode($expect->throws) . ' to be thrown'], $this->actual($result));
        }

        $actual = $result->toArray();
        foreach (get_object_vars($expect) as $field => $expected) {
            if (! $this->same($actual[$field] ?? null, $expected)) {
                $failures[] = $field . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual[$field] ?? null);
            }
        }

        $expectedTrace = $case->expectTrace ?? null;
        if (is_array($expectedTrace)) {
            $problem = $this->traceProblem($expectedTrace, $actual['trace'] ?? null);
            if ($problem !== null) {
                $failures[] = 'trace: ' . $problem;
            }
        }

        return new Outcome($id, $name, $failures, $this->actual($result));
    }

    /**
     * @return array<string, mixed> the fields a report compares
     */
    private function actual(EvaluationResult $result): array
    {
        return ['result' => [
            'value' => $result->value,
            'variationKey' => $result->variationKey,
            'reason' => $result->reason->value,
            'ruleId' => $result->ruleId,
            'prerequisiteKey' => $result->prerequisiteKey,
            'errorKind' => $result->errorKind?->value,
            'revision' => $result->revision,
            'generation' => $result->generation,
            'bucket' => $result->bucket,
            'experimentId' => $result->experimentId,
            'allocationId' => $result->allocationId,
        ]];
    }

    /**
     * Each listed step must appear in the trace in order, with every listed field equal; listed conditions are
     * matched by path. Extra steps and fields are allowed.
     *
     * @param  array<array-key, mixed>  $expected
     */
    private function traceProblem(array $expected, mixed $trace): ?string
    {
        $steps = is_array($trace) && is_array($trace['steps'] ?? null) ? array_values($trace['steps']) : [];
        $position = 0;

        foreach ($expected as $want) {
            if (! $want instanceof stdClass) {
                return 'a listed step is not an object';
            }
            $found = false;
            for (; $position < count($steps); $position++) {
                $step = $steps[$position];
                if (is_array($step) && $this->stepMatches($want, $step)) {
                    $found = true;
                    $position++;
                    break;
                }
            }
            if (! $found) {
                return 'no step matches ' . json_encode($want);
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $step
     */
    private function stepMatches(stdClass $want, array $step): bool
    {
        foreach (get_object_vars($want) as $field => $value) {
            if ($field === 'conditions') {
                $have = is_array($step['conditions'] ?? null) ? $step['conditions'] : [];
                foreach (is_array($value) ? $value : [] as $listed) {
                    if (! $listed instanceof stdClass || ! $this->conditionPresent($listed, $have)) {
                        return false;
                    }
                }

                continue;
            }
            if (! $this->same($step[$field] ?? null, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $have
     */
    private function conditionPresent(stdClass $listed, array $have): bool
    {
        foreach ($have as $condition) {
            if (is_array($condition) && ($condition['path'] ?? null) === ($listed->path ?? null)) {
                foreach (get_object_vars($listed) as $field => $value) {
                    if (($condition[$field] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            }
        }

        return false;
    }

    private function parseSnapshot(stdClass $fixture): Outcome
    {
        $id = $this->string($fixture, 'id');
        $expect = $fixture->expect ?? null;
        if (! $expect instanceof stdClass) {
            return new Outcome($id, 'snapshot', ['the fixture has no expect']);
        }

        $text = isset($fixture->snapshotRaw) && is_string($fixture->snapshotRaw)
            ? $fixture->snapshotRaw
            : json_encode($fixture->snapshot ?? null, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $failures = [];
        try {
            SnapshotParser::parse($text);
            $actual = ['accepted' => true, 'errorKind' => null, 'path' => null, 'code' => null];
        } catch (MalformedSnapshot $e) {
            $actual = ['accepted' => false, 'errorKind' => $e->errorKind->value, 'path' => $e->path, 'code' => $e->problem];
        }

        if ($actual['accepted'] !== ($expect->accept ?? null)) {
            $failures[] = $actual['accepted'] ? 'the snapshot was accepted' : 'the snapshot was rejected: ' . json_encode($actual);
        } elseif (! $actual['accepted']) {
            foreach (['errorKind', 'path', 'code'] as $field) {
                if (isset($expect->$field) && $expect->$field !== $actual[$field]) {
                    $failures[] = $field . ': expected ' . json_encode($expect->$field) . ', got ' . json_encode($actual[$field]);
                }
            }
        }

        return new Outcome($id, 'snapshot', $failures, $actual);
    }

    private function snapshotFor(stdClass $fixture, stdClass $case): Snapshot
    {
        $ref = $case->snapshotRef ?? $fixture->snapshotRef ?? null;
        if (is_string($ref)) {
            return $this->snapshots[$ref] ??= SnapshotParser::parse((string) file_get_contents($this->path('fixtures/' . $ref)));
        }

        $key = 'inline:' . $this->string($fixture, 'id');

        return $this->snapshots[$key] ??= SnapshotParser::parse(json_encode($fixture->snapshot ?? null, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** JSON equality; numbers compare numerically, so 20 equals 20.0. */
    private function same(mixed $a, mixed $b): bool
    {
        return CanonicalJson::encode($a) === CanonicalJson::encode($b);
    }

    /**
     * @return list<stdClass>
     */
    private function evaluationFixtures(): array
    {
        if ($this->evaluation !== null) {
            return $this->evaluation;
        }

        $fixtures = [];
        foreach ($this->listJson($this->path('fixtures/evaluation')) as $relative) {
            $fixture = $this->readJson($this->path('fixtures/evaluation/' . $relative));
            if ($fixture instanceof stdClass) {
                $fixtures[] = $fixture;
            }
        }

        return $this->evaluation = $fixtures;
    }

    /**
     * @return list<stdClass>
     */
    private function snapshotFixtures(): array
    {
        if ($this->snapshotLevel !== null) {
            return $this->snapshotLevel;
        }

        $fixtures = [];
        foreach ($this->listJson($this->path('fixtures/snapshot')) as $relative) {
            $fixture = $this->readJson($this->path('fixtures/snapshot/' . $relative));
            if ($fixture instanceof stdClass) {
                $fixtures[] = $fixture;
            }
        }

        return $this->snapshotLevel = $fixtures;
    }

    /**
     * @return list<stdClass>
     */
    private function cases(stdClass $fixture): array
    {
        $cases = $fixture->cases ?? [];

        return is_array($cases) ? array_values(array_filter($cases, static fn(mixed $c): bool => $c instanceof stdClass)) : [];
    }

    /**
     * @return list<string> JSON files below a directory, as paths relative to it, sorted
     */
    private function listJson(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'json') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, '/\\')) + 1));
            }
        }
        sort($files);

        return $files;
    }

    private function readJson(string $file): mixed
    {
        $text = @file_get_contents($file);
        if ($text === false) {
            throw new RuntimeException("Cannot read $file.");
        }

        return JsonDecoder::decode($text, self::BIG, self::DEEP);
    }

    private function path(string $relative): string
    {
        return rtrim($this->releaseDir, '/\\') . '/' . $relative;
    }

    private function string(stdClass $object, string $field): string
    {
        $value = $object->$field ?? null;

        return is_string($value) ? $value : throw new RuntimeException("Expected a string \"$field\" in a fixture.");
    }
}
