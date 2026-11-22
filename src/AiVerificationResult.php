<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * What checking an AI agent's identity found: `AiVerification::$result`. The
 * API can add values, so keep a default branch.
 */
final class AiVerificationResult
{
    /** The claim held. */
    public const PASS = 'pass';

    /** The claim was checked and did not hold. */
    public const FAIL = 'fail';

    /** No check was made. */
    public const NOT_ATTEMPTED = 'not_attempted';

    /** Every result, in the order listed. */
    public const ALL = [self::PASS, self::FAIL, self::NOT_ATTEMPTED];

    private function __construct() {}
}
