<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Identifiers `EventTrigger::$signal` (and `$group`) can hold.
 *
 * Event reads report each trigger under its signal group, such as `vpn`;
 * `visitor.suspect` webhooks and the archived document's `risk.triggers` carry
 * the detector, such as `vpn_public`, with its group beside it. Many groups
 * share their detector's name, so each identifier is here once.
 *
 * Like every constant class in this package, each constant is the wire string
 * itself, so `Signal::VPN === $trigger->signal` compares strings, and the
 * field stays a string: a signal the server adds later still arrives, and is
 * only missing from `ALL`.
 */
final class Signal
{
    // Detectors every platform can produce.
    public const HIGH_ACTIVITY = 'high_activity';
    public const TAMPERING = 'tampering';
    public const DEVICE_FARM = 'device_farm';
    public const DEVELOPER_TOOLS = 'developer_tools';
    public const GEO_MISMATCH = 'geo_mismatch';
    public const DEVICE_INCONSISTENT = 'device_inconsistent';

    // Detectors derived from the address a submission arrived from.
    public const TOR = 'tor';
    public const PROXY = 'proxy';
    public const RESIDENTIAL_PROXY = 'residential_proxy';
    public const RESIDENTIAL_PROXY_LOW_CONFIDENCE = 'residential_proxy_low_confidence';
    public const RESIDENTIAL_PROXY_MEDIUM_CONFIDENCE = 'residential_proxy_medium_confidence';
    public const RESIDENTIAL_PROXY_HIGH_CONFIDENCE = 'residential_proxy_high_confidence';
    public const VPN_PUBLIC = 'vpn_public';
    public const VPN_TIMEZONE = 'vpn_timezone';
    public const VPN_RELAY = 'vpn_relay';
    public const VPN_MOBILE = 'vpn_mobile';
    public const VPN_OS_MISMATCH = 'vpn_os_mismatch';
    public const VPN_LOW_CONFIDENCE = 'vpn_low_confidence';
    public const VPN_MEDIUM_CONFIDENCE = 'vpn_medium_confidence';
    public const VPN_HIGH_CONFIDENCE = 'vpn_high_confidence';
    public const IP_BLOCKLIST_EMAIL_SPAM = 'ip_blocklist_email_spam';
    public const IP_BLOCKLIST_ATTACK_SOURCE = 'ip_blocklist_attack_source';
    public const IP_ABUSER = 'ip_abuser';
    public const DATACENTER_IP = 'datacenter_ip';
    public const BOGON_IP = 'bogon_ip';
    public const ANYCAST_IP = 'anycast_ip';

    // Detectors only a browser can produce.
    public const INCOGNITO = 'incognito';
    public const BAD_BOT = 'bad_bot';
    public const AUTOMATION = 'automation';
    public const FETCH_METADATA_MISSING = 'fetch_metadata_missing';
    public const WEB_TICKET_MISSING = 'web_ticket_missing';
    public const WEB_TICKET_INVALID = 'web_ticket_invalid';
    public const ANTIDETECT_BROWSER = 'antidetect_browser';
    public const VIRTUAL_MACHINE = 'virtual_machine';
    public const FINGERPRINT_SUPPRESSED = 'fingerprint_suppressed';
    public const PRIVACY_SETTINGS = 'privacy_settings';
    public const EMBEDDED_WEB_VIEW = 'embedded_web_view';
    public const REMOTE_CONTROL = 'remote_control';

    // Detectors only a native application can produce.
    public const EMULATOR = 'emulator';
    public const ROOT_APPS = 'root_apps';
    public const APP_CLONER = 'app_cloner';
    public const JAILBREAK = 'jailbreak';
    public const SIMULATOR = 'simulator';
    public const FRIDA = 'frida';
    public const MITM_ATTACK = 'mitm_attack';
    public const ACTIVE_CALL = 'active_call';
    public const ATTESTATION_MISSING = 'attestation_missing';
    public const ATTESTATION_DOWNGRADE = 'attestation_downgrade';
    public const REPEATED_REINSTALL = 'repeated_reinstall';
    public const DEVICE_ACTIVITY_HIGH = 'device_activity_high';
    public const DEVICE_ACTIVITY_VERY_HIGH = 'device_activity_very_high';
    public const PLAY_DEVICE_INTEGRITY_FAILED = 'play_device_integrity_failed';
    public const PLAY_APP_UNRECOGNIZED = 'play_app_unrecognized';
    public const ATTESTATION_FAILED = 'attestation_failed';
    public const REPORT_INCOMPLETE = 'report_incomplete';
    public const PROBE_ERRORS = 'probe_errors';
    public const CUSTOM_ROOT_CA = 'custom_root_ca';
    public const SECONDARY_USER_PROFILE = 'secondary_user_profile';

    // Signal groups whose name is not also a detector's.
    public const DATACENTER_PROXY = 'datacenter_proxy';
    public const VPN = 'vpn';
    public const IP_BLOCKLIST = 'ip_blocklist';
    public const IP_REPUTATION = 'ip_reputation';
    public const NETWORK_ANOMALY = 'network_anomaly';
    public const LOCATION_SPOOFING = 'location_spoofing';
    public const IOS_SIMULATOR = 'ios_simulator';
    public const ANDROID_EMULATOR = 'android_emulator';
    public const JAILBROKEN_DEVICE = 'jailbroken_device';
    public const ROOTED_DEVICE = 'rooted_device';
    public const CLONED_APP = 'cloned_app';
    public const FRIDA_DETECTED = 'frida_detected';
    public const BROWSER_TAMPERING = 'browser_tampering';
    public const BOT = 'bot';
    public const INCOGNITO_MODE = 'incognito_mode';

    /** Every identifier, in the order listed. */
    public const ALL = [
        self::HIGH_ACTIVITY,
        self::TAMPERING,
        self::DEVICE_FARM,
        self::DEVELOPER_TOOLS,
        self::GEO_MISMATCH,
        self::DEVICE_INCONSISTENT,
        self::TOR,
        self::PROXY,
        self::RESIDENTIAL_PROXY,
        self::RESIDENTIAL_PROXY_LOW_CONFIDENCE,
        self::RESIDENTIAL_PROXY_MEDIUM_CONFIDENCE,
        self::RESIDENTIAL_PROXY_HIGH_CONFIDENCE,
        self::VPN_PUBLIC,
        self::VPN_TIMEZONE,
        self::VPN_RELAY,
        self::VPN_MOBILE,
        self::VPN_OS_MISMATCH,
        self::VPN_LOW_CONFIDENCE,
        self::VPN_MEDIUM_CONFIDENCE,
        self::VPN_HIGH_CONFIDENCE,
        self::IP_BLOCKLIST_EMAIL_SPAM,
        self::IP_BLOCKLIST_ATTACK_SOURCE,
        self::IP_ABUSER,
        self::DATACENTER_IP,
        self::BOGON_IP,
        self::ANYCAST_IP,
        self::INCOGNITO,
        self::BAD_BOT,
        self::AUTOMATION,
        self::FETCH_METADATA_MISSING,
        self::WEB_TICKET_MISSING,
        self::WEB_TICKET_INVALID,
        self::ANTIDETECT_BROWSER,
        self::VIRTUAL_MACHINE,
        self::FINGERPRINT_SUPPRESSED,
        self::PRIVACY_SETTINGS,
        self::EMBEDDED_WEB_VIEW,
        self::REMOTE_CONTROL,
        self::EMULATOR,
        self::ROOT_APPS,
        self::APP_CLONER,
        self::JAILBREAK,
        self::SIMULATOR,
        self::FRIDA,
        self::MITM_ATTACK,
        self::ACTIVE_CALL,
        self::ATTESTATION_MISSING,
        self::ATTESTATION_DOWNGRADE,
        self::REPEATED_REINSTALL,
        self::DEVICE_ACTIVITY_HIGH,
        self::DEVICE_ACTIVITY_VERY_HIGH,
        self::PLAY_DEVICE_INTEGRITY_FAILED,
        self::PLAY_APP_UNRECOGNIZED,
        self::ATTESTATION_FAILED,
        self::REPORT_INCOMPLETE,
        self::PROBE_ERRORS,
        self::CUSTOM_ROOT_CA,
        self::SECONDARY_USER_PROFILE,
        self::DATACENTER_PROXY,
        self::VPN,
        self::IP_BLOCKLIST,
        self::IP_REPUTATION,
        self::NETWORK_ANOMALY,
        self::LOCATION_SPOOFING,
        self::IOS_SIMULATOR,
        self::ANDROID_EMULATOR,
        self::JAILBROKEN_DEVICE,
        self::ROOTED_DEVICE,
        self::CLONED_APP,
        self::FRIDA_DETECTED,
        self::BROWSER_TAMPERING,
        self::BOT,
        self::INCOGNITO_MODE,
    ];

    private function __construct() {}
}
