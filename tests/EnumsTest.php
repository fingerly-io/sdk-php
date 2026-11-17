<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\AnonymityNetwork;
use Fingerly\AttestationState;
use Fingerly\AttestationStatus;
use Fingerly\AttestSkippedReason;
use Fingerly\Confidence;
use Fingerly\DeviceKind;
use Fingerly\Environment;
use Fingerly\Event;
use Fingerly\EventState;
use Fingerly\EventTrigger;
use Fingerly\EventVerification;
use Fingerly\Platform;
use Fingerly\Signal;
use Fingerly\SuspectLevel;
use Fingerly\VerifyReason;
use Fingerly\VisitorKind;
use Fingerly\WebhookEnvironment;
use Fingerly\WebhookEventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The named constants are the server's own wire strings. Each expected list
 * below is copied from the server's definitions, so a typo, a dropped value
 * or an invented one fails here rather than in a customer's comparison.
 *
 * @internal
 */
final class EnumsTest extends TestCase
{
    /**
     * @param class-string $class
     * @param list<string> $values
     */
    #[DataProvider('expected')]
    public function testAllListsExactlyTheServerValuesInOrder(string $class, array $values): void
    {
        self::assertSame($values, self::constant($class, 'ALL'));
    }

    /**
     * @param class-string $class
     * @param list<string> $values
     */
    #[DataProvider('expected')]
    public function testAllHoldsEveryConstantOnce(string $class, array $values): void
    {
        $declared = self::declared($class);
        $all = self::constant($class, 'ALL');
        self::assertIsArray($all);

        self::assertEqualsCanonicalizing(array_values($declared), $all);
        self::assertSame(array_values(array_unique($all)), $all);
    }

    /**
     * The name is the value, upper-cased with dots as underscores, so a reader
     * can write the constant for any wire string they see.
     *
     * @param class-string $class
     * @param list<string> $values
     */
    #[DataProvider('expected')]
    public function testEachNameSpellsItsValue(string $class, array $values): void
    {
        foreach (self::declared($class) as $name => $value) {
            if ('OK' === $name) {
                self::assertSame('', $value);

                continue;
            }
            self::assertSame($name, strtoupper(str_replace('.', '_', $value)), $class.'::'.$name);
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $values
     */
    #[DataProvider('expected')]
    public function testTheClassCannotBeInstantiated(string $class, array $values): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
    }

    /**
     * @return iterable<string, array{class-string, list<string>}>
     */
    public static function expected(): iterable
    {
        yield 'Signal' => [Signal::class, [
            'high_activity', 'tampering', 'device_farm', 'developer_tools', 'geo_mismatch',
            'device_inconsistent',
            'tor', 'proxy', 'residential_proxy', 'residential_proxy_low_confidence',
            'residential_proxy_medium_confidence', 'residential_proxy_high_confidence',
            'vpn_public', 'vpn_timezone', 'vpn_relay', 'vpn_mobile', 'vpn_os_mismatch',
            'vpn_low_confidence', 'vpn_medium_confidence', 'vpn_high_confidence',
            'ip_blocklist_email_spam', 'ip_blocklist_attack_source', 'ip_abuser', 'datacenter_ip',
            'bogon_ip', 'anycast_ip',
            'incognito', 'bad_bot', 'automation', 'fetch_metadata_missing', 'web_ticket_missing',
            'web_ticket_invalid', 'antidetect_browser', 'virtual_machine', 'fingerprint_suppressed',
            'privacy_settings', 'embedded_web_view', 'remote_control',
            'emulator', 'root_apps', 'app_cloner', 'jailbreak', 'simulator', 'frida', 'mitm_attack',
            'active_call', 'attestation_missing', 'attestation_downgrade', 'repeated_reinstall',
            'device_activity_high', 'device_activity_very_high', 'play_device_integrity_failed',
            'play_app_unrecognized', 'attestation_failed', 'report_incomplete', 'probe_errors',
            'custom_root_ca', 'secondary_user_profile',
            'datacenter_proxy', 'vpn', 'ip_blocklist', 'ip_reputation', 'network_anomaly',
            'location_spoofing', 'ios_simulator', 'android_emulator', 'jailbroken_device',
            'rooted_device', 'cloned_app', 'frida_detected', 'browser_tampering', 'bot',
            'incognito_mode',
        ]];

        yield 'SuspectLevel' => [SuspectLevel::class, ['low', 'medium', 'high']];

        yield 'Confidence' => [Confidence::class, ['low', 'medium', 'high']];

        yield 'AttestationState' => [AttestationState::class, ['attested', 'not_attested', 'failed']];

        yield 'AttestationStatus' => [AttestationStatus::class, ['verified', 'indeterminate', 'invalid', 'absent', 'unconfigured']];

        yield 'AttestSkippedReason' => [AttestSkippedReason::class, [
            'not_offered', 'unsupported', 'challenge_unavailable', 'key_error', 'timeout', 'backoff',
            'install_unavailable',
        ]];

        yield 'VerifyReason' => [VerifyReason::class, [
            '', 'not_found', 'too_old', 'tag_mismatch', 'unidentifiable', 'unscored', 'already_consumed',
        ]];

        yield 'WebhookEventType' => [WebhookEventType::class, [
            'identification.completed', 'identification.refused', 'visitor.suspect',
            'billing.status_changed', 'usage.daily_settled', 'webhook.test',
        ]];

        yield 'WebhookEnvironment' => [WebhookEnvironment::class, ['live', 'test']];

        yield 'EventState' => [EventState::class, ['pending', 'enriched', 'unavailable']];

        yield 'Environment' => [Environment::class, ['production', 'staging', 'development']];

        yield 'Platform' => [Platform::class, ['web', 'android', 'ios']];

        yield 'AnonymityNetwork' => [AnonymityNetwork::class, ['tor', 'relay', 'vpn', 'proxy', 'hosting']];

        yield 'DeviceKind' => [DeviceKind::class, ['desktop', 'mobile', 'tablet', 'server']];

        yield 'VisitorKind' => [VisitorKind::class, ['human', 'ai_bot', 'automation', 'search_crawler']];
    }

    public function testConstantsCompareIdenticalToDecodedFields(): void
    {
        $trigger = EventTrigger::listFrom([['signal' => 'fingerprint_suppressed', 'weight' => 6, 'confidence' => 'high']])[0];

        self::assertSame(Signal::FINGERPRINT_SUPPRESSED, $trigger->signal);
        self::assertSame(Confidence::HIGH, $trigger->confidence);

        $event = Event::fromArray([
            'request_id' => 'r', 'visitor_id' => 'v', 'occurred_at' => '2026-01-02T03:04:05Z',
            'state' => 'enriched', 'environment' => 'production', 'platform' => 'web',
            'sdk_platform' => 'web', 'sdk_version' => '1.0.0', 'suspect_score' => 80,
            'suspect_level' => 'high', 'triggers' => [],
        ]);
        self::assertSame(SuspectLevel::HIGH, $event->suspectLevel);
        self::assertSame(EventState::ENRICHED, $event->state);
        self::assertSame(Environment::PRODUCTION, $event->environment);
        self::assertSame(Platform::WEB, $event->platform);
    }

    public function testAnUnknownValueArrivesUnchanged(): void
    {
        $trigger = EventTrigger::listFrom([['signal' => 'a_signal_added_later', 'weight' => 1]])[0];

        self::assertSame('a_signal_added_later', $trigger->signal);
        self::assertNotContains($trigger->signal, Signal::ALL);
    }

    public function testTheModelConstantsAreTheSameStrings(): void
    {
        self::assertSame(array_slice(VerifyReason::ALL, 1), EventVerification::REASONS);
        foreach (self::declared(EventVerification::class) as $name => $value) {
            if (str_starts_with($name, 'REASON_')) {
                self::assertSame(self::constant(VerifyReason::class, substr($name, 7)), $value);
            }
        }
        foreach (self::declared(EventTrigger::class) as $name => $value) {
            if (str_starts_with($name, 'SIGNAL_')) {
                self::assertSame(self::constant(Signal::class, substr($name, 7)), $value);
            }
        }
        self::assertSame([], array_values(array_diff(EventTrigger::V3_SIGNALS, Signal::ALL)));
    }

    /**
     * @param class-string $class
     *
     * @return array<string, string>
     */
    private static function declared(string $class): array
    {
        $constants = [];
        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if (is_string($value)) {
                $constants[$constant->getName()] = $value;
            }
        }

        return $constants;
    }

    /**
     * @param class-string $class
     */
    private static function constant(string $class, string $name): mixed
    {
        return (new \ReflectionClassConstant($class, $name))->getValue();
    }
}
