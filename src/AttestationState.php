<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The server's verdict on a native event's hardware evidence: the archived
 * document's `attestation.state`.
 */
final class AttestationState
{
    public const ATTESTED = 'attested';
    public const NOT_ATTESTED = 'not_attested';
    public const FAILED = 'failed';

    /** Every state, in the order listed. */
    public const ALL = [self::ATTESTED, self::NOT_ATTESTED, self::FAILED];

    private function __construct() {}
}
