<?php

declare(strict_types=1);

namespace BitFlag\Tests\Support;

use BitFlag\Testing\Conformance\FixtureRunner;
use RuntimeException;

/**
 * Finds the pinned protocol release for the conformance suite.
 */
final class Protocol
{
    private static ?FixtureRunner $runner = null;

    public static function directory(): string
    {
        $override = getenv('BITFLAG_PROTOCOL_DIR');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return __DIR__ . '/../../build/protocol/' . self::lock()['version'];
    }

    public static function runner(): FixtureRunner
    {
        $directory = self::directory();
        if (! is_file($directory . '/fixtures/manifest.json')) {
            throw new RuntimeException("The protocol fixtures are missing at $directory. Run scripts/fetch-protocol-fixtures.sh, or set BITFLAG_PROTOCOL_DIR to an extracted release.");
        }

        return self::$runner ??= new FixtureRunner($directory);
    }

    /**
     * @return array{repository: string, version: string, asset: string, sha256: string|null}
     */
    public static function lock(): array
    {
        $lock = json_decode((string) file_get_contents(__DIR__ . '/../../protocol.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($lock) || ! is_string($lock['repository'] ?? null) || ! is_string($lock['version'] ?? null) || ! is_string($lock['asset'] ?? null)) {
            throw new RuntimeException('protocol.lock.json is malformed.');
        }
        $sha = $lock['sha256'] ?? null;

        return ['repository' => $lock['repository'], 'version' => $lock['version'], 'asset' => $lock['asset'], 'sha256' => is_string($sha) ? $sha : null];
    }
}
