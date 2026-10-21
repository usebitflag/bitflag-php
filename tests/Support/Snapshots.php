<?php

declare(strict_types=1);

namespace BitFlag\Tests\Support;

use BitFlag\Protocol\Checksum;
use BitFlag\Protocol\Json\JsonDecoder;
use stdClass;

/**
 * Builds snapshot JSON for tests. Plain PHP arrays become JSON; use (object) [] for an empty object.
 */
final class Snapshots
{
    /**
     * @return array<string, mixed>
     */
    public static function base(): array
    {
        return [
            'protocolVersion' => 1,
            'algorithmVersion' => 'bucket-sha256-v1',
            'environment' => ['id' => 'env_1', 'key' => 'production'],
            'revision' => 42,
            'generation' => 1,
            'projection' => 'server',
            'publishedAt' => '2026-10-06T07:00:00Z',
            'requiredCapabilities' => [],
            'flags' => [
                'checkout' => self::flag(),
            ],
            'segments' => (object) [],
            'checksum' => 'sha256:' . str_repeat('0', 64),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function flag(array $overrides = []): array
    {
        return array_replace([
            'type' => 'boolean',
            'enabled' => true,
            'variations' => ['on' => true, 'off' => false],
            'offVariation' => 'off',
            'defaultVariation' => 'off',
            'prerequisites' => [],
            'overrides' => [],
            'rules' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function json(array $snapshot, bool $withChecksum = true): string
    {
        $text = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! $withChecksum) {
            return $text;
        }

        $decoded = JsonDecoder::decode($text);
        assert($decoded instanceof stdClass);
        $snapshot['checksum'] = Checksum::compute($decoded);

        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>|null  $mutate
     */
    public static function valid(?callable $mutate = null): string
    {
        $snapshot = self::base();
        if ($mutate !== null) {
            $snapshot = $mutate($snapshot);
        }

        return self::json($snapshot);
    }
}
