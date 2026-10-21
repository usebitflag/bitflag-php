<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Bucketing;

/**
 * The stable rollout algorithm bucket-sha256-v1: SHA-256 over length-prefixed fields, the first eight digest
 * bytes as an unsigned big-endian integer, modulo 100,000.
 *
 * PHP has no unsigned 64-bit integer, so the value is built from two 32-bit halves:
 * bucket = ((hi mod 100000) * (2^32 mod 100000) + lo) mod 100000, where 2^32 mod 100000 is 67296. Every
 * intermediate fits in a signed 64-bit integer.
 */
final class BucketSha256V1
{
    public const ALGORITHM = 'bucket-sha256-v1';

    public const BUCKET_COUNT = 100_000;

    private const DOMAIN_TAG = 'bitflag-bucket-v1';

    /** 2^32 mod 100000 */
    private const TWO_POW_32_MOD = 67296;

    /**
     * @return int 0 to 99,999
     */
    public static function bucket(string $salt, string $kind, string $value): int
    {
        $input = self::field(self::DOMAIN_TAG) . self::field($salt) . self::field($kind) . self::field($value);
        $digest = hash('sha256', $input, true);

        $high = self::word($digest, 0);
        $low = self::word($digest, 4);

        return (($high % self::BUCKET_COUNT) * self::TWO_POW_32_MOD + $low) % self::BUCKET_COUNT;
    }

    /** The bytes that are hashed: the four fields, each prefixed with its byte length. */
    public static function input(string $salt, string $kind, string $value): string
    {
        return self::field(self::DOMAIN_TAG) . self::field($salt) . self::field($kind) . self::field($value);
    }

    private static function field(string $text): string
    {
        return pack('N', strlen($text)) . $text;
    }

    private static function word(string $digest, int $offset): int
    {
        $word = unpack('N', $digest, $offset);

        return is_array($word) && is_int($word[1]) ? $word[1] : 0;
    }
}
