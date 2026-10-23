<?php

declare(strict_types=1);

use BitFlag\Tests\Support\Protocol;

/**
 * @return array<string, mixed>
 */
function composerJson(): array
{
    $json = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($json));

    return $json;
}

it('requires nothing but php at runtime', function () {
    $require = composerJson()['require'] ?? [];

    expect(array_keys(is_array($require) ? $require : []))->toBe(['php']);
});

it('pins the protocol release by checksum once it is published', function () {
    $sha = Protocol::lock()['sha256'];

    expect($sha === null || preg_match('/^[0-9a-f]{64}$/', $sha) === 1)->toBeTrue()
        ->and(Protocol::lock()['asset'])->toBe('bitflag-protocol-' . Protocol::lock()['version'] . '.tar.gz');
});
