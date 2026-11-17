<?php

declare(strict_types=1);

namespace Fingerly;

/** The environment an event was recorded in: `Event::$environment`. */
final class Environment
{
    public const PRODUCTION = 'production';
    public const STAGING = 'staging';
    public const DEVELOPMENT = 'development';

    /** Every environment, in the order listed. */
    public const ALL = [self::PRODUCTION, self::STAGING, self::DEVELOPMENT];

    private function __construct() {}
}
