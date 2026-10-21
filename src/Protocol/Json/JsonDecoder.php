<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Json;

use BitFlag\Protocol\MalformedSnapshot;
use BitFlag\Protocol\Problem;
use JsonException;
use stdClass;

/**
 * Strict JSON decoding. Objects become stdClass so that an empty object stays distinguishable from an empty list;
 * duplicate members keep the last value, as in JavaScript.
 */
final class JsonDecoder
{
    /** The hard ceiling for a snapshot; publication sets a lower limit. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Levels of arrays and objects. */
    public const MAX_DEPTH = 64;

    /** The largest integer both PHP and JavaScript represent exactly. */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @param  int<0, max>  $maxBytes
     * @param  int<0, max>  $maxDepth
     *
     * @throws MalformedSnapshot with INVALID_JSON, TOO_DEEP or TOO_LARGE
     */
    public static function decode(string $json, int $maxBytes = self::MAX_BYTES, int $maxDepth = self::MAX_DEPTH): mixed
    {
        if (strlen($json) > $maxBytes) {
            throw MalformedSnapshot::malformed('', Problem::TOO_LARGE);
        }

        try {
            // PHP counts the level of the scalars inside the innermost container, so allow one more.
            return json_decode($json, false, $maxDepth + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw MalformedSnapshot::malformed('', $e->getCode() === JSON_ERROR_DEPTH ? Problem::TOO_DEEP : Problem::INVALID_JSON, $e->getMessage());
        }
    }

    /**
     * Whether a decoded number is not a safe number: an integer value outside ±(2^53 - 1), however it was written,
     * or a literal too large for binary64 (which decodes to infinity).
     */
    public static function isUnsafeNumber(int|float $number): bool
    {
        if (is_int($number)) {
            return $number > self::MAX_SAFE_INTEGER || $number < -self::MAX_SAFE_INTEGER;
        }

        return ! is_finite($number) || (floor($number) === $number && abs($number) > self::MAX_SAFE_INTEGER);
    }

    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass;
    }
}
