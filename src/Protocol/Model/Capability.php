<?php

declare(strict_types=1);

namespace BitFlag\Protocol\Model;

/**
 * The capabilities of protocol version 1. Snapshots may name others; they are plain strings there.
 */
enum Capability: string
{
    case Operators = 'operators.v1';
    case Semver = 'semver.v1';
    case Dates = 'dates.v1';
    case Segments = 'segments.v1';
    case Prerequisites = 'prerequisites.v1';
    case Rollouts = 'rollouts.v1';
    case Experiments = 'experiments.v1';
}
