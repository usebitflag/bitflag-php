<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Evaluation\ErrorKind;
use RuntimeException;

/**
 * Stops an evaluation with an ERROR result. Internal control flow: the Evaluator catches it and never lets it out.
 *
 * @internal
 */
final class Abort extends RuntimeException
{
    public function __construct(public readonly ErrorKind $errorKind)
    {
        parent::__construct($errorKind->value);
    }
}
