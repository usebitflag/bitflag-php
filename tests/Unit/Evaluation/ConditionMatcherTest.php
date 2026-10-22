<?php

declare(strict_types=1);

use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\Internal\Abort;
use BitFlag\Evaluation\Internal\ConditionMatcher;
use BitFlag\Evaluation\Internal\Operators\StringMatch;
use BitFlag\Evaluation\Trace\ConditionOutcome as O;
use BitFlag\Evaluation\Trace\ConditionTrace;
use BitFlag\Protocol\Model\Condition;
use BitFlag\Protocol\Model\ConditionGroup;
use BitFlag\Protocol\Model\GroupKind;
use BitFlag\Protocol\Model\Operator;

/**
 * @param  list<string|int|float|bool>  $values
 */
function cond(string $op, ?string $attribute, array $values = [], bool $ignoreCase = false): Condition
{
    return new Condition(Operator::from($op), $attribute, $values, $ignoreCase);
}

/**
 * @param  array<string, mixed>|null  $attributes  null for a context without the attribute
 */
function outcomeOf(Condition $condition, ?array $attributes, ?string $key = 'u_1'): O
{
    $context = new Context('user', $key, $attributes ?? []);
    $matcher = new ConditionMatcher($context, static fn(string $segment): bool => $segment === 'beta');

    return $matcher->outcome($condition);
}

it('decides equality strictly within a type', function (Condition $condition, mixed $value, O $expected) {
    expect(outcomeOf($condition, ['v' => $value]))->toBe($expected);
})->with([
    'eq string' => [cond('eq', 'v', ['PH']), 'PH', O::Match],
    'eq string differs in case' => [cond('eq', 'v', ['PH']), 'ph', O::NoMatch],
    'eq number as float' => [cond('eq', 'v', [20]), 20.0, O::Match],
    'eq number is not a numeral' => [cond('eq', 'v', [20]), '20', O::WrongType],
    'eq numeral is not a number' => [cond('eq', 'v', ['20']), 20, O::WrongType],
    'eq boolean' => [cond('eq', 'v', [true]), true, O::Match],
    'eq boolean false' => [cond('eq', 'v', [true]), false, O::NoMatch],
    'eq boolean is not 1' => [cond('eq', 'v', [true]), 1, O::WrongType],
    'eq negative zero' => [cond('eq', 'v', [0]), -0.0, O::Match],
    'eq unsafe integer' => [cond('eq', 'v', [9007199254740991]), 9007199254740993, O::WrongType],
    'eq array any' => [cond('eq', 'v', ['PH']), ['US', 'PH'], O::Match],
    'eq array none' => [cond('eq', 'v', ['PH']), ['US'], O::NoMatch],
    'eq empty array' => [cond('eq', 'v', ['PH']), [], O::NoMatch],
    'neq differs' => [cond('neq', 'v', ['PH']), 'US', O::Match],
    'neq equal' => [cond('neq', 'v', ['PH']), 'PH', O::NoMatch],
    'neq wrong type' => [cond('neq', 'v', ['PH']), 5, O::WrongType],
    'neq array none equal' => [cond('neq', 'v', ['PH']), ['US', 'DE'], O::Match],
    'neq array one equal' => [cond('neq', 'v', ['PH']), ['US', 'PH'], O::NoMatch],
    'neq empty array' => [cond('neq', 'v', ['PH']), [], O::Match],
    'in listed' => [cond('in', 'v', ['a', 'b']), 'b', O::Match],
    'in not listed' => [cond('in', 'v', ['a', 'b']), 'c', O::NoMatch],
    'in numbers' => [cond('in', 'v', [1, 2, 3]), 2.0, O::Match],
    'in wrong type' => [cond('in', 'v', ['a']), true, O::WrongType],
    'not_in not listed' => [cond('not_in', 'v', ['a']), 'c', O::Match],
    'not_in listed' => [cond('not_in', 'v', ['a']), 'a', O::NoMatch],
    'not_in empty array' => [cond('not_in', 'v', ['a']), [], O::Match],
    'not_in array with a listed element' => [cond('not_in', 'v', ['a']), ['x', 'a'], O::NoMatch],
]);

it('decides string and numeric operators', function (Condition $condition, mixed $value, O $expected) {
    expect(outcomeOf($condition, ['v' => $value]))->toBe($expected);
})->with([
    'starts_with' => [cond('starts_with', 'v', ['ab']), 'abc', O::Match],
    'starts_with miss' => [cond('starts_with', 'v', ['bc']), 'abc', O::NoMatch],
    'starts_with any value' => [cond('starts_with', 'v', ['x', 'ab']), 'abc', O::Match],
    'starts_with empty value' => [cond('starts_with', 'v', ['']), 'abc', O::Match],
    'ends_with' => [cond('ends_with', 'v', ['bc']), 'abc', O::Match],
    'ends_with longer than the text' => [cond('ends_with', 'v', ['abcd']), 'abc', O::NoMatch],
    'contains' => [cond('contains', 'v', ['b']), 'abc', O::Match],
    'contains miss' => [cond('contains', 'v', ['d']), 'abc', O::NoMatch],
    'contains is case-sensitive' => [cond('contains', 'v', ['B']), 'abc', O::NoMatch],
    'contains ignoring case' => [cond('contains', 'v', ['B'], true), 'abc', O::Match],
    'ignore case does not fold accents' => [cond('contains', 'v', ['é'], true), 'CAFÉ', O::NoMatch],
    'ignore case does not fold the Kelvin sign' => [cond('contains', 'v', ['k'], true), "\u{212A}", O::NoMatch],
    'string operator on a number' => [cond('contains', 'v', ['1']), 1, O::WrongType],
    'string operator on a list of numbers' => [cond('contains', 'v', ['1']), [1], O::WrongType],
    'gt above' => [cond('gt', 'v', [18]), 19, O::Match],
    'gt equal' => [cond('gt', 'v', [18]), 18, O::NoMatch],
    'gte equal' => [cond('gte', 'v', [18]), 18.0, O::Match],
    'lt below' => [cond('lt', 'v', [18]), 17.5, O::Match],
    'lt equal' => [cond('lt', 'v', [18]), 18, O::NoMatch],
    'lte equal' => [cond('lte', 'v', [18]), 18, O::Match],
    'lte above' => [cond('lte', 'v', [18]), 19, O::NoMatch],
    'numeric on a numeral' => [cond('gte', 'v', [18]), '20', O::WrongType],
    'numeric on a boolean' => [cond('gte', 'v', [18]), true, O::WrongType],
    'numeric on an unsafe integer' => [cond('gt', 'v', [1]), 9007199254740993, O::WrongType],
    'numeric on a huge float' => [cond('gt', 'v', [1]), 1e300, O::WrongType],
    'numeric on a safe integral float' => [cond('gt', 'v', [1]), 9007199254740991.0, O::Match],
    'numeric array any' => [cond('gt', 'v', [18]), [3, 20], O::Match],
    'numeric array none' => [cond('gt', 'v', [18]), [3, 4], O::NoMatch],
    'numeric empty array' => [cond('gt', 'v', [18]), [], O::NoMatch],
    'negative -0 below 0' => [cond('lt', 'v', [0]), -0.0, O::NoMatch],
]);

it('decides SemVer and date operators', function (Condition $condition, mixed $value, O $expected) {
    expect(outcomeOf($condition, ['v' => $value]))->toBe($expected);
})->with([
    'semver_eq with build' => [cond('semver_eq', 'v', ['1.0.0']), '1.0.0+x', O::Match],
    'semver_neq equal' => [cond('semver_neq', 'v', ['1.0.0']), '1.0.0+x', O::NoMatch],
    'semver_neq differs' => [cond('semver_neq', 'v', ['1.0.0']), '1.0.1', O::Match],
    'semver_neq invalid' => [cond('semver_neq', 'v', ['1.0.0']), '1.0', O::InvalidValue],
    'semver_gt' => [cond('semver_gt', 'v', ['4.2.0']), '4.10.0', O::Match],
    'semver_gte' => [cond('semver_gte', 'v', ['4.2.0']), '4.2.0', O::Match],
    'semver_lt pre-release' => [cond('semver_lt', 'v', ['4.2.0']), '4.2.0-rc.1', O::Match],
    'semver_lte' => [cond('semver_lte', 'v', ['4.2.0']), '4.2.1', O::NoMatch],
    'semver on a number' => [cond('semver_gt', 'v', ['4.2.0']), 4.2, O::WrongType],
    'semver array with an invalid element' => [cond('semver_eq', 'v', ['1.0.0']), ['1.0.0', 'nope'], O::InvalidValue],
    'semver empty array' => [cond('semver_neq', 'v', ['1.0.0']), [], O::Match],
    'before' => [cond('before', 'v', ['2026-10-06T08:00:00Z']), '2026-10-06T07:59:59Z', O::Match],
    'before equal' => [cond('before', 'v', ['2026-10-06T08:00:00Z']), '2026-10-06T16:00:00+08:00', O::NoMatch],
    'after' => [cond('after', 'v', ['2026-10-06T08:00:00Z']), '2026-10-06T08:00:00.001Z', O::Match],
    'after equal' => [cond('after', 'v', ['2026-10-06T08:00:00Z']), '2026-10-06T08:00:00.000Z', O::NoMatch],
    'date invalid' => [cond('after', 'v', ['2026-10-06T08:00:00Z']), '2026-10-07', O::InvalidValue],
    'date on epoch seconds' => [cond('after', 'v', ['2026-10-06T08:00:00Z']), 1791273601, O::WrongType],
]);

it('treats missing, null and existence', function () {
    $eq = cond('eq', 'v', ['x']);
    $neq = cond('neq', 'v', ['x']);

    expect(outcomeOf($eq, []))->toBe(O::Missing)
        ->and(outcomeOf($neq, []))->toBe(O::Missing)
        ->and(outcomeOf($eq, ['v' => null]))->toBe(O::Null)
        ->and(outcomeOf($neq, ['v' => null]))->toBe(O::Null)
        ->and(outcomeOf(cond('exists', 'v'), ['v' => null]))->toBe(O::Match)
        ->and(outcomeOf(cond('exists', 'v'), ['v' => false]))->toBe(O::Match)
        ->and(outcomeOf(cond('exists', 'v'), ['v' => []]))->toBe(O::Match)
        ->and(outcomeOf(cond('exists', 'v'), []))->toBe(O::NoMatch)
        ->and(outcomeOf(cond('not_exists', 'v'), []))->toBe(O::Match)
        ->and(outcomeOf(cond('not_exists', 'v'), ['v' => null]))->toBe(O::NoMatch);
});

it('reads kind and key as attributes', function () {
    expect(outcomeOf(cond('eq', 'kind', ['user']), []))->toBe(O::Match)
        ->and(outcomeOf(cond('eq', 'key', ['u_1']), []))->toBe(O::Match)
        ->and(outcomeOf(cond('eq', 'key', ['u_1']), [], key: null))->toBe(O::Missing)
        ->and(outcomeOf(cond('exists', 'key'), [], key: null))->toBe(O::NoMatch)
        ->and(outcomeOf(cond('not_exists', 'key'), [], key: null))->toBe(O::Match)
        ->and(outcomeOf(cond('exists', 'kind'), []))->toBe(O::Match);
});

it('decides segment conditions through the membership callback', function () {
    expect(outcomeOf(cond('in_segment', null, ['beta']), []))->toBe(O::Match)
        ->and(outcomeOf(cond('in_segment', null, ['other']), []))->toBe(O::NoMatch)
        ->and(outcomeOf(cond('in_segment', null, ['other', 'beta']), []))->toBe(O::Match)
        ->and(outcomeOf(cond('not_in_segment', null, ['beta']), []))->toBe(O::NoMatch)
        ->and(outcomeOf(cond('not_in_segment', null, ['other']), []))->toBe(O::Match)
        ->and(outcomeOf(cond('not_in_segment', null, ['other', 'beta']), []))->toBe(O::NoMatch);
});

it('lets the membership callback abort the evaluation', function () {
    $matcher = new ConditionMatcher(new Context('user', 'u_1'), static fn(string $segment): bool => throw new Abort(ErrorKind::InvalidConfig));

    expect(fn() => $matcher->outcome(cond('in_segment', null, ['ghost'])))->toThrow(Abort::class);
});

/**
 * @param  list<Condition|ConditionGroup>  $members
 */
function group(string $kind, array $members): ConditionGroup
{
    return new ConditionGroup(GroupKind::from($kind), $members);
}

it('evaluates groups', function (ConditionGroup $group, bool $expected) {
    $matcher = new ConditionMatcher(new Context('user', 'u_1', ['country' => 'PH', 'plan' => 'free']), static fn(string $segment): bool => false);

    expect($matcher->matches($group))->toBe($expected);
})->with([
    'empty all' => [group('all', []), true],
    'empty any' => [group('any', []), false],
    'all true' => [group('all', [cond('eq', 'country', ['PH']), cond('eq', 'plan', ['free'])]), true],
    'all one false' => [group('all', [cond('eq', 'country', ['PH']), cond('eq', 'plan', ['pro'])]), false],
    'any one true' => [group('any', [cond('eq', 'country', ['US']), cond('eq', 'plan', ['free'])]), true],
    'any none true' => [group('any', [cond('eq', 'country', ['US']), cond('eq', 'plan', ['pro'])]), false],
    'nested any in all' => [group('all', [cond('eq', 'country', ['PH']), group('any', [cond('eq', 'plan', ['pro']), cond('eq', 'plan', ['free'])])]), true],
    'nested empty any blocks all' => [group('all', [cond('eq', 'country', ['PH']), group('any', [])]), false],
    'nested empty all in any' => [group('any', [cond('eq', 'country', ['US']), group('all', [])]), true],
]);

it('records outcomes with paths and marks skipped conditions as not evaluated', function () {
    $matcher = new ConditionMatcher(new Context('user', 'u_1', ['country' => 'US']), static fn(string $segment): bool => false);
    $group = group('all', [
        cond('eq', 'country', ['PH']),
        group('any', [cond('eq', 'plan', ['pro']), cond('in_segment', null, ['beta'])]),
        cond('exists', 'x'),
    ]);

    $trace = [];
    $matched = $matcher->matches($group, $trace);

    expect($matched)->toBeFalse()
        ->and(array_map(static fn(ConditionTrace $t): array => [$t->path, $t->outcome], $trace))->toBe([
            ['/all/0', O::NoMatch],
            ['/all/1/any/0', O::NotEvaluated],
            ['/all/1/any/1', O::NotEvaluated],
            ['/all/2', O::NotEvaluated],
        ])
        ->and($trace[2]->segment)->toBe('beta')
        ->and($trace[0]->attribute)->toBe('country');
});

it('does not record anything when no trace is wanted', function () {
    $matcher = new ConditionMatcher(new Context('user', 'u_1'), static fn(string $segment): bool => false);

    expect($matcher->matches(group('all', [cond('exists', 'x')])))->toBeFalse();
});

it('records an any group stopping at the first match', function () {
    $matcher = new ConditionMatcher(new Context('user', 'u_1', ['country' => 'PH']), static fn(string $segment): bool => false);
    $trace = [];

    $matcher->matches(group('any', [cond('eq', 'country', ['PH']), cond('eq', 'plan', ['pro'])]), $trace);

    expect(array_map(static fn(ConditionTrace $t): O => $t->outcome, $trace))->toBe([O::Match, O::NotEvaluated]);
});

it('folds only ASCII letters', function () {
    expect(StringMatch::fold("ABC xyz É İ \u{212A}"))->toBe("abc xyz É İ \u{212A}")
        ->and(StringMatch::fold("ÀB"))->toBe("Àb");
});
