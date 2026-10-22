<?php

declare(strict_types=1);

namespace BitFlag\Evaluation\Exception;

use InvalidArgumentException;

/**
 * The caller passed a fallback whose type differs from the requested type. A programming error, never a result.
 */
final class FallbackTypeError extends InvalidArgumentException {}
