<?php

declare(strict_types=1);

use BitFlag\Evaluation\Internal\Values\SemVer;

function semver(string $text): SemVer
{
    return SemVer::parse($text) ?? throw new RuntimeException("Not a valid SemVer: $text");
}

it('orders versions by SemVer 2.0.0 precedence', function () {
    $ordered = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.1.0', '2.0.0', '2.1.0', '2.1.1', '10.0.0'];

    foreach ($ordered as $i => $a) {
        foreach ($ordered as $j => $b) {
            expect(semver($a)->compare(semver($b)) <=> 0)->toBe($i <=> $j, "$a vs $b");
        }
    }
});

it('compares numeric parts as numbers', function () {
    expect(semver('4.10.0')->compare(semver('4.9.0')))->toBeGreaterThan(0)
        ->and(semver('9007199254740991.0.0')->compare(semver('9007199254740990.0.0')))->toBeGreaterThan(0);
});

it('ignores build metadata', function () {
    expect(semver('1.0.0+a')->compare(semver('1.0.0+b')))->toBe(0)
        ->and(semver('1.0.0-rc.1+x')->compare(semver('1.0.0-rc.1')))->toBe(0);
});

it('orders upper case before lower case in pre-release identifiers', function () {
    expect(semver('1.0.0-Beta')->compare(semver('1.0.0-beta')))->toBeLessThan(0);
});

it('ranks numeric identifiers below alphanumeric ones', function () {
    expect(semver('1.0.0-99')->compare(semver('1.0.0-0a')))->toBeLessThan(0);
});

it('rejects what is not a version', function (string $text) {
    expect(SemVer::parse($text))->toBeNull();
})->with([
    'two parts' => '4.2', 'one part' => '4', 'four parts' => '4.2.0.1', 'trailing dot' => '4.2.0.',
    'v prefix' => 'v4.2.0', 'upper V' => 'V4.2.0', 'leading zero major' => '04.2.0', 'leading zero minor' => '4.02.0',
    'leading zero patch' => '4.2.00', 'numeric pre-release with a zero' => '4.2.0-01', 'empty pre-release' => '1.0.0-',
    'empty pre-release identifier' => '1.0.0-a..b', 'empty build' => '1.0.0+', 'empty build identifier' => '1.0.0+a..b',
    'leading space' => ' 4.2.0', 'trailing space' => '4.2.0 ', 'trailing newline' => "4.2.0\n", 'underscore' => '4.2.0-rc_1',
    'non-ASCII digit' => "4.2.\u{0663}", 'plus sign' => '+4.2.0', 'negative' => '-1.2.3', 'accent' => "4.2.0-b\u{00E9}ta",
    'beyond the safe range' => '9007199254740992.0.0', 'far beyond' => '99999999999999999999.0.0',
    'over 256 bytes' => '1.0.0-' . str_repeat('a', 251),
    'pre-release beyond the safe range' => '1.0.0-9007199254740992', 'empty' => '', 'text' => 'latest',
]);

it('accepts valid forms', function (string $text) {
    expect(SemVer::parse($text))->not->toBeNull();
})->with(['0.0.0', '1.2.3', '1.0.0-0', '1.0.0-0a', '1.0.0-alpha-1', '1.0.0+build.5', '1.0.0-rc.1+sha.5114f85', '9007199254740991.9007199254740991.9007199254740991', '1.0.0-9007199254740991', '256 bytes' => '1.0.0-' . str_repeat('a', 250)]);
