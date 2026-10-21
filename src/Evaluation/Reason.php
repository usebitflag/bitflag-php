<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

/**
 * Why an evaluation returned its value.
 */
enum Reason: string
{
    case Off = 'OFF';
    case Prerequisite = 'PREREQUISITE';
    case Override = 'OVERRIDE';
    case Rule = 'RULE';
    case Default = 'DEFAULT';
    case Fallback = 'FALLBACK';
    case Error = 'ERROR';
}
