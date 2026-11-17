<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * A webhook delivery's `WebhookEvent::$type`.
 *
 * `WEBHOOK_TEST` is sent to one endpoint on request and cannot be subscribed
 * to.
 */
final class WebhookEventType
{
    public const IDENTIFICATION_COMPLETED = 'identification.completed';
    public const IDENTIFICATION_REFUSED = 'identification.refused';
    public const VISITOR_SUSPECT = 'visitor.suspect';
    public const BILLING_STATUS_CHANGED = 'billing.status_changed';
    public const USAGE_DAILY_SETTLED = 'usage.daily_settled';
    public const WEBHOOK_TEST = 'webhook.test';

    /** Every type, in the order listed. */
    public const ALL = [
        self::IDENTIFICATION_COMPLETED,
        self::IDENTIFICATION_REFUSED,
        self::VISITOR_SUSPECT,
        self::BILLING_STATUS_CHANGED,
        self::USAGE_DAILY_SETTLED,
        self::WEBHOOK_TEST,
    ];

    private function __construct() {}
}
