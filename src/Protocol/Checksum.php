<?php

declare(strict_types=1);

namespace BitFlag\Protocol;

use BitFlag\Protocol\Json\CanonicalJson;
use stdClass;

/**
 * The checksum of a snapshot: "sha256:" and the SHA-256 of the canonical form of every member except the
 * top-level "checksum".
 */
final class Checksum
{
    public static function compute(stdClass $snapshot): string
    {
        $members = get_object_vars($snapshot);
        unset($members['checksum']);

        return 'sha256:' . hash('sha256', CanonicalJson::encode($members));
    }

    /**
     * @throws MalformedSnapshot with CHECKSUM_MISMATCH
     */
    public static function verify(stdClass $snapshot): void
    {
        $stated = $snapshot->checksum ?? null;
        if (! is_string($stated) || ! hash_equals(self::compute($snapshot), $stated)) {
            throw MalformedSnapshot::malformed('/checksum', Problem::CHECKSUM_MISMATCH);
        }
    }
}
