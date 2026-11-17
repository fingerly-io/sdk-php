<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The detail behind {@see AttestationState}: the archived document's
 * `attestation.status`.
 */
final class AttestationStatus
{
    public const VERIFIED = 'verified';
    public const INDETERMINATE = 'indeterminate';
    public const INVALID = 'invalid';
    public const ABSENT = 'absent';
    public const UNCONFIGURED = 'unconfigured';

    /** Every status, in the order listed. */
    public const ALL = [
        self::VERIFIED,
        self::INDETERMINATE,
        self::INVALID,
        self::ABSENT,
        self::UNCONFIGURED,
    ];

    private function __construct() {}
}
