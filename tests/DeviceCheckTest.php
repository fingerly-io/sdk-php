<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * Reading and writing an event's DeviceCheck bits.
 *
 * @internal
 */
final class DeviceCheckTest extends TestCase
{
    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testQueryReadsTheBits(): void
    {
        $this->server = TestServer::always(200, '{"found":true,"bit0":true,"bit1":false,"last_update":"2026-10"}');
        $bits = $this->client()->deviceCheck->query('req_1');

        self::assertTrue($bits->found);
        self::assertTrue($bits->bit0);
        self::assertFalse($bits->bit1);
        self::assertSame('2026-10', $bits->lastUpdate);

        $request = $this->server->first();
        self::assertSame('POST', $request['method']);
        self::assertSame('/api/v1/events/req_1/device-check/query', $request['path']);
        self::assertSame('', $request['body']);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($request, 'x-api-key'));
    }

    public function testQueryWithNoBitsYet(): void
    {
        $this->server = TestServer::always(200, '{"found":false,"bit0":false,"bit1":false}');
        $bits = $this->client()->deviceCheck->query('req_1');

        self::assertFalse($bits->found);
        self::assertNull($bits->lastUpdate);
    }

    public function testWriteSendsOnlyTheNamedBits(): void
    {
        $this->server = TestServer::always(200, '{"bit0":true}');
        $result = $this->client()->deviceCheck->write('req/1', ['bit0' => true]);

        self::assertTrue($result->bit0);
        self::assertNull($result->bit1);

        $request = $this->server->first();
        self::assertSame('PUT', $request['method']);
        self::assertSame('/api/v1/events/req%2F1/device-check', $request['path']);
        self::assertSame('{"bit0":true}', $request['body']);
        self::assertSame('application/json', TestServer::header($request, 'content-type'));
    }

    public function testWriteNeedsABit(): void
    {
        $this->server = TestServer::always(200, '{}');
        $client = $this->client();

        foreach ([[], ['bit0' => null], ['bit2' => true], ['bit1' => 1]] as $bits) {
            try {
                $client->deviceCheck->write('req_1', $bits);
                self::fail('a write without a usable bit was sent: '.json_encode($bits));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(0, $this->server->requestCount());
    }

    public function testRefusesADotSegmentRequestId(): void
    {
        $this->server = TestServer::always(200, '{}');
        $this->expectException(ConfigurationException::class);
        $this->client()->deviceCheck->query('..');
    }

    /**
     * Each error is told apart by its code, and none of them is retried.
     *
     * @dataProvider errors
     */
    public function testErrorsAreTypedByCodeAndNotRetried(int $status, string $code, string $predicate): void
    {
        $this->server = TestServer::always($status, self::error($status, $code));

        try {
            $this->client()->deviceCheck->write('req_1', ['bit1' => true]);
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame($status, $e->getStatus());
            self::assertSame($code, $e->getErrorCode());
            self::assertTrue($e->{$predicate}());
            self::assertNull($e->getAppleStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function errors(): iterable
    {
        yield 'not held' => [404, 'device_check_not_held', 'isDeviceCheckNotHeld'];

        yield 'spent' => [409, 'device_check_spent', 'isDeviceCheckSpent'];

        yield 'busy' => [409, 'device_check_busy', 'isDeviceCheckBusy'];

        yield 'credential' => [409, 'credential_unavailable', 'isCredentialUnavailable'];

        yield 'bits required' => [422, 'bits_required', 'isBitsRequired'];
    }

    public function testAppleRejectedCarriesAppleStatus(): void
    {
        $this->server = TestServer::always(422, json_encode(['error' => [
            'code' => 'apple_rejected', 'message' => 'Apple rejected the request (HTTP 400)', 'status' => 422,
            'details' => [['field' => 'apple_status', 'location' => 'upstream', 'issue' => '400']],
        ]], \JSON_THROW_ON_ERROR));

        try {
            $this->client()->deviceCheck->query('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertTrue($e->isAppleRejected());
            self::assertSame(400, $e->getAppleStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /** A 503 keeps the token, so the identical request is sent again. */
    public function testRetriesA503WithTheIdenticalRequest(): void
    {
        $this->server = TestServer::start([
            ['status' => 503, 'body' => self::error(503, 'device_check_unavailable')],
            ['status' => 200, 'body' => '{"bit0":false,"bit1":true}'],
        ]);
        $result = $this->client()->deviceCheck->write('req_1', ['bit0' => false, 'bit1' => true]);

        self::assertTrue($result->bit1);
        $requests = $this->server->requests();
        self::assertCount(2, $requests);
        foreach (['method', 'path', 'body'] as $part) {
            self::assertSame($requests[0][$part], $requests[1][$part]);
        }
        self::assertSame('{"bit0":false,"bit1":true}', $requests[1]['body']);
    }

    public function testGivesUpAfterTheRetries(): void
    {
        $this->server = TestServer::always(503, self::error(503, 'device_check_unavailable'));

        try {
            $this->client()->deviceCheck->query('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertTrue($e->isDeviceCheckUnavailable());
        }
        self::assertSame(3, $this->server->requestCount());
    }

    /** @dataProvider unretried */
    public function testOtherFailuresAreNotRetried(int $status): void
    {
        $this->server = TestServer::always($status, self::error($status, 'other'));

        try {
            $this->client()->deviceCheck->query('req_1');
            self::fail('no exception');
        } catch (ApiException $e) {
            self::assertSame($status, $e->getStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unretried(): iterable
    {
        yield '429' => [429];

        yield '500' => [500];

        yield '502' => [502];
    }

    /** A connection that cannot be made is retried, then reported. */
    public function testRetriesATransportFailure(): void
    {
        $client = new Client(TestServer::SECRET_KEY, ['endpoint' => 'http://127.0.0.1:1', 'allow_insecure_loopback' => true]);
        $started = microtime(true);

        try {
            $client->deviceCheck->query('req_1');
            self::fail('no exception');
        } catch (ConnectionException $e) {
            self::assertSame('connect', $e->kind);
        }
        // Two pauses between the three attempts.
        self::assertGreaterThan(0.7, microtime(true) - $started);
    }

    private static function error(int $status, string $code): string
    {
        return json_encode(['error' => ['code' => $code, 'message' => $code, 'status' => $status]], \JSON_THROW_ON_ERROR);
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl(), 'allow_insecure_loopback' => true]);
    }
}
