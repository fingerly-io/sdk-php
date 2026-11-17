<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Why a native app sent no attestation: the archived document's
 * `attestation.attest_skipped`. Diagnostic only; it never lowers the score.
 */
final class AttestSkippedReason
{
    /**
     * The challenge offered no attestation: on iOS the SDK key has no Apple app ID for
     * App Attest, on Android the app's transport returned no challenge offer.
     */
    public const NOT_OFFERED = 'not_offered';

    /**
     * The device cannot make an attested key: App Attest is not supported on the iOS
     * device, or Android is older than 7.0 (API 24).
     */
    public const UNSUPPORTED = 'unsupported';

    /**
     * No usable challenge ticket: the challenge request failed, or on Android the ticket
     * it returned was malformed.
     */
    public const CHALLENGE_UNAVAILABLE = 'challenge_unavailable';

    /**
     * The platform key store failed to create, attest or sign with the key, or on Android
     * the stored key record could not be read.
     */
    public const KEY_ERROR = 'key_error';

    /**
     * Attestation did not finish in time: a key store call ran past its budget, or another
     * identification held the attestation step too long.
     */
    public const TIMEOUT = 'timeout';

    /**
     * The server rejected a new key from this install (on iOS, also when it asked for a
     * replacement), and the SDK is waiting out a backoff before it registers another.
     */
    public const BACKOFF = 'backoff';

    /**
     * The report has no usable install ID, or on iOS the stored key record could not be
     * read or saved.
     */
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
