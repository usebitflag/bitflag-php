<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal\Values;

/**
 * A SemVer 2.0.0 version with its precedence rules. Build metadata is ignored.
 *
 * @internal
 */
final readonly class SemVer
{
    private const NUMBER = '(?:0|[1-9]\d*)';

    private const IDENTIFIER = '(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)';

    private const MAX_SAFE = '9007199254740991';

    /**
     * @param  list<string>  $prerelease
     */
    private function __construct(
        private int $major,
        private int $minor,
        private int $patch,
        private array $prerelease,
    ) {}

    /** Longest SemVer string, in bytes, that is valid. */
    private const MAX_LENGTH = 256;

    public static function parse(string $text): ?self
    {
        if (strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        $pattern = '/^(' . self::NUMBER . ')\.(' . self::NUMBER . ')\.(' . self::NUMBER . ')'
            . '(?:-(' . self::IDENTIFIER . '(?:\.' . self::IDENTIFIER . ')*))?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $numbers = [$m[1], $m[2], $m[3]];
        $prerelease = ($m[4] ?? '') === '' ? [] : explode('.', $m[4]);
        foreach ([...$numbers, ...array_filter($prerelease, self::isNumeric(...))] as $number) {
            if (! self::withinSafeRange($number)) {
                return null;
            }
        }

        return new self((int) $numbers[0], (int) $numbers[1], (int) $numbers[2], $prerelease);
    }

    /** Negative, zero or positive as this version has lower, equal or higher precedence. */
    public function compare(self $other): int
    {
        foreach ([[$this->major, $other->major], [$this->minor, $other->minor], [$this->patch, $other->patch]] as [$a, $b]) {
            if ($a !== $b) {
                return $a <=> $b;
            }
        }

        // A version with a pre-release has lower precedence than the same version without one.
        if ($this->prerelease === [] || $other->prerelease === []) {
            return count($other->prerelease) <=> count($this->prerelease);
        }

        $common = min(count($this->prerelease), count($other->prerelease));
        for ($i = 0; $i < $common; $i++) {
            $a = $this->prerelease[$i];
            $b = $other->prerelease[$i];
            if ($a === $b) {
                continue;
            }
            $aNumeric = self::isNumeric($a);
            $bNumeric = self::isNumeric($b);
            if ($aNumeric && $bNumeric) {
                return (int) $a <=> (int) $b;
            }
            if ($aNumeric !== $bNumeric) {
                return $aNumeric ? -1 : 1;
            }

            return strcmp($a, $b) <=> 0;
        }

        return count($this->prerelease) <=> count($other->prerelease);
    }

    private static function isNumeric(string $identifier): bool
    {
        return preg_match('/^\d+$/D', $identifier) === 1;
    }

    private static function withinSafeRange(string $number): bool
    {
        return strlen($number) < strlen(self::MAX_SAFE) || (strlen($number) === strlen(self::MAX_SAFE) && $number <= self::MAX_SAFE);
    }
}
