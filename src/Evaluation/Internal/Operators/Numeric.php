<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Operators;

use BitFlag\Protocol\Model\Operator;

/**
 * gt, gte, lt and lte on finite numbers.
 *
 * @internal
 */
final class Numeric
{
    public static function compare(Operator $operator, float $item, float $value): bool
    {
        return match ($operator) {
            Operator::Gt => $item > $value,
            Operator::Gte => $item >= $value,
            Operator::Lt => $item < $value,
            default => $item <= $value,
        };
    }
}
