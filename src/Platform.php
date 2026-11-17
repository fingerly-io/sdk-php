<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The scoring profile an event was scored under: `Event::$platform`.
 *
 * `Event::$sdkPlatform` is what the SDK called itself and is not limited to
 * these.
 */
final class Platform
{
    public const WEB = 'web';
    public const ANDROID = 'android';
    public const IOS = 'ios';

    /** Every platform, in the order listed. */
    public const ALL = [self::WEB, self::ANDROID, self::IOS];

    private function __construct() {}
}
