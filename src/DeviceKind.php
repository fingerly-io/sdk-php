<?php

declare(strict_types=1);

namespace Fingerly;

/** What the fingerprint was taken on: `Event::$deviceKind`. */
final class DeviceKind
{
    public const DESKTOP = 'desktop';
    public const MOBILE = 'mobile';
    public const TABLET = 'tablet';
    public const SERVER = 'server';

    /** Every kind, in the order listed. */
    public const ALL = [self::DESKTOP, self::MOBILE, self::TABLET, self::SERVER];

    private function __construct() {}
}
