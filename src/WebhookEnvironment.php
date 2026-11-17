<?php

declare(strict_types=1);

namespace Fingerly;

/** A webhook delivery's `WebhookEvent::$environment`. */
final class WebhookEnvironment
{
    public const LIVE = 'live';
    public const TEST = 'test';

    /** Every environment, in the order listed. */
    public const ALL = [self::LIVE, self::TEST];

    private function __construct() {}
}
