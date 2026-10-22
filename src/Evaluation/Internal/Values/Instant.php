<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Values;

/**
 * RFC 3339 instants as epoch milliseconds, computed with integer arithmetic so that every language agrees
 * without a date library.
 *
 * @internal
 */
final class Instant
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/D';

    /**
     * Epoch milliseconds of a valid instant, or null. The fraction is truncated to milliseconds by its digits.
     */
    public static function parse(string $text, bool $utcOnly = false): ?int
    {
        if (preg_match(self::PATTERN, $text, $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) $m[4];
        $minute = (int) $m[5];
        $second = (int) $m[6];
        if ($month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return null;
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $offsetMinutes = 0;
        if ($m[8] !== 'Z') {
            if ($utcOnly) {
                return null;
            }
            $offsetHours = (int) substr($m[8], 1, 2);
            $offsetMins = (int) substr($m[8], 4, 2);
            if ($offsetHours > 23 || $offsetMins > 59) {
                return null;
            }
            $offsetMinutes = ($m[8][0] === '-' ? -1 : 1) * ($offsetHours * 60 + $offsetMins);
        }

        $millis = (int) substr(str_pad($m[7], 3, '0'), 0, 3);
        $seconds = ((self::daysFromCivil($year, $month, $day) * 24 + $hour) * 60 + $minute - $offsetMinutes) * 60 + $second;

        return $seconds * 1000 + $millis;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            return (($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0) ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /**
     * Days since 1970-01-01 in the proleptic Gregorian calendar (Howard Hinnant's algorithm).
     */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $y = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yearOfEra = $y - $era * 400;
        $dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;

        return $era * 146097 + $dayOfEra - 719468;
    }
}
