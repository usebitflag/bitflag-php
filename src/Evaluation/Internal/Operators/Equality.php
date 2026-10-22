<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Operators;

/**
 * eq, neq, in and not_in: strict equality within one type family. Numbers compare numerically.
 *
 * @internal
 */
final class Equality
{
    public static function equals(string|int|float|bool $item, string|int|float|bool $value): bool
    {
        if (is_string($item) || is_bool($item)) {
            return $item === $value;
        }

        return is_int($value) || is_float($value) ? (float) $item === (float) $value : false;
    }

    /**
     * @param  list<string|int|float|bool>  $values
     */
    public static function anyEqual(string|int|float|bool $item, array $values): bool
    {
        foreach ($values as $value) {
            if (self::equals($item, $value)) {
                return true;
            }
        }

        return false;
    }
}
