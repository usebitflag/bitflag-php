<?php

declare(strict_types=1);

namespace BitFlag\Protocol;

/**
 * Codes that say why a snapshot was rejected.
 */
final class Problem
{
    public const INVALID_JSON = 'INVALID_JSON';

    public const TOO_DEEP = 'TOO_DEEP';

    public const TOO_LARGE = 'TOO_LARGE';

    public const INVALID_TYPE = 'INVALID_TYPE';

    public const MISSING_FIELD = 'MISSING_FIELD';

    public const UNKNOWN_FIELD = 'UNKNOWN_FIELD';

    public const INVALID_VALUE = 'INVALID_VALUE';

    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    public const UNSAFE_NUMBER = 'UNSAFE_NUMBER';

    public const UNSUPPORTED_VERSION = 'UNSUPPORTED_VERSION';

    public const UNSUPPORTED_ALGORITHM = 'UNSUPPORTED_ALGORITHM';

    public const WEIGHT_TOTAL_EXCEEDED = 'WEIGHT_TOTAL_EXCEEDED';

    public const MISSING_REMAINDER = 'MISSING_REMAINDER';

    public const DUPLICATE_OVERRIDE = 'DUPLICATE_OVERRIDE';

    public const PROJECTION_VIOLATION = 'PROJECTION_VIOLATION';

    public const EMPTY_SNAPSHOT_REQUIRED = 'EMPTY_SNAPSHOT_REQUIRED';

    public const CHECKSUM_MISMATCH = 'CHECKSUM_MISMATCH';

    private function __construct() {}
}
