<?php

declare(strict_types=1);

namespace BitFlag\Evaluation;

/**
 * Machine-readable cause of a FALLBACK or ERROR result, and of a rejected snapshot.
 */
enum ErrorKind: string
{
    case FlagNotFound = 'FLAG_NOT_FOUND';
    case TypeMismatch = 'TYPE_MISMATCH';
    case MissingSubjectKey = 'MISSING_SUBJECT_KEY';
    case InvalidConfig = 'INVALID_CONFIG';
    case InvalidContext = 'INVALID_CONTEXT';
    case UnsupportedCapability = 'UNSUPPORTED_CAPABILITY';
    case MalformedSnapshot = 'MALFORMED_SNAPSHOT';
    case SnapshotTooOld = 'SNAPSHOT_TOO_OLD';
    case ClientNotReady = 'CLIENT_NOT_READY';
    case AuthorizationRejected = 'AUTHORIZATION_REJECTED';
}
