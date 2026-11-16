<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\EventAlreadyConsumedException;
use Fingerly\EventVerification;
use PHPUnit\Framework\TestCase;

/**
 * Consuming an event, and verifying one for a backend action.
 *
 * @internal
 */
final class VerifyTest extends TestCase
{
    private const CONSUMED = '{"request_id":"req_1","consumed_at":"2026-11-17T10:00:00Z","first":true}';

    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testConsumeDecodesTheAnswer(): void
    {
        $this->server = TestServer::always(200, self::CONSUMED);
        $result = $this->client()->events->consume('req/1');

        self::assertSame('req_1', $result->requestId);
        self::assertSame('2026-11-17T10:00:00Z', $result->consumedAt);
        self::assertTrue($result->first);

        $request = $this->server->first();
        self::assertSame('POST', $request['method']);
        self::assertSame('/api/v1/events/req%2F1/consume', $request['path']);
        self::assertSame('', $request['body']);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($request, 'x-api-key'));
        self::assertSame(1, $this->server->requestCount());
    }

    /** @dataProvider alreadyConsumedBodies */
    public function testConsumeTwiceThrowsTheTypedError(string $body): void
    {
        $this->server = TestServer::always(409, $body);

        try {
            $this->client()->events->consume('req_1');
            self::fail('no exception');
        } catch (EventAlreadyConsumedException $e) {
            self::assertInstanceOf(ApiException::class, $e);
            self::assertSame(409, $e->getStatus());
            self::assertSame(409, $e->getCode());
            self::assertSame('event_already_consumed', $e->getErrorCode());
            self::assertTrue($e->isAlreadyConsumed());
            self::assertSame('2026-11-17T10:00:00Z', $e->getConsumedAt());
            self::assertStringNotContainsString(TestServer::SECRET_KEY, $e->getMessage());
            self::assertStringNotContainsString('2026-11-17', $e->getMessage());
        }
        self::assertSame(1, $this->server->requestCount(), 'a consume was retried');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function alreadyConsumedBodies(): iterable
    {
        yield 'top level' => ['{"code":"event_already_consumed","consumed_at":"2026-11-17T10:00:00Z"}'];

        yield 'envelope with details' => [json_encode(['error' => [
            'code' => 'event_already_consumed', 'message' => 'Event already consumed', 'status' => 409,
            'details' => [['field' => 'consumed_at', 'issue' => '2026-11-17T10:00:00Z', 'location' => 'body']],
        ]], \JSON_THROW_ON_ERROR)];

        yield 'envelope field' => [json_encode(['error' => [
            'code' => 'event_already_consumed', 'message' => 'Event already consumed', 'status' => 409,
            'consumed_at' => '2026-11-17T10:00:00Z',
        ]], \JSON_THROW_ON_ERROR)];
    }

    public function testAlreadyConsumedWithoutATime(): void
    {
        $this->server = TestServer::always(409, '{"code":"event_already_consumed","consumed_at":"not a time"}');

        try {
            $this->client()->events->consume('req_1');
            self::fail('no exception');
        } catch (EventAlreadyConsumedException $e) {
            self::assertNull($e->getConsumedAt());
        }
    }

    /** Another 409 stays an ordinary ApiException. */
    public function testOtherConflictIsNotAlreadyConsumed(): void
    {
        $this->server = TestServer::always(409, self::error(409, 'something_else'));

        try {
            $this->client()->events->consume('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(EventAlreadyConsumedException::class, $e);
            self::assertFalse($e->isAlreadyConsumed());
        }
    }

    public function testConsumeOfAnUnknownEventThrowsNotFound(): void
    {
        $this->server = TestServer::always(404, Fixtures::notFound());

        try {
            $this->client()->events->consume('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(EventAlreadyConsumedException::class, $e);
            self::assertTrue($e->isEventNotFound());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    public function testConsumeRefusesADotSegmentRequestId(): void
    {
        $this->server = TestServer::always(200, self::CONSUMED);
        $client = $this->client();

        foreach (['', '.', '..'] as $requestId) {
            try {
                $client->events->consume($requestId);
                self::fail(sprintf('request id "%s" should have been refused', $requestId));
            } catch (ConfigurationException) {
            }
        }
        self::assertSame(0, $this->server->requestCount());
    }

    public function testVerifyOkReadsThenConsumesOnce(): void
    {
        $this->server = TestServer::start([
            ['status' => 200, 'body' => self::event(['tag' => 'checkout'])],
            ['status' => 200, 'body' => self::CONSUMED],
        ]);
        $result = $this->client()->events->verify('req_1', ['tag' => 'checkout']);

        self::assertTrue($result->ok);
        self::assertSame('', $result->reason);
        self::assertNotNull($result->event);
        self::assertSame('vis_1', $result->event->visitorId);

        $requests = $this->server->requests();
        self::assertCount(2, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('/api/v1/events/req_1', $requests[0]['path']);
        self::assertSame('POST', $requests[1]['method']);
        self::assertSame('/api/v1/events/req_1/consume', $requests[1]['path']);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($requests[1], 'x-api-key'));
    }

    public function testVerifyWithoutConsumeNeverPosts(): void
    {
        $this->server = TestServer::always(200, self::event());
        $result = $this->client()->events->verify('req_1', ['consume' => false]);

        self::assertTrue($result->ok);
        self::assertSame('', $result->reason);
        self::assertSame(0, $this->consumeCount());
    }

    public function testVerifyNotFound(): void
    {
        $this->server = TestServer::always(404, Fixtures::notFound());
        $result = $this->client()->events->verify('req_1');

        self::assertFalse($result->ok);
        self::assertSame(EventVerification::REASON_NOT_FOUND, $result->reason);
        self::assertNull($result->event);
        self::assertSame(0, $this->consumeCount());
    }

    /**
     * Each check that fails before consumption names its reason, carries the
     * event, and never consumes it.
     *
     * @dataProvider failures
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     */
    public function testVerifyFailsBeforeConsuming(array $fields, array $options, string $reason): void
    {
        $this->server = TestServer::start([
            ['status' => 200, 'body' => self::event($fields)],
            ['status' => 200, 'body' => self::CONSUMED],
        ]);
        $result = $this->client()->events->verify('req_1', $options);

        self::assertFalse($result->ok);
        self::assertSame($reason, $result->reason);
        self::assertContains($result->reason, EventVerification::REASONS);
        self::assertNotNull($result->event);
        self::assertSame('req_1', $result->event->requestId);
        self::assertSame(0, $this->consumeCount(), 'a failed check consumed the event');
        self::assertSame(1, $this->server->requestCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function failures(): iterable
    {
        yield 'too old by default' => [['occurred_at' => self::ago(400)], [], EventVerification::REASON_TOO_OLD];

        yield 'too old for max_age_seconds' => [['occurred_at' => self::ago(90)], ['max_age_seconds' => 60], EventVerification::REASON_TOO_OLD];

        yield 'unparseable time' => [['occurred_at' => 'yesterday'], [], EventVerification::REASON_TOO_OLD];

        yield 'impossible date' => [['occurred_at' => '2026-02-30T10:00:00Z'], [], EventVerification::REASON_TOO_OLD];

        yield 'missing time' => [['occurred_at' => null], [], EventVerification::REASON_TOO_OLD];

        yield 'tag mismatch' => [['tag' => 'login'], ['tag' => 'checkout'], EventVerification::REASON_TAG_MISMATCH];

        yield 'untagged event' => [[], ['tag' => 'checkout'], EventVerification::REASON_TAG_MISMATCH];

        yield 'tag is exact' => [['tag' => 'Checkout'], ['tag' => 'checkout'], EventVerification::REASON_TAG_MISMATCH];

        yield 'unidentifiable' => [['visitor_id' => ''], [], EventVerification::REASON_UNIDENTIFIABLE];

        yield 'missing visitor' => [['visitor_id' => null], [], EventVerification::REASON_UNIDENTIFIABLE];

        yield 'unscored' => [['suspect_score' => null, 'suspect_level' => null], [], EventVerification::REASON_UNSCORED];

        yield 'old beats tag, visitor and score' => [
            ['occurred_at' => self::ago(400), 'visitor_id' => '', 'suspect_score' => null],
            ['tag' => 'checkout'],
            EventVerification::REASON_TOO_OLD,
        ];

        yield 'tag beats visitor and score' => [
            ['visitor_id' => '', 'suspect_score' => null],
            ['tag' => 'checkout'],
            EventVerification::REASON_TAG_MISMATCH,
        ];

        yield 'visitor beats score' => [['visitor_id' => '', 'suspect_score' => null], [], EventVerification::REASON_UNIDENTIFIABLE];
    }

    public function testVerifyDefaultMaxAgeAcceptsARecentEvent(): void
    {
        $this->server = TestServer::always(200, self::event(['occurred_at' => self::ago(200)]));

        self::assertTrue($this->client()->events->verify('req_1', ['consume' => false])->ok);
    }

    public function testVerifyAcceptsAFutureEvent(): void
    {
        $this->server = TestServer::always(200, self::event(['occurred_at' => self::ago(-3600)]));

        self::assertTrue($this->client()->events->verify('req_1', ['consume' => false])->ok);
    }

    /** Verify never judges the score's value or level: that is the caller's policy. */
    public function testVerifyIgnoresTheScoreValue(): void
    {
        $this->server = TestServer::always(200, self::event(['suspect_score' => 95, 'suspect_level' => 'high']));

        self::assertTrue($this->client()->events->verify('req_1', ['consume' => false])->ok);
    }

    /** @dataProvider alreadyConsumedBodies */
    public function testVerifyAlreadyConsumed(string $body): void
    {
        $this->server = TestServer::start([
            ['status' => 200, 'body' => self::event()],
            ['status' => 409, 'body' => $body],
        ]);
        $result = $this->client()->events->verify('req_1');

        self::assertFalse($result->ok);
        self::assertSame(EventVerification::REASON_ALREADY_CONSUMED, $result->reason);
        self::assertNotNull($result->event);
        self::assertSame(1, $this->consumeCount());
    }

    public function testVerifyNotFoundOnConsume(): void
    {
        $this->server = TestServer::start([
            ['status' => 200, 'body' => self::event()],
            ['status' => 404, 'body' => Fixtures::notFound()],
        ]);
        $result = $this->client()->events->verify('req_1');

        self::assertFalse($result->ok);
        self::assertSame(EventVerification::REASON_NOT_FOUND, $result->reason);
        self::assertNull($result->event);
    }

    /** A pending event is not a verdict, so it is raised rather than mapped. */
    public function testVerifyRaisesPending(): void
    {
        $this->server = TestServer::always(404, Fixtures::pending(), ['retry-after' => '2']);

        try {
            $this->client()->events->verify('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertTrue($e->isPending());
            self::assertSame(2, $e->getRetryAfter());
        }
        self::assertSame(0, $this->consumeCount());
    }

    /** @dataProvider raised */
    public function testVerifyRaisesOtherReadErrors(int $status): void
    {
        $this->server = TestServer::always($status, self::error($status, 'other'));

        try {
            $this->client()->events->verify('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame($status, $e->getStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /** @dataProvider raised */
    public function testVerifyRaisesOtherConsumeErrors(int $status): void
    {
        $this->server = TestServer::start([
            ['status' => 200, 'body' => self::event()],
            ['status' => $status, 'body' => self::error($status, 'other')],
        ]);

        try {
            $this->client()->events->verify('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame($status, $e->getStatus());
        }
        self::assertSame(1, $this->consumeCount(), 'a consume was retried');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function raised(): iterable
    {
        yield '401' => [401];

        yield '429' => [429];

        yield '500' => [500];

        yield '503' => [503];
    }

    /**
     * @dataProvider badOptions
     *
     * @param array<string, mixed> $options
     */
    public function testVerifyRejectsBadOptionsBeforeAnyRequest(array $options): void
    {
        $this->server = TestServer::always(200, self::event());

        try {
            $this->client()->events->verify('req_1', $options);
            self::fail('accepted '.json_encode($options));
        } catch (ConfigurationException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame(0, $this->server->requestCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badOptions(): iterable
    {
        yield 'zero max age' => [['max_age_seconds' => 0]];

        yield 'negative max age' => [['max_age_seconds' => -5]];

        yield 'string max age' => [['max_age_seconds' => '300']];

        yield 'float max age' => [['max_age_seconds' => 1.5]];

        yield 'empty tag' => [['tag' => '']];

        yield 'non-string tag' => [['tag' => 7]];

        yield 'non-bool consume' => [['consume' => 'no']];

        yield 'unknown option' => [['maxAge' => 60]];
    }

    public function testVerifyRefusesADotSegmentRequestId(): void
    {
        $this->server = TestServer::always(200, self::event());

        foreach (['', '.', '..'] as $requestId) {
            try {
                $this->client()->events->verify($requestId);
                self::fail(sprintf('request id "%s" should have been refused', $requestId));
            } catch (ConfigurationException) {
            }
        }
        self::assertSame(0, $this->server->requestCount());
    }

    /** An RFC 3339 time this many seconds ago. */
    private static function ago(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', time() - $seconds);
    }

    /**
     * A recent, identifiable, scored event, with fields replaced or, when
     * null, removed.
     *
     * @param array<string, mixed> $fields
     */
    private static function event(array $fields = []): string
    {
        /** @var array<string, mixed> $body */
        $body = json_decode(Fixtures::event(), true, 32, \JSON_THROW_ON_ERROR);
        $body['occurred_at'] = self::ago(5);
        foreach ($fields as $name => $value) {
            if (null === $value && 'suspect_score' !== $name && 'suspect_level' !== $name) {
                unset($body[$name]);
            } else {
                $body[$name] = $value;
            }
        }

        return json_encode($body, \JSON_THROW_ON_ERROR);
    }

    private static function error(int $status, string $code): string
    {
        return json_encode(['error' => ['code' => $code, 'message' => $code, 'status' => $status]], \JSON_THROW_ON_ERROR);
    }

    private function consumeCount(): int
    {
        self::assertNotNull($this->server);

        return count(array_filter(
            $this->server->requests(),
            static fn (array $request): bool => str_ends_with($request['path'], '/consume'),
        ));
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl(), 'allow_insecure_loopback' => true]);
    }
}
