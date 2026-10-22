<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Evaluation\Reason;
use BitFlag\Evaluation\Trace\TraceStep;

/**
 * What the evaluation of one flag decided, before it becomes a result.
 *
 * @internal
 */
final readonly class Decision
{
    /**
     * @param  list<TraceStep>  $steps
     */
    public function __construct(
        public string $variation,
        public Reason $reason,
        public ?string $ruleId = null,
        public ?string $prerequisiteKey = null,
        public ?int $bucket = null,
        public ?string $experimentId = null,
        public ?string $allocationId = null,
        public array $steps = [],
    ) {}
}
