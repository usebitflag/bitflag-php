<?php

declare(strict_types=1);

namespace BitFlag\Protocol;

/**
 * A problem in a snapshot that does not stop it from loading. Publication treats any issue as blocking;
 * evaluation reports INVALID_CONFIG only for the issues it reaches.
 */
final readonly class ValidationIssue
{
    public const DANGLING_VARIATION = 'DANGLING_VARIATION';

    public const DANGLING_SEGMENT = 'DANGLING_SEGMENT';

    public const DANGLING_PREREQUISITE = 'DANGLING_PREREQUISITE';

    public const PREREQUISITE_CYCLE = 'PREREQUISITE_CYCLE';

    public const PREREQUISITE_DEPTH = 'PREREQUISITE_DEPTH';

    public const SEGMENT_CYCLE = 'SEGMENT_CYCLE';

    public const CAPABILITY_UNION_MISMATCH = 'CAPABILITY_UNION_MISMATCH';

    public const DUPLICATE_SALT = 'DUPLICATE_SALT';

    public function __construct(
        public string $code,
        /** JSON Pointer to the offending value. */
        public string $path,
        public string $message,
    ) {}
}
