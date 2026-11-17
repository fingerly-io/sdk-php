<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Why {@see Events::verify()} refused an event: `EventVerification::$reason`.
 *
 * `OK` is the `''` of an event that passed; the failures follow in the order
 * the checks run.
 */
final class VerifyReason
{
    /** Every check passed. */
    public const OK = '';

    /** No such event: never issued, past retention, or gone before it could be consumed. */
    public const NOT_FOUND = 'not_found';

    /** The event happened longer ago than `max_age_seconds`, or its time could not be read. */
    public const TOO_OLD = 'too_old';

    /** The event's tag is not exactly the `tag` asked for. */
    public const TAG_MISMATCH = 'tag_mismatch';

    /** The submission resolved to no visitor (`visitorId` is `''`). */
    public const UNIDENTIFIABLE = 'unidentifiable';

    /** The event has no suspect score. */
    public const UNSCORED = 'unscored';

    /** Another call already consumed the event. */
    public const ALREADY_CONSUMED = 'already_consumed';

    /** Every value `EventVerification::$reason` can hold. */
    public const ALL = [
        self::OK,
        self::NOT_FOUND,
        self::TOO_OLD,
        self::TAG_MISMATCH,
        self::UNIDENTIFIABLE,
        self::UNSCORED,
        self::ALREADY_CONSUMED,
    ];

    private function __construct() {}
}
