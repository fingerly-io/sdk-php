<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * How an AI agent's claimed identity was checked: `AiVerification::$method`.
 * The API can add values, so keep a default branch.
 */
final class AiVerificationMethod
{
    /** A signed request. */
    public const SIGNATURE = 'signature';

    /** The operator's published address ranges. */
    public const IP_LIST = 'ip_list';

    /** A reverse lookup confirmed forward. */
    public const REVERSE_DNS = 'reverse_dns';

    /** No check was made. */
    public const NONE = 'none';

    /** Every method, in the order listed. */
    public const ALL = [self::SIGNATURE, self::IP_LIST, self::REVERSE_DNS, self::NONE];

    private function __construct() {}
}
