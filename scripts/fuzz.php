#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * The harness of the protocol's differential fuzz test: reads the input written by the protocol's
 * tools/fuzz/generate.mjs (first argument) and writes what this evaluator did with it (second argument). The
 * format is described in the protocol's conformance specification.
 */

require __DIR__ . '/../vendor/autoload.php';

use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\EvaluationOptions;
use BitFlag\Evaluation\Evaluator;
use BitFlag\Evaluation\Exception\FallbackTypeError;
use BitFlag\Evaluation\FixedClock;
use BitFlag\Protocol\Json\CanonicalJson;
use BitFlag\Protocol\Json\JsonDecoder;
use BitFlag\Protocol\MalformedSnapshot;
use BitFlag\Protocol\Model\FlagType;
use BitFlag\Protocol\SnapshotParser;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/fuzz.php <input.json> <output.json>\n");
    exit(2);
}

$input = JsonDecoder::decode((string) file_get_contents($argv[1]), 1 << 30, 10000);
$output = ['evaluator' => 'bitflag-php', 'parse' => [], 'evaluate' => []];

$options = static function (object $case): EvaluationOptions {
    $options = EvaluationOptions::defaults()->at(new DateTimeImmutable($case->at));
    if ($case->details) {
        $options = $options->withDetails();
    }
    if ($case->trace) {
        $options = $options->withTrace();
    }

    return $options;
};

foreach ($input->parse as $item) {
    try {
        SnapshotParser::parse($item->text);
        $output['parse'][$item->id] = ['accepted' => true];
    } catch (MalformedSnapshot $e) {
        $output['parse'][$item->id] = ['accepted' => false, 'errorKind' => $e->errorKind->value, 'path' => $e->path, 'code' => $e->problem];
    } catch (Throwable $e) {
        $output['parse'][$item->id] = ['accepted' => false, 'thrown' => $e::class];
    }
}

foreach ($input->evaluate as $item) {
    try {
        $snapshot = SnapshotParser::parse($item->snapshotText);
    } catch (MalformedSnapshot $e) {
        $output['evaluate'][$item->id] = ['rejected' => ['errorKind' => $e->errorKind->value, 'path' => $e->path, 'code' => $e->problem]];

        continue;
    }

    $evaluator = new Evaluator($snapshot, new FixedClock(new DateTimeImmutable('1970-01-01T00:00:00Z')));
    $results = [];
    foreach ($item->cases as $case) {
        try {
            $result = $evaluator->evaluate($case->flagKey, Context::fromDecoded($case->context), FlagType::from($case->type), $case->fallback, $options($case));
            $results[] = CanonicalJson::encode($result->toArray());
        } catch (FallbackTypeError) {
            $results[] = 'THROWS FallbackTypeError';
        } catch (Throwable $e) {
            $results[] = 'CRASH ' . $e::class . ': ' . $e->getMessage();
        }
    }
    foreach ($item->bulk as $bulk) {
        try {
            $results[] = CanonicalJson::encode($evaluator->evaluateAll(Context::fromDecoded($bulk->context), $options($bulk))->toArray());
        } catch (Throwable $e) {
            $results[] = 'CRASH ' . $e::class . ': ' . $e->getMessage();
        }
    }
    $output['evaluate'][$item->id] = $results;
}

file_put_contents($argv[2], json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
printf("%d snapshot texts and %d generated snapshots processed\n", count($output['parse']), count($output['evaluate']));
