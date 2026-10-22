<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Internal;

use BitFlag\Evaluation\Context;
use BitFlag\Evaluation\ErrorKind;
use BitFlag\Protocol\Model\Segment;
use BitFlag\Protocol\Model\SubjectRef;

/**
 * Segment membership: excluded subjects are out, then included subjects are in, then the segment's conditions
 * decide. A context without a key never matches either list.
 *
 * @internal
 */
final class SegmentMatcher
{
    /** @var list<string> segments being evaluated, to stop a segment that refers back to itself */
    private array $active = [];

    /** @var array<string, bool> membership already decided in this evaluation, so a shared segment is evaluated once */
    private array $decided = [];

    /**
     * @param  array<string, Segment>  $segments
     */
    public function __construct(private readonly array $segments, private readonly Context $context) {}

    /**
     * @throws Abort when the segment is missing or refers back to itself
     */
    public function isMember(string $key): bool
    {
        if (isset($this->decided[$key])) {
            return $this->decided[$key];
        }

        $segment = $this->segments[$key] ?? throw new Abort(ErrorKind::InvalidConfig);
        if (in_array($key, $this->active, true)) {
            throw new Abort(ErrorKind::InvalidConfig);
        }

        if ($this->listed($segment->exclude)) {
            return $this->decided[$key] = false;
        }
        if ($this->listed($segment->include)) {
            return $this->decided[$key] = true;
        }

        $this->active[] = $key;
        try {
            return $this->decided[$key] = (new ConditionMatcher($this->context, $this->isMember(...)))->matches($segment->match);
        } finally {
            array_pop($this->active);
        }
    }

    /**
     * @param  list<SubjectRef>  $subjects
     */
    private function listed(array $subjects): bool
    {
        if ($this->context->key === null) {
            return false;
        }
        foreach ($subjects as $subject) {
            if ($subject->kind === $this->context->kind && $subject->key === $this->context->key) {
                return true;
            }
        }

        return false;
    }
}
