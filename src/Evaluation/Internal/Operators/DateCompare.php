<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Operators;

use BitFlag\Protocol\Model\Operator;

/**
 * before and after on instants in epoch milliseconds. Equal instants match neither.
 *
 * @internal
 */
final class DateCompare
{
    public static function compare(Operator $operator, int $item, int $value): bool
    {
        return $operator === Operator::Before ? $item < $value : $item > $value;
    }
}
