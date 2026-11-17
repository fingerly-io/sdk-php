<?php

declare(strict_types=1);

namespace Fingerly;

/** One signal group that contributed to an event's suspect score. */
final class EventTrigger
{
    public const SIGNAL_ATTESTATION_FAILED = 'attestation_failed';
    public const SIGNAL_ATTESTATION_MISSING = 'attestation_missing';
    public const SIGNAL_ATTESTATION_DOWNGRADE = 'attestation_downgrade';
    public const SIGNAL_DEVICE_INCONSISTENT = 'device_inconsistent';
    public const SIGNAL_REPORT_INCOMPLETE = 'report_incomplete';
    public const SIGNAL_PROBE_ERRORS = 'probe_errors';
    public const SIGNAL_FETCH_METADATA_MISSING = 'fetch_metadata_missing';
    public const SIGNAL_WEB_TICKET_MISSING = 'web_ticket_missing';
    public const SIGNAL_WEB_TICKET_INVALID = 'web_ticket_invalid';
    public const SIGNAL_TAMPERING = 'tampering';
    public const SIGNAL_BROWSER_TAMPERING = 'browser_tampering';
    public const SIGNAL_CUSTOM_ROOT_CA = 'custom_root_ca';
    public const SIGNAL_SECONDARY_USER_PROFILE = 'secondary_user_profile';

    public const V3_SIGNALS = [
        self::SIGNAL_ATTESTATION_FAILED,
        self::SIGNAL_ATTESTATION_DOWNGRADE,
        self::SIGNAL_DEVICE_INCONSISTENT,
        self::SIGNAL_REPORT_INCOMPLETE,
        self::SIGNAL_PROBE_ERRORS,
        self::SIGNAL_FETCH_METADATA_MISSING,
        self::SIGNAL_WEB_TICKET_MISSING,
        self::SIGNAL_WEB_TICKET_INVALID,
        self::SIGNAL_TAMPERING,
        self::SIGNAL_CUSTOM_ROOT_CA,
        self::SIGNAL_SECONDARY_USER_PROFILE,
    ];

    /**
     * @param string      $signal     the signal or signal group, such as `vpn` or `attestation_failed`
     * @param int         $weight     the group's combined weight in this score
     * @param null|string $confidence `low`, `medium` or `high`
     * @param null|string $group      optional group identifier when returned alongside a raw signal
     */
    public function __construct(
        public readonly string $signal,
        public readonly int $weight,
        public readonly ?string $confidence = null,
        public readonly ?string $group = null,
    ) {}

    /**
     * @return list<self>
     *
     * @internal
     */
    public static function listFrom(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $triggers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $triggers[] = new self(
                signal: Json::string($row, 'signal'),
                weight: (int) ($row['weight'] ?? 0),
                confidence: Json::nullableString($row, 'confidence'),
                group: Json::nullableString($row, 'group'),
            );
        }

        return $triggers;
    }
}
