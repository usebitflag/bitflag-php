<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Values;

/**
 * The type family of a context attribute value, as operators see it.
 *
 * @internal
 */
final class TypedValue
{
    public const STRING = 'string';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    /** An integer value outside ±(2^53 - 1): a number no operator accepts. */
    public const UNSAFE = 'unsafe';

    public const OTHER = 'other';

    private const MAX_SAFE = 9007199254740991;

    public static function family(mixed $value): string
    {
        if (is_string($value)) {
            return self::STRING;
        }
        if (is_bool($value)) {
            return self::BOOLEAN;
        }
        if (is_int($value)) {
            return abs($value) > self::MAX_SAFE ? self::UNSAFE : self::NUMBER;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                return self::OTHER;
            }

            return floor($value) === $value && abs($value) > self::MAX_SAFE ? self::UNSAFE : self::NUMBER;
        }

        return self::OTHER;
    }
}
