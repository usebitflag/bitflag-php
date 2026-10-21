<?php

declare(strict_types=1);

use BitFlag\Evaluation\Internal\Values\Instant;

it('converts valid instants to epoch milliseconds', function (string $text, int $expected) {
    expect(Instant::parse($text))->toBe($expected);
})->with([
    'epoch' => ['1970-01-01T00:00:00Z', 0],
    'one millisecond after' => ['1970-01-01T00:00:00.001Z', 1],
    'one millisecond before' => ['1969-12-31T23:59:59.999Z', -1],
    'pre-epoch fraction truncated by digits' => ['1969-12-31T23:59:59.9999Z', -1],
    'a fraction of 9 digits' => ['1970-01-01T00:00:00.123999999Z', 123],
    'offset east' => ['1970-01-01T08:00:00+08:00', 0],
    'offset west' => ['1969-12-31T19:00:00-05:00', 0],
    'a known date' => ['2026-10-06T08:00:00Z', 1791273600000],
    'leap day' => ['2028-02-29T00:00:00Z', 1835395200000],
    'year 2000 leap day' => ['2000-02-29T00:00:00Z', 951782400000],
    'year 0' => ['0000-01-01T00:00:00Z', -62167219200000],
    'year 9999' => ['9999-12-31T23:59:59.999Z', 253402300799999],
    'largest offset' => ['2026-10-06T00:00:00+23:59', 1791158460000],
]);

it('rejects invalid instants', function (string $text) {
    expect(Instant::parse($text))->toBeNull();
})->with([
    'date only' => '2026-10-06',
    'no offset' => '2026-10-06T08:00:00',
    'lower-case t' => '2026-10-06t08:00:00Z',
    'lower-case z' => '2026-10-06T08:00:00z',
    'space separator' => '2026-10-06 08:00:00Z',
    'leap second' => '2016-12-31T23:59:60Z',
    'hour 24' => '2026-10-06T24:00:00Z',
    'minute 60' => '2026-10-06T08:60:00Z',
    'month 13' => '2026-13-01T00:00:00Z',
    'day 32' => '2026-01-32T00:00:00Z',
    'april 31' => '2026-04-31T00:00:00Z',
    'february 30' => '2026-02-30T00:00:00Z',
    'not a leap year' => '2027-02-29T00:00:00Z',
    'century not a leap year' => '2100-02-29T00:00:00Z',
    'offset hour 24' => '2026-10-06T00:00:00+24:00',
    'offset minute 60' => '2026-10-06T00:00:00+08:60',
    'offset without colon' => '2026-10-06T00:00:00+0800',
    '10 digit fraction' => '2026-10-06T08:00:00.1234567890Z',
    'empty fraction' => '2026-10-06T08:00:00.Z',
    'trailing newline' => "2026-10-06T08:00:00Z\n",
    'leading space' => ' 2026-10-06T08:00:00Z',
    'two-digit year' => '26-10-06T08:00:00Z',
    'empty' => '',
]);

it('can require UTC', function () {
    expect(Instant::parse('2026-10-06T08:00:00Z', true))->toBe(1791273600000)
        ->and(Instant::parse('2026-10-06T08:00:00+00:00', true))->toBeNull();
});

it('agrees with PHP\'s own date handling over many instants', function () {
    mt_srand(8785);
    for ($i = 0; $i < 3000; $i++) {
        $year = mt_rand(1, 9999);
        $month = mt_rand(1, 12);
        $day = mt_rand(1, 28);
        $offsetHours = mt_rand(0, 23);
        $offsetMinutes = mt_rand(0, 59);
        $sign = mt_rand(0, 1) === 0 ? '+' : '-';
        $text = sprintf('%04d-%02d-%02dT%02d:%02d:%02d.%03d%s%02d:%02d', $year, $month, $day, mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59), mt_rand(0, 999), $sign, $offsetHours, $offsetMinutes);

        $expected = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.vP', $text);
        expect($expected)->not->toBeFalse();
        assert($expected instanceof DateTimeImmutable);

        expect(Instant::parse($text))->toBe(((int) $expected->format('U')) * 1000 + (int) $expected->format('v'), $text);
    }
});
