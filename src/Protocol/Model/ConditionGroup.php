<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

final readonly class ConditionGroup
{
    /**
     * @param  list<Condition|ConditionGroup>  $members
     */
    public function __construct(public GroupKind $kind, public array $members) {}
}
