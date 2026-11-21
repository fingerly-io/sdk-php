<?php

declare(strict_types=1);

namespace Fingerly;

/** How an {@see AiAgent}'s claimed identity was checked, and what that found. */
final class AiVerification
{
    /**
     * @param string $method `signature`, `ip_list`, `reverse_dns` or `none`; see {@see AiVerificationMethod}
     * @param string $result `pass`, `fail` or `not_attempted`; see {@see AiVerificationResult}
     */
    public function __construct(
        public readonly string $method,
        public readonly string $result,
    ) {}
}
