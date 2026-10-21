<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Operators;

use BitFlag\Evaluation\Internal\Values\SemVer;
use BitFlag\Protocol\Model\Operator;

/**
 * The semver_* operators, by SemVer 2.0.0 precedence.
 *
 * @internal
 */
final class SemverCompare
{
    public static function compare(Operator $operator, SemVer $item, SemVer $value): bool
    {
        $order = $item->compare($value);

        return match ($operator) {
            Operator::SemverEq, Operator::SemverNeq => $order === 0,
            Operator::SemverGt => $order > 0,
            Operator::SemverGte => $order >= 0,
            Operator::SemverLt => $order < 0,
            default => $order <= 0,
        };
    }
}
