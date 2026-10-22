<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class Segment
{
    /**
     * @param  list<SubjectRef>  $include
     * @param  list<SubjectRef>  $exclude
     */
    public function __construct(
        public int $version,
        public ConditionGroup $match,
        public array $include,
        public array $exclude,
    ) {}
}
