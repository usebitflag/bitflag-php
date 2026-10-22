<?php

declare(strict_types=1);

use BitFlag\Protocol\Json\CanonicalJson;
use BitFlag\Protocol\Json\JsonDecoder;

it('writes the RFC 8785 sample', function () {
    $input = JsonDecoder::decode('{"numbers": [333333333.33333329, 1E30, 4.50, 2e-3, 0.000000000000000000000000001], "string": "\u20ac$\u000F\u000aA\'\u0042\u0022\u005c\\\\\"\/", "literals": [null, true, false]}');

    expect(CanonicalJson::encode($input))->toBe('{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f' . "\\n" . 'A\'B\"\\\\\\\\\"/"}');
});

it('sorts member names by UTF-16 code units', function () {
    $input = JsonDecoder::decode('{"\u20ac":"Euro","\r":"CR","\ufb33":"Dalet","1":"One","\ud83d\ude00":"Emoji","\u0080":"Control","\u00f6":"O"}');

    expect(CanonicalJson::encode($input))->toBe("{\"\\r\":\"CR\",\"1\":\"One\",\"\u{0080}\":\"Control\",\"\u{00F6}\":\"O\",\"\u{20AC}\":\"Euro\",\"\u{1F600}\":\"Emoji\",\"\u{FB33}\":\"Dalet\"}");
});

it('compares member names longer first only after the common prefix', function () {
    expect(CanonicalJson::encode(JsonDecoder::decode('{"ab":1,"a":2,"b":3,"aa":4}')))->toBe('{"a":2,"aa":4,"ab":1,"b":3}');
});

it('writes numbers as ECMAScript does', function (float|int $number, string $expected) {
    expect(CanonicalJson::encode($number))->toBe($expected);
})->with([
    'zero' => [0, '0'],
    'negative zero' => [-0.0, '0'],
    'one' => [1, '1'],
    'minus one' => [-1, '-1'],
    'a hundred' => [100.0, '100'],
    'a half' => [0.5, '0.5'],
    'one tenth' => [0.1, '0.1'],
    'two floats added' => [0.1 + 0.2, '0.30000000000000004'],
    'twenty digits' => [1e20, '100000000000000000000'],
    'exponent 21' => [1e21, '1e+21'],
    'just under exponent 21' => [999999999999999900000.0, '999999999999999900000'],
    'one millionth' => [1e-6, '0.000001'],
    'one ten-millionth' => [1e-7, '1e-7'],
    'small with digits' => [1.5e-7, '1.5e-7'],
    'largest double' => [1.7976931348623157e308, '1.7976931348623157e+308'],
    'smallest double' => [5e-324, '5e-324'],
    'smallest normal' => [2.2250738585072014e-308, '2.2250738585072014e-308'],
    'two to the 53' => [9007199254740992.0, '9007199254740992'],
    'integer beyond 2^53 reads as its double' => [9007199254740993, '9007199254740992'],
    'one digit after the point' => [1424953923781206.2, '1424953923781206.2'],
    'negative fraction' => [-0.0000033333333333333333, '-0.0000033333333333333333'],
    'negative exponent' => [-1.5e-10, '-1.5e-10'],
    'e+23' => [1e23, '1e+23'],
]);

it('escapes strings minimally', function () {
    expect(CanonicalJson::encode("a\"b\\c/d\x08\x09\x0A\x0C\x0D\x00\x1F\x7F\u{2028}"))
        ->toBe("\"a\\\"b\\\\c/d\\b\\t\\n\\f\\r\\u0000\\u001f\x7F\u{2028}\"");
});

it('writes non-ASCII text as UTF-8, not as escapes', function () {
    expect(CanonicalJson::encode("é日本語😀"))->toBe("\"é日本語😀\"");
});

it('keeps list order and nests', function () {
    expect(CanonicalJson::encode(JsonDecoder::decode('[[3,2,1],[{"b":1,"a":2}],"b","a",{}, []]')))->toBe('[[3,2,1],[{"a":2,"b":1}],"b","a",{},[]]');
});

it('writes an associative array as an object and a list as an array', function () {
    expect(CanonicalJson::encode(['b' => 1, 'a' => [1, 2]]))->toBe('{"a":[1,2],"b":1}');
});

it('refuses values without a JSON form', function (mixed $value) {
    CanonicalJson::encode($value);
})->with([[NAN], [INF], [-INF], [new DateTimeImmutable()], ["\xB1"]])->throws(InvalidArgumentException::class);
