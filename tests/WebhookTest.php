<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Webhook signature verification.
 *
 * @internal
 */
final class WebhookTest extends TestCase
{
    private const NOW = 1789646400;
    private const PAYLOAD = '{"id":"evt_1","type":"identification.completed"}';

    public function testAcceptsADeliveryItSigned(): void
    {
        self::assertTrue($this->verify(self::sign(self::NOW)));
    }

    /** During a rotation Fingerly sends several signatures; any one matching is enough. */
    public function testAcceptsAnySignatureInARotationHeader(): void
    {
        $current = self::sign(self::NOW);
        $previous = self::sign(self::NOW, self::PAYLOAD, 'whsec_previous');

        foreach (["{$previous},{$current}", "{$current},{$previous}", "{$previous}, {$current}"] as $header) {
            self::assertTrue($this->verify($header), $header.' was refused');
        }
        self::assertFalse($this->verify($previous));
    }

    /** The signature covers the body, so one changed byte invalidates it. */
    /** Only the first 32 signatures in the header are looked at. */
    public function testConsidersAtMost32Signatures(): void
    {
        $timestamp = (string) time();
        $valid = 'sha256='.hash_hmac('sha256', $timestamp.'.{}', TestServer::WEBHOOK_SECRET);
        $decoys = array_fill(0, Webhook::MAX_SIGNATURES - 1, 'sha256='.str_repeat('0', 64));

        $last = implode(',', [...$decoys, $valid]);
        self::assertTrue(Webhook::verify(TestServer::WEBHOOK_SECRET, '{}', $timestamp, $last));

        $pastTheCap = implode(',', [...$decoys, 'sha256='.str_repeat('1', 64), $valid]);
        self::assertFalse(Webhook::verify(TestServer::WEBHOOK_SECRET, '{}', $timestamp, $pastTheCap));
    }

    public function testRefusesATamperedBody(): void
    {
        self::assertFalse($this->verify(
            self::sign(self::NOW),
            '{"id":"evt_1","type":"identification.failed"}',
        ));
    }

    /** It covers the timestamp too, so a replay cannot be re-dated. */
    public function testRefusesASignatureLiftedOntoAnotherTimestamp(): void
    {
        self::assertFalse(Webhook::verify(
            secret: TestServer::WEBHOOK_SECRET,
            payload: self::PAYLOAD,
            timestamp: (string) (self::NOW + 1),
            signature: self::sign(self::NOW),
            now: self::at(self::NOW),
        ));
    }

    public function testRefusesADeliveryOutsideTheReplayWindow(): void
    {
        foreach ([301, -301] as $drift) {
            $signedAt = self::NOW + $drift;
            self::assertFalse(Webhook::verify(
                secret: TestServer::WEBHOOK_SECRET,
                payload: self::PAYLOAD,
                timestamp: (string) $signedAt,
                signature: self::sign($signedAt),
                now: self::at(self::NOW),
            ), sprintf('a delivery %ds away was accepted', $drift));
        }
    }

    public function testAcceptsADeliveryAtTheEdgeOfTheWindow(): void
    {
        foreach ([300, -300] as $drift) {
            $signedAt = self::NOW + $drift;
            self::assertTrue(Webhook::verify(
                secret: TestServer::WEBHOOK_SECRET,
                payload: self::PAYLOAD,
                timestamp: (string) $signedAt,
                signature: self::sign($signedAt),
                now: self::at(self::NOW),
            ));
        }
    }

    public function testHonoursACustomTolerance(): void
    {
        $signedAt = self::NOW - 600;
        $arguments = [
            'secret' => TestServer::WEBHOOK_SECRET,
            'payload' => self::PAYLOAD,
            'timestamp' => (string) $signedAt,
            'signature' => self::sign($signedAt),
            'now' => self::at(self::NOW),
        ];

        self::assertFalse(Webhook::verify(...$arguments));
        self::assertTrue(Webhook::verify(...$arguments + ['tolerance' => 900]));
    }

    /** Malformed or missing input is a false result, never an exception. */
    public function testRefusesAnythingThatIsNotASignatureWeSend(): void
    {
        $valid = self::sign(self::NOW);
        $digest = substr($valid, 7);

        $cases = [
            [null, $valid],
            [(string) self::NOW, null],
            [null, null],
            ['', $valid],
            [(string) self::NOW, ''],
            ['not-a-number', $valid],
            ['-1', $valid],
            ['1.5', $valid],
            [(string) self::NOW, $digest],
            [(string) self::NOW, 'sha256='],
            [(string) self::NOW, 'sha1=abc'],
            [(string) self::NOW, 'sha256=zz'],
            [(string) self::NOW, ',,,'],
            [(string) self::NOW, strtoupper($valid)],
        ];

        foreach ($cases as [$timestamp, $signature]) {
            self::assertFalse(Webhook::verify(
                secret: TestServer::WEBHOOK_SECRET,
                payload: self::PAYLOAD,
                timestamp: $timestamp,
                signature: $signature,
                now: self::at(self::NOW),
            ), sprintf('%s / %s was accepted', var_export($timestamp, true), var_export($signature, true)));
        }
    }

    public function testRefusesAnEmptySecret(): void
    {
        self::assertFalse(Webhook::verify(
            secret: '',
            payload: self::PAYLOAD,
            timestamp: (string) self::NOW,
            signature: self::sign(self::NOW, self::PAYLOAD, ''),
            now: self::at(self::NOW),
        ));
    }

    /** With no clock supplied it checks against the real one. */
    public function testVerifiesAFreshDeliveryAgainstTheRealClock(): void
    {
        $now = time();
        self::assertTrue(Webhook::verify(
            secret: TestServer::WEBHOOK_SECRET,
            payload: self::PAYLOAD,
            timestamp: (string) $now,
            signature: self::sign($now),
        ));
    }

    /** The envelope decodes, and data stays raw so a new event type needs no upgrade. */
    public function testDecodesTheDeliveryEnvelope(): void
    {
        $event = Webhook::parse(json_encode([
            'id' => 'evt_1',
            'type' => 'identification.completed',
            'version' => 1,
            'organization_id' => 'org_1',
            'environment' => 'production',
            'created_at' => '2026-09-18T08:00:00Z',
            'data' => ['request_id' => 'req_1', 'invented_later' => true],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame('evt_1', $event->id);
        self::assertSame('identification.completed', $event->type);
        self::assertSame(1, $event->version);
        self::assertSame('req_1', $event->data['request_id']);
        self::assertTrue($event->data['invented_later']);
    }

    public function testRefusesAPayloadThatIsNotADelivery(): void
    {
        $this->expectException(ApiException::class);
        Webhook::parse('[]');
    }

    private function verify(string $signature, string $payload = self::PAYLOAD): bool
    {
        return Webhook::verify(
            secret: TestServer::WEBHOOK_SECRET,
            payload: $payload,
            timestamp: (string) self::NOW,
            signature: $signature,
            now: self::at(self::NOW),
        );
    }

    /** Signs a payload the way Fingerly does: the timestamp, a dot, then the bytes. */
    private static function sign(
        int $timestamp,
        string $payload = self::PAYLOAD,
        string $secret = TestServer::WEBHOOK_SECRET,
    ): string {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    private static function at(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($timestamp);
    }
}
