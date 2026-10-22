<?php

declare(strict_types=1);

namespace BitFlag\Protocol;

use BitFlag\Evaluation\ErrorKind;
use RuntimeException;

/**
 * A snapshot was rejected. Carries the error kind, a JSON Pointer to the offending value and a problem code.
 */
final class MalformedSnapshot extends RuntimeException
{
    public function __construct(
        public readonly ErrorKind $errorKind,
        public readonly string $path,
        public readonly string $problem,
        string $detail = '',
    ) {
        parent::__construct(sprintf('%s at "%s" (%s)%s', $errorKind->value, $path, $problem, $detail === '' ? '' : ': ' . $detail));
    }

    public static function malformed(string $path, string $problem, string $detail = ''): self
    {
        return new self(ErrorKind::MalformedSnapshot, $path, $problem, $detail);
    }
}
