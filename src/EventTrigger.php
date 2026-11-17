<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * One signal group that contributed to an event's suspect score.
 *
 * The `SIGNAL_*` constants are the same strings as {@see Signal}'s.
 */
final class EventTrigger
{
    public const SIGNAL_ATTESTATION_FAILED = Signal::ATTESTATION_FAILED;
    public const SIGNAL_ATTESTATION_MISSING = Signal::ATTESTATION_MISSING;
    public const SIGNAL_ATTESTATION_DOWNGRADE = Signal::ATTESTATION_DOWNGRADE;
    public const SIGNAL_DEVICE_INCONSISTENT = Signal::DEVICE_INCONSISTENT;
    public const SIGNAL_REPORT_INCOMPLETE = Signal::REPORT_INCOMPLETE;
    public const SIGNAL_PROBE_ERRORS = Signal::PROBE_ERRORS;
    public const SIGNAL_FETCH_METADATA_MISSING = Signal::FETCH_METADATA_MISSING;
    public const SIGNAL_WEB_TICKET_MISSING = Signal::WEB_TICKET_MISSING;
    public const SIGNAL_WEB_TICKET_INVALID = Signal::WEB_TICKET_INVALID;
    public const SIGNAL_TAMPERING = Signal::TAMPERING;
    public const SIGNAL_BROWSER_TAMPERING = Signal::BROWSER_TAMPERING;
    public const SIGNAL_CUSTOM_ROOT_CA = Signal::CUSTOM_ROOT_CA;
    public const SIGNAL_SECONDARY_USER_PROFILE = Signal::SECONDARY_USER_PROFILE;

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
     * @param string      $signal     the signal or signal group, such as `vpn` or `attestation_failed`; see {@see Signal}
     * @param int         $weight     the group's combined weight in this score
     * @param null|string $confidence `low`, `medium` or `high`; see {@see Confidence}
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
