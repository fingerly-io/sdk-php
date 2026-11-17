<?php

declare(strict_types=1);

namespace Fingerly;

/** How sure a detection is: `EventTrigger::$confidence`. */
final class Confidence
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    /** Every confidence, in the order listed. */
    public const ALL = [self::LOW, self::MEDIUM, self::HIGH];

    private function __construct() {}
}
