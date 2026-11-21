<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Where an AI agent runs: `AiAgent::$runtime`. The API can add values, so
 * keep a default branch.
 */
final class AiRuntime
{
    /** The operator's own infrastructure. */
    public const PROVIDER_CLOUD = 'provider_cloud';

    /** A third party's cloud browser. */
    public const HOSTED_BROWSER = 'hosted_browser';

    /** A person's own browser. */
    public const USER_DEVICE = 'user_device';

    /** Every runtime, in the order listed. */
    public const ALL = [self::PROVIDER_CLOUD, self::HOSTED_BROWSER, self::USER_DEVICE];

    private function __construct() {}
}
