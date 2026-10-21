<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Trace;

/**
 * How one condition came out.
 */
enum ConditionOutcome: string
{
    case Match = 'match';
    case NoMatch = 'no_match';
    case Missing = 'missing';
    case Null = 'null';
    case WrongType = 'wrong_type';
    case InvalidValue = 'invalid_value';
    case NotEvaluated = 'not_evaluated';
}
