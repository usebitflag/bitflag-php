<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Json;

use InvalidArgumentException;
use stdClass;

/**
 * Canonical JSON as defined by RFC 8785 (JSON Canonicalization Scheme).
 *
 * Accepts what JsonDecoder produces (stdClass objects, lists, scalars). An associative array is written as an
 * object and a list as an array; use an empty stdClass for an empty object, since an empty array is a list.
 */
final class CanonicalJson
{
    /** Escapes for the control characters and the two characters that need a backslash. */
    private const ESCAPES = [
        '"' => '\\"',
        '\\' => '\\\\',
        "\x08" => '\\b',
        "\x09" => '\\t',
        "\x0A" => '\\n',
        "\x0C" => '\\f',
        "\x0D" => '\\r',
    ];

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return self::number((float) $value);
        }
        if (is_float($value)) {
            return self::number($value);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof stdClass) {
            return self::object(get_object_vars($value));
        }
        if (is_array($value)) {
            return array_is_list($value) ? self::list($value) : self::object($value);
        }

        throw new InvalidArgumentException('Value has no JSON form: ' . get_debug_type($value));
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    private static function list(array $items): string
    {
        return '[' . implode(',', array_map(self::encode(...), $items)) . ']';
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private static function object(array $members): string
    {
        $keys = array_map(strval(...), array_keys($members));
        usort($keys, self::compareKeys(...));

        $parts = [];
        foreach ($keys as $key) {
            // A numeric string key came back from PHP as an int; both read the same way.
            $parts[] = self::string($key) . ':' . self::encode($members[$key]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    private static function string(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('String is not valid UTF-8.');
        }

        $out = strtr($value, self::ESCAPES);

        return '"' . preg_replace_callback('/[\x00-\x1F]/', static fn(array $m): string => sprintf('\\u%04x', ord($m[0])), $out) . '"';
    }

    /**
     * ECMAScript Number::toString for a finite binary64 value.
     */
    public static function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Only finite numbers have a JSON form.');
        }
        if ($value === 0.0) {
            return '0';
        }

        $sign = $value < 0 ? '-' : '';
        [$digits, $exponent] = self::shortestDigits(abs($value));
        $k = strlen($digits);
        $n = $exponent + 1; // the decimal point sits after the first $n digits

        if ($k <= $n && $n <= 21) {
            return $sign . $digits . str_repeat('0', $n - $k);
        }
        if (0 < $n && $n <= 21) {
            return $sign . substr($digits, 0, $n) . '.' . substr($digits, $n);
        }
        if (-6 < $n && $n <= 0) {
            return $sign . '0.' . str_repeat('0', -$n) . $digits;
        }

        $e = $n - 1;
        $exp = ($e < 0 ? '-' : '+') . abs($e);

        return $sign . ($k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1)) . 'e' . $exp;
    }

    /**
     * The shortest decimal digits that read back as the same binary64 value, and the base-10 exponent of the
     * first digit.
     *
     * @return array{string, int}
     */
    private static function shortestDigits(float $value): array
    {
        for ($precision = 0; $precision <= 16; $precision++) {
            $text = sprintf('%.' . $precision . 'e', $value);
            if ((float) $text === $value) {
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', $text);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');

        return [$digits === '' ? '0' : $digits, (int) $exponent];
    }

    /**
     * Orders keys by their UTF-16 code units, as the specification requires.
     */
    private static function compareKeys(string $a, string $b): int
    {
        $x = self::utf16($a);
        $y = self::utf16($b);
        $common = min(count($x), count($y));
        for ($i = 0; $i < $common; $i++) {
            if ($x[$i] !== $y[$i]) {
                return $x[$i] <=> $y[$i];
            }
        }

        // PHP compares arrays by length first, which is not the order wanted here.
        return count($x) <=> count($y);
    }

    /**
     * @return list<int>
     */
    private static function utf16(string $value): array
    {
        $units = [];
        $length = strlen($value);
        for ($i = 0; $i < $length;) {
            $byte = ord($value[$i]);
            if ($byte < 0x80) {
                $point = $byte;
                $i += 1;
            } elseif ($byte < 0xE0) {
                $point = (($byte & 0x1F) << 6) | (ord($value[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($byte < 0xF0) {
                $point = (($byte & 0x0F) << 12) | ((ord($value[$i + 1]) & 0x3F) << 6) | (ord($value[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $point = (($byte & 0x07) << 18) | ((ord($value[$i + 1]) & 0x3F) << 12) | ((ord($value[$i + 2]) & 0x3F) << 6) | (ord($value[$i + 3]) & 0x3F);
                $i += 4;
            }

            if ($point >= 0x10000) {
                $point -= 0x10000;
                $units[] = 0xD800 + ($point >> 10);
                $units[] = 0xDC00 + ($point & 0x3FF);
            } else {
                $units[] = $point;
            }
        }

        return $units;
    }
}
