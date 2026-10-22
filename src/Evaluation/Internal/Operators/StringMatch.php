<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Operators;

use BitFlag\Protocol\Model\Operator;

/**
 * starts_with, ends_with and contains: byte-wise on UTF-8. ignoreCase folds the ASCII letters A to Z only.
 *
 * @internal
 */
final class StringMatch
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * @param  list<string|int|float|bool>  $values
     */
    public static function matches(Operator $operator, string $item, array $values, bool $ignoreCase): bool
    {
        if ($ignoreCase) {
            $item = self::fold($item);
        }

        foreach ($values as $value) {
            $needle = $ignoreCase ? self::fold((string) $value) : (string) $value;
            $hit = match ($operator) {
                Operator::StartsWith => str_starts_with($item, $needle),
                Operator::EndsWith => str_ends_with($item, $needle),
                default => str_contains($item, $needle),
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    public static function fold(string $text): string
    {
        return strtr($text, self::UPPER, self::LOWER);
    }
}
