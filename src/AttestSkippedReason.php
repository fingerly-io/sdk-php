<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Why a native app sent no attestation: the archived document's
 * `attestation.attest_skipped`. Diagnostic only; it never lowers the score.
 */
final class AttestSkippedReason
{
    public const NOT_OFFERED = 'not_offered';
    public const UNSUPPORTED = 'unsupported';
    public const CHALLENGE_UNAVAILABLE = 'challenge_unavailable';
    public const KEY_ERROR = 'key_error';
    public const TIMEOUT = 'timeout';
    public const BACKOFF = 'backoff';
    public const INSTALL_UNAVAILABLE = 'install_unavailable';

    /** Every reason, in the order listed. */
    public const ALL = [
        self::NOT_OFFERED,
        self::UNSUPPORTED,
        self::CHALLENGE_UNAVAILABLE,
        self::KEY_ERROR,
        self::TIMEOUT,
        self::BACKOFF,
        self::INSTALL_UNAVAILABLE,
    ];

    private function __construct() {}
}
