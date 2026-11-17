<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * `Event::$suspectLevel`, and the `level` of identification webhooks: the
 * score, banded.
 *
 * Each constant is the wire string itself, so
 * `SuspectLevel::HIGH === $event->suspectLevel` compares strings.
 */
final class SuspectLevel
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    /** Every level, in the order listed. */
    public const ALL = [self::LOW, self::MEDIUM, self::HIGH];

    private function __construct() {}
}
