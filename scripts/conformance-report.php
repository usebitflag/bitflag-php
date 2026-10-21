#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Writes a conformance report (see the protocol's conformance specification) for the pinned release, or for the
 * release in BITFLAG_PROTOCOL_DIR, to the file given as the first argument or to standard output. The report holds
 * what this evaluator actually did for every case, so that it can be compared with another evaluator's.
 */

require __DIR__ . '/../vendor/autoload.php';

use BitFlag\Testing\Conformance\FixtureRunner;
use BitFlag\Tests\Support\Protocol;

$runner = new FixtureRunner(Protocol::directory());
$report = $runner->report('bitflag-php', Protocol::lock()['version']);
$failures = $report['failures'];
unset($report['failures']);

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (isset($argv[1])) {
    file_put_contents($argv[1], $json);
} else {
    echo $json;
}

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}
exit($failures === [] ? 0 : 1);
