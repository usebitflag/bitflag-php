<?php

declare(strict_types=1);

use BitFlag\Tests\Support\CodeScan;

const FORBIDDEN_CALLS = [
    'file_get_contents', 'file_put_contents', 'fopen', 'fwrite', 'fread', 'file', 'readfile', 'unlink', 'mkdir', 'rmdir', 'glob', 'scandir', 'opendir',
    'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'socket_create', 'gethostbyname',
    'getenv', 'putenv', 'ini_set', 'ini_get', 'set_error_handler', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'eval',
    'random_int', 'random_bytes', 'rand', 'mt_rand', 'mt_srand', 'srand', 'shuffle', 'array_rand', 'str_shuffle', 'uniqid', 'lcg_value',
    'date', 'time', 'microtime', 'hrtime', 'gmdate', 'strtotime', 'mktime', 'sleep', 'usleep',
];

const FORBIDDEN_VARIABLES = ['$_SERVER', '$_ENV', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_REQUEST', '$_SESSION', '$GLOBALS'];

it('performs no I/O, reads no environment and uses no randomness or wall clock in evaluation and protocol', function () {
    $violations = [];
    foreach (CodeScan::files([__DIR__ . '/../../src/Evaluation', __DIR__ . '/../../src/Protocol']) as $file) {
        if (str_ends_with($file, 'SystemClock.php')) {
            continue; // the one place that reads the clock
        }
        foreach (CodeScan::calls($file) as $call) {
            if (in_array($call, FORBIDDEN_CALLS, true) || in_array($call, FORBIDDEN_VARIABLES, true)) {
                $violations[] = basename($file) . ': ' . $call;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('notices a forbidden call when there is one', function () {
    $file = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($file, "<?php\nfunction f() { \$x = file_get_contents('a'); \$this->time(); Foo::rand(); return \\mt_rand() + \$_SERVER['x']; }\n");

    $calls = CodeScan::calls($file);
    unlink($file);

    expect($calls)->toContain('file_get_contents')->toContain('mt_rand')->toContain('$_SERVER')->not->toContain('time')->not->toContain('rand')->not->toContain('f');
});

arch('internal classes are final')
    ->expect(['BitFlag\\Evaluation\\Internal', 'BitFlag\\Protocol\\Internal'])
    ->classes->toBeFinal();

arch('protocol models and results are immutable')
    ->expect(['BitFlag\\Protocol\\Model', 'BitFlag\\Evaluation\\EvaluationResult', 'BitFlag\\Evaluation\\AllFlagsResult', 'BitFlag\\Evaluation\\EvaluationOptions', 'BitFlag\\Evaluation\\Trace', 'BitFlag\\Protocol\\ValidationIssue'])
    ->classes->toBeReadonly();

arch('the evaluator and the parser are not extensible')
    ->expect(['BitFlag\\Evaluation\\Evaluator', 'BitFlag\\Protocol\\SnapshotParser', 'BitFlag\\Protocol\\SnapshotValidator', 'BitFlag\\Evaluation\\Context'])
    ->toBeFinal();

it('marks every class in an Internal namespace with @internal', function () {
    $missing = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/Internal/') && str_ends_with($path, '.php') && ! str_contains((string) file_get_contents($path), '@internal')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});
