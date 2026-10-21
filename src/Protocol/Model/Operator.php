<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

enum Operator: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case In = 'in';
    case NotIn = 'not_in';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Contains = 'contains';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case Exists = 'exists';
    case NotExists = 'not_exists';
    case SemverEq = 'semver_eq';
    case SemverNeq = 'semver_neq';
    case SemverGt = 'semver_gt';
    case SemverGte = 'semver_gte';
    case SemverLt = 'semver_lt';
    case SemverLte = 'semver_lte';
    case Before = 'before';
    case After = 'after';
    case InSegment = 'in_segment';
    case NotInSegment = 'not_in_segment';

    public function isSegment(): bool
    {
        return $this === self::InSegment || $this === self::NotInSegment;
    }

    public function isExistence(): bool
    {
        return $this === self::Exists || $this === self::NotExists;
    }

    public function isSemver(): bool
    {
        return in_array($this, [self::SemverEq, self::SemverNeq, self::SemverGt, self::SemverGte, self::SemverLt, self::SemverLte], true);
    }

    public function isDate(): bool
    {
        return $this === self::Before || $this === self::After;
    }

    public function isNumeric(): bool
    {
        return in_array($this, [self::Gt, self::Gte, self::Lt, self::Lte], true);
    }

    public function isStringMatch(): bool
    {
        return in_array($this, [self::StartsWith, self::EndsWith, self::Contains], true);
    }

    /** Operators that are the negation of a positive form and need a usable value to say anything. */
    public function isNegated(): bool
    {
        return in_array($this, [self::Neq, self::NotIn, self::SemverNeq], true);
    }
}
