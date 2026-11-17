<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * `Event::$state`: enriched, or unavailable when the network lookup could not
 * run. `PENDING` is accepted and not yet written; an event read answers it as
 * a pending `ApiException` rather than as an event.
 */
final class EventState
{
    public const PENDING = 'pending';
    public const ENRICHED = 'enriched';
    public const UNAVAILABLE = 'unavailable';

    /** Every state, in the order listed. */
    public const ALL = [self::PENDING, self::ENRICHED, self::UNAVAILABLE];

    private function __construct() {}
}
