<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\ConnectionException;
use Fingerly\TimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * Construction, credentials and the shape of a single request.
 *
 * @internal
 */
final class ClientTest extends TestCase
{
    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testRefusesEveryKeyThatIsNotASecretKey(): void
    {
        $keys = [
            TestServer::PUBLIC_KEY,
            TestServer::PROXY_KEY,
            'fly_mk_us_production_management',
            'fly_sk_ap_production_wrongregion',
            'fly_sk_us_testing_wrongenvironment',
            'fly_sk_us_production_',
            'fly_sk_us_production_has spaces',
            "fly_sk_us_production_trailing\n",
            'sk_us_production_missingprefix',
            '',
        ];

        foreach ($keys as $key) {
            try {
                new Client($key);
                self::fail(sprintf('%s should have been refused', var_export($key, true)));
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('secret key', $e->getMessage());
            }
        }
    }

    public function testAcceptsASecretKeyForEitherRegion(): void
    {
        foreach (['fly_sk_us_production_abc', 'fly_sk_eu_staging_abc-DEF_123', 'fly_sk_eu_development_abc'] as $key) {
            self::assertInstanceOf(Client::class, new Client($key));
        }
    }

    /**
     * The key is validated even when an endpoint is given, so a public key can
     * never reach an API that would accept it.
     */
    public function testValidatesTheKeyEvenWithAnEndpoint(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client(TestServer::PUBLIC_KEY, ['endpoint' => 'http://127.0.0.1:1']);
    }

    public function testRefusesAnEndpointThatWouldLeakOrRewriteTheRequest(): void
    {
        $endpoints = [
            'https://user:pass@api.example.com',
            'https://api.example.com?token=x',
            'https://api.example.com#fragment',
            'ftp://api.example.com',
            'not a url',
            '',
        ];

        foreach ($endpoints as $endpoint) {
            try {
                new Client(TestServer::SECRET_KEY, ['endpoint' => $endpoint]);
                self::fail(sprintf('%s should have been refused', var_export($endpoint, true)));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRefusesAnApiVersionThatIsNotOne(): void
    {
        foreach (['', '1', 'v0', 'v01', 'v1beta', "v1\n", ' v1'] as $version) {
            try {
                new Client(TestServer::SECRET_KEY, ['api_version' => $version]);
                self::fail(sprintf('%s should have been refused', var_export($version, true)));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertInstanceOf(Client::class, new Client(TestServer::SECRET_KEY, ['api_version' => 'v2']));
    }

    public function testRefusesADeadlineThatHasAlreadyPassed(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client(TestServer::SECRET_KEY, ['timeout' => 0]);
    }

    /** A misspelled option is otherwise silently ignored. */
    public function testRefusesAnOptionItDoesNotKnow(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/timeut/');
        new Client(TestServer::SECRET_KEY, ['timeut' => 5]);
    }

    public function testSendsTheKeyAndIdentifiesTheSdk(): void
    {
        $this->server = TestServer::always(200, Fixtures::event());
        $this->client()->events->get('req_1');

        $request = $this->server->first();
        self::assertSame('GET', $request['method']);
        self::assertSame('/api/v1/events/req_1', $request['path']);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($request, 'x-api-key'));
        self::assertSame('application/json', TestServer::header($request, 'accept'));
        self::assertStringStartsWith('fingerly-php/', TestServer::header($request, 'user-agent'));
    }

    public function testHonoursACustomApiVersion(): void
    {
        $this->server = TestServer::always(200, Fixtures::event());
        $client = new Client(TestServer::SECRET_KEY, [
            'endpoint' => $this->server->baseUrl(),
            'api_version' => 'v2',
        ]);
        $client->events->get('req_1');

        self::assertSame('/api/v2/events/req_1', $this->server->first()['path']);
    }

    /**
     * A redirect would replay the key to another host, so it is surfaced as
     * the status it is rather than followed.
     */
    public function testNeverFollowsARedirect(): void
    {
        $this->server = TestServer::always(302, '', ['location' => 'https://attacker.example.com/api/v1/events']);

        try {
            $this->client()->events->get('req_1');
            self::fail('the redirect was followed');
        } catch (ApiException $e) {
            self::assertSame(302, $e->getStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    public function testMapsAnErrorEnvelopeWithoutEchoingTheBody(): void
    {
        $this->server = TestServer::always(422, json_encode([
            'error' => [
                'code' => 'invalid_request',
                'message' => 'secret sauce',
                'status' => 422,
                'details' => [['field' => 'limit']],
            ],
            'request_id' => 'req_trace',
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->client()->events->get('req_1');
            self::fail('the request should have failed');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatus());
            self::assertSame('invalid_request', $e->getErrorCode());
            self::assertSame('req_trace', $e->getRequestId());
            self::assertSame([['field' => 'limit']], $e->getDetails());
            self::assertStringNotContainsString('secret sauce', $e->getMessage());
            // Throwable::getCode() carries the status too.
            self::assertSame(422, $e->getCode());
        }
    }

    /**
     * An error body the SDK cannot parse still reports its status, and its
     * contents are dropped rather than surfaced.
     */
    public function testReportsTheStatusOfAnUnparsableErrorBody(): void
    {
        $this->server = TestServer::always(503, '<html>upstream down</html>');

        try {
            $this->client()->events->get('req_1');
            self::fail('the request should have failed');
        } catch (ApiException $e) {
            self::assertSame(503, $e->getStatus());
            self::assertNull($e->getErrorCode());
            self::assertStringNotContainsString('upstream', $e->getMessage());
        }
    }

    /**
     * Anything an error carries to a log is scrubbed of the key, including a
     * code or trace id the API echoed it into.
     */
    public function testRedactsTheSecretKeyFromErrorMetadata(): void
    {
        $key = TestServer::SECRET_KEY;
        $this->server = TestServer::always(400, json_encode([
            'error' => [
                'code' => 'bad_key_'.$key,
                'message' => 'x',
                'status' => 400,
                'details' => ['seen' => $key],
            ],
            'request_id' => $key,
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->client()->events->get('req_1');
            self::fail('the request should have failed');
        } catch (ApiException $e) {
            self::assertSame('bad_key_[redacted]', $e->getErrorCode());
            self::assertSame('[redacted]', $e->getRequestId());
            self::assertSame(['seen' => '[redacted]'], $e->getDetails());
        }
    }

    /**
     * The deadline covers reading the body, so a server that answers and then
     * stalls times out like one that never answered.
     */
    public function testTimesOutAResponseThatNeverFinishes(): void
    {
        $this->server = TestServer::always(200, Fixtures::event(), [], 5.0);
        $client = new Client(TestServer::SECRET_KEY, [
            'endpoint' => $this->server->baseUrl(),
            'timeout' => 0.3,
        ]);

        try {
            $client->events->get('req_1');
            self::fail('the request should have timed out');
        } catch (TimeoutException $e) {
            self::assertSame(TimeoutException::OPERATION_REQUEST, $e->getOperation());
        }
    }

    /** An unreachable API is a connection failure, not a malformed response. */
    public function testReportsAnUnreachableApiAsAConnectionFailure(): void
    {
        // Port 1 is reserved and nothing listens on it.
        $client = new Client(TestServer::SECRET_KEY, ['endpoint' => 'http://127.0.0.1:1', 'timeout' => 2]);

        $this->expectException(ConnectionException::class);
        $client->events->get('req_1');
    }

    public function testReportsABodyThatIsNotAnEvent(): void
    {
        $this->server = TestServer::always(200, '{"unexpected":true}');

        try {
            $this->client()->events->get('req_1');
            self::fail('the body should have been refused');
        } catch (ApiException $e) {
            self::assertSame('invalid_response', $e->getErrorCode());
        }
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);
    }
}
