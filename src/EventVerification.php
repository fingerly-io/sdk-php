<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * What {@see Events::verify()} decided about one event.
 *
 * `$ok` is true only when every check passed (and, unless `consume` was
 * false, this call consumed the event). Otherwise `$reason` names the first
 * check that failed. It says nothing about the score: what a score means for
 * the action stays the caller's decision.
 */
final class EventVerification
{
    /** No such event: never issued, past retention, or gone before it could be consumed. */
    public const REASON_NOT_FOUND = 'not_found';

    /** The event happened longer ago than `max_age_seconds`, or its time could not be read. */
    public const REASON_TOO_OLD = 'too_old';

    /** The event's tag is not exactly the `tag` asked for. */
    public const REASON_TAG_MISMATCH = 'tag_mismatch';

    /** The submission resolved to no visitor (`visitorId` is `''`). */
    public const REASON_UNIDENTIFIABLE = 'unidentifiable';

    /** The event has no suspect score. */
    public const REASON_UNSCORED = 'unscored';

    /** Another call already consumed the event. */
    public const REASON_ALREADY_CONSUMED = 'already_consumed';

    /** Every reason `$reason` can hold when `$ok` is false. */
    public const REASONS = [
        self::REASON_NOT_FOUND,
        self::REASON_TOO_OLD,
        self::REASON_TAG_MISMATCH,
        self::REASON_UNIDENTIFIABLE,
        self::REASON_UNSCORED,
        self::REASON_ALREADY_CONSUMED,
    ];

    /**
     * @param bool       $ok     true when every check passed
     * @param string     $reason one of the `REASON_*` constants, or `''` when `$ok`
     * @param null|Event $event  the event read; null only for `not_found`
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly ?Event $event,
    ) {}
}
