<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Evaluation\Internal\Operators\DateCompare;
use BitFlag\Evaluation\Internal\Operators\Equality;
use BitFlag\Evaluation\Internal\Operators\Numeric;
use BitFlag\Evaluation\Internal\Operators\SemverCompare;
use BitFlag\Evaluation\Internal\Operators\StringMatch;
use BitFlag\Evaluation\Internal\Values\Instant;
use BitFlag\Evaluation\Internal\Values\SemVer;
use BitFlag\Evaluation\Internal\Values\TypedValue;
use BitFlag\Evaluation\Trace\ConditionOutcome;
use BitFlag\Evaluation\Trace\ConditionTrace;
use BitFlag\Protocol\Model\Condition;
use BitFlag\Protocol\Model\ConditionGroup;
use BitFlag\Protocol\Model\GroupKind;
use BitFlag\Protocol\Model\Operator;
use Closure;

/**
 * Evaluates condition groups against one context. Groups short-circuit; the conditions they skip are recorded as
 * not evaluated.
 *
 * @internal
 */
final class ConditionMatcher
{
    /**
     * @param  Closure(string): bool  $inSegment  membership of the context in a segment; aborts for a missing segment
     */
    public function __construct(private readonly Context $context, private readonly Closure $inSegment) {}

    /**
     * @param  list<ConditionTrace>|null  $trace  collects one entry per condition when given
     */
    public function matches(ConditionGroup $group, ?array &$trace = null, string $path = ''): bool
    {
        $name = $group->kind->value;
        $all = $group->kind === GroupKind::All;
        $result = $all;
        $decided = false;

        foreach ($group->members as $index => $member) {
            $memberPath = $path . '/' . $name . '/' . $index;
            if ($decided) {
                $this->skip($member, $memberPath, $trace);

                continue;
            }

            $matched = $member instanceof ConditionGroup
                ? $this->matches($member, $trace, $memberPath)
                : $this->condition($member, $memberPath, $trace);

            if ($all && ! $matched) {
                $result = false;
                $decided = true;
            } elseif (! $all && $matched) {
                $result = true;
                $decided = true;
            }
        }

        return $result;
    }

    /**
     * @param  list<ConditionTrace>|null  $trace
     */
    private function skip(Condition|ConditionGroup $member, string $path, ?array &$trace): void
    {
        if ($trace === null) {
            return;
        }
        if ($member instanceof ConditionGroup) {
            foreach ($member->members as $index => $inner) {
                $this->skip($inner, $path . '/' . $member->kind->value . '/' . $index, $trace);
            }

            return;
        }

        $trace[] = $this->traceEntry($member, $path, ConditionOutcome::NotEvaluated);
    }

    /**
     * @param  list<ConditionTrace>|null  $trace
     */
    private function condition(Condition $condition, string $path, ?array &$trace): bool
    {
        $outcome = $this->outcome($condition);
        if ($trace !== null) {
            $trace[] = $this->traceEntry($condition, $path, $outcome);
        }

        return $outcome === ConditionOutcome::Match;
    }

    private function traceEntry(Condition $condition, string $path, ConditionOutcome $outcome): ConditionTrace
    {
        $segment = $condition->operator->isSegment() && count($condition->values) === 1 ? (string) $condition->values[0] : null;

        return new ConditionTrace($path, $condition->operator->value, $condition->attribute, $segment, $outcome);
    }

    public function outcome(Condition $condition): ConditionOutcome
    {
        $operator = $condition->operator;

        if ($operator->isSegment()) {
            $member = false;
            foreach ($condition->values as $key) {
                if (($this->inSegment)((string) $key)) {
                    $member = true;
                    break;
                }
            }

            return ($operator === Operator::InSegment ? $member : ! $member) ? ConditionOutcome::Match : ConditionOutcome::NoMatch;
        }

        $name = (string) $condition->attribute;
        $present = $this->has($name);
        if ($operator->isExistence()) {
            return ($operator === Operator::Exists ? $present : ! $present) ? ConditionOutcome::Match : ConditionOutcome::NoMatch;
        }
        if (! $present) {
            return ConditionOutcome::Missing;
        }

        $value = $this->read($name);
        if ($value === null) {
            return ConditionOutcome::Null;
        }

        return $this->compare($condition, is_array($value) ? $value : [$value]);
    }

    /**
     * @param  array<array-key, mixed>  $items  the attribute value, or the elements of an array value
     */
    private function compare(Condition $condition, array $items): ConditionOutcome
    {
        $operator = $condition->operator;
        $values = $condition->values;
        $first = $values[0] ?? null;

        $expected = match (true) {
            $operator->isNumeric() => TypedValue::NUMBER,
            $operator->isStringMatch(), $operator->isSemver(), $operator->isDate() => TypedValue::STRING,
            default => TypedValue::family($first),
        };
        foreach ($items as $item) {
            if (TypedValue::family($item) !== $expected) {
                return ConditionOutcome::WrongType;
            }
        }

        if ($operator->isSemver() || $operator->isDate()) {
            return $this->compareParsed($condition, $items);
        }

        $any = false;
        foreach ($items as $item) {
            /** @var string|int|float|bool $item */
            $hit = match (true) {
                $operator === Operator::Eq, $operator === Operator::Neq => Equality::equals($item, $first ?? ''),
                $operator === Operator::In, $operator === Operator::NotIn => Equality::anyEqual($item, $values),
                $operator->isStringMatch() => StringMatch::matches($operator, (string) $item, $values, $condition->ignoreCase),
                default => Numeric::compare($operator, (float) $item, (float) ($first ?? 0)),
            };
            if ($hit) {
                $any = true;
                break;
            }
        }

        return ($operator->isNegated() ? ! $any : $any) ? ConditionOutcome::Match : ConditionOutcome::NoMatch;
    }

    /**
     * @param  array<array-key, mixed>  $items  strings
     */
    private function compareParsed(Condition $condition, array $items): ConditionOutcome
    {
        $operator = $condition->operator;
        $listed = (string) ($condition->values[0] ?? '');

        if ($operator->isSemver()) {
            $target = SemVer::parse($listed) ?? throw new Abort(ErrorKind::InvalidConfig);
            $parsed = [];
            foreach ($items as $item) {
                $version = is_string($item) ? SemVer::parse($item) : null;
                if ($version === null) {
                    return ConditionOutcome::InvalidValue;
                }
                $parsed[] = $version;
            }
            $any = false;
            foreach ($parsed as $version) {
                if (SemverCompare::compare($operator, $version, $target)) {
                    $any = true;
                    break;
                }
            }
        } else {
            $target = Instant::parse($listed) ?? throw new Abort(ErrorKind::InvalidConfig);
            $parsed = [];
            foreach ($items as $item) {
                $instant = is_string($item) ? Instant::parse($item) : null;
                if ($instant === null) {
                    return ConditionOutcome::InvalidValue;
                }
                $parsed[] = $instant;
            }
            $any = false;
            foreach ($parsed as $instant) {
                if (DateCompare::compare($operator, $instant, $target)) {
                    $any = true;
                    break;
                }
            }
        }

        return ($operator->isNegated() ? ! $any : $any) ? ConditionOutcome::Match : ConditionOutcome::NoMatch;
    }

    private function has(string $name): bool
    {
        return match ($name) {
            'kind' => true,
            'key' => $this->context->key !== null,
            default => array_key_exists($name, $this->context->attributes),
        };
    }

    private function read(string $name): mixed
    {
        return match ($name) {
            'kind' => $this->context->kind,
            'key' => $this->context->key,
            default => $this->context->attributes[$name] ?? null,
        };
    }
}
