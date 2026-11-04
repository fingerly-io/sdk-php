<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\Proxy;
use Fingerly\Proxy\ProxyRequest;
use Fingerly\Proxy\ProxyResponse;
use PHPUnit\Framework\TestCase;

/**
 * The same-origin proxy core: what it refuses, what it forwards, and what it
 * relays back.
 *
 * @internal
 */
final class ProxyTest extends TestCase
{
    private const CLIENT_IP = '203.0.113.7';
    private const PATH = '/k7x2qm4a';
    private const IDENTIFIED = '{"request_id":"req_1","visitor_id":"vis_1"}';

    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testRequiresACredentialAndAClientIp(): void
    {
        $ip = fn (ProxyRequest $request): string => self::CLIENT_IP;

        foreach ([
            ['client_ip' => $ip],
            ['proxy_key' => TestServer::PROXY_KEY],
            ['proxy_key' => TestServer::PROXY_KEY, 'secret_key' => TestServer::SECRET_KEY, 'client_ip' => $ip],
            ['proxy_key' => TestServer::PROXY_KEY, 'client_ip' => 'not callable'],
        ] as $options) {
            try {
                new Proxy($options);
                self::fail('these options should have been refused');
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRefusesOptionsItCouldNotHonour(): void
    {
        $base = [
            'proxy_key' => TestServer::PROXY_KEY,
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ];

        foreach ([
            ['proxy_key' => TestServer::SECRET_KEY],
            ['path' => 'k7x2qm4a'],
            ['path' => '/'],
            ['path' => '///'],
            ['path' => ''],
            ['path' => '/a?b'],
            ['path' => '/a#b'],
            ['path' => self::PATH.'/'],
            ['path' => '//k7x2qm4a'],
            ['path' => '/a/../b'],
            ['path' => '/a/./b'],
            ['path' => '/a%2Fb'],
            ['path' => ' /k7x2qm4a'],
            ['path' => false],
            ['api_version' => 'beta'],
            ['max_body_bytes' => 0],
            ['timeout' => 0],
            ['upstream' => 'https://user:pass@example.com'],
            ['on_error' => 'not callable'],
            ['prefix' => '/k7x2qm4a'],
        ] as $override) {
            try {
                new Proxy(array_merge($base, $override));
                self::fail(sprintf('%s should have been refused', json_encode(array_keys($override))));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testForwardsToTheIngestUrlAndSendsOnlyVettedMetadata(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle($this->identify(self::PATH));

        self::assertSame(200, $response->status);
        self::assertSame(self::IDENTIFIED, $response->body);

        $forwarded = $this->server->first();
        self::assertSame('POST', $forwarded['method']);
        self::assertSame('/api/v1/ingest', $forwarded['path']);
        self::assertSame('{"signals":{}}', $forwarded['body']);
        self::assertSame(TestServer::PUBLIC_KEY, TestServer::header($forwarded, 'x-api-key'));
        self::assertSame(TestServer::PROXY_KEY, TestServer::header($forwarded, 'x-fingerly-proxy-key'));
        self::assertSame(self::CLIENT_IP, TestServer::header($forwarded, 'x-fingerly-client-ip'));
        self::assertSame('https://shop.example.com', TestServer::header($forwarded, 'x-fingerly-origin'));
        self::assertSame('Mozilla/5.0', TestServer::header($forwarded, 'x-fingerly-user-agent'));
        self::assertStringStartsWith('fingerly-php/', TestServer::header($forwarded, 'user-agent'));
        self::assertSame('php/'.Client::VERSION, TestServer::header($forwarded, 'x-fingerly-proxy-version'));
        self::assertMatchesRegularExpression(
            '~\A[a-z0-9][a-z0-9-]{0,31}/[0-9A-Za-z.+-]{1,32}\z~',
            TestServer::header($forwarded, 'x-fingerly-proxy-version'),
        );

        // The customer's own credentials are never passed on.
        self::assertSame('', TestServer::header($forwarded, 'cookie'));
        self::assertSame('', TestServer::header($forwarded, 'authorization'));
    }

    /**
     * The deferred report goes to the same path and the same URL; the API
     * tells the two calls apart by their bodies.
     */
    public function testForwardsTheDeferredReportToTheSameUrl(): void
    {
        $this->server = TestServer::always(202, '{"request_id":"req_1","duplicate":false}');
        $response = $this->proxy()->handle(new ProxyRequest(
            method: 'POST',
            path: self::PATH,
            headers: ['x-api-key' => TestServer::PUBLIC_KEY, 'content-type' => 'application/json'],
            body: '{"token":"tok_1"}',
        ));

        self::assertSame(202, $response->status);
        self::assertSame('/api/v1/ingest', $this->server->first()['path']);
        self::assertSame('{"token":"tok_1"}', $this->server->first()['body']);
    }

    /** api_version only chooses the upstream URL; the local path is the same. */
    public function testForwardsToTheConfiguredVersionsIngestUrl(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'api_version' => 'v2',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        self::assertSame(200, $proxy->handle($this->identify(self::PATH))->status);
        self::assertSame('/api/v2/ingest', $this->server->first()['path']);
    }

    public function testPassesOnAnIdempotencyKeyWhenTheSdkSentOne(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $this->proxy()->handle($this->identify(self::PATH, ['idempotency-key' => 'abc-123']));

        self::assertSame('abc-123', TestServer::header($this->server->first(), 'idempotency-key'));
    }

    /**
     * The path is the proxy's byte for byte. Anything a server, a CDN or a
     * PSR-7 library might read as the same path is still not it, so nothing
     * can reach the proxy by a spelling its configuration did not name.
     */
    public function testMatchesThePathByteForByte(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = $this->proxy();

        foreach ([
            self::PATH.'/',
            self::PATH.'//',
            '/'.self::PATH,
            self::PATH.'/.',
            '/.'.self::PATH,
            '/x/..'.self::PATH,
            self::PATH.'%2F',
            '/%6B7x2qm4a',
            strtoupper(self::PATH),
            self::PATH.' ',
        ] as $path) {
            self::assertFalse($proxy->handles($this->identify($path)), $path.' was claimed');
        }
        self::assertSame(200, $proxy->handle($this->identify(self::PATH))->status);
        self::assertSame(1, $this->server->requestCount());
    }

    /** Without a path the framework's route has already chosen the request. */
    public function testWithoutAPathAnswersWhateverTheFrameworkRoutedToIt(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        self::assertNull($proxy->path());
        self::assertTrue($proxy->handles($this->identify('/anything')));
        self::assertSame(200, $proxy->handle($this->identify('/anything'))->status);
        self::assertSame('/api/v1/ingest', $this->server->first()['path']);
    }

    public function testRefusesEveryMethodButPost(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle(new ProxyRequest(
            method: 'GET',
            path: self::PATH,
            headers: ['x-api-key' => TestServer::PUBLIC_KEY],
        ));

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->headers['allow']);

        // The path is checked first, so another path is not found at all.
        $elsewhere = $this->proxy()->handle(new ProxyRequest(method: 'GET', path: '/other'));
        self::assertSame(404, $elsewhere->status);
        self::assertSame(0, $this->server->requestCount());
    }

    /** The path is matched exactly: nothing under it and nothing beside it. */
    public function testRefusesEveryPathButItsOwn(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = $this->proxy();

        foreach ([
            self::PATH.'/admin',
            self::PATH.'/api/v1/identify',
            self::PATH.'x',
            '/other',
            '/api/v1/events',
            '/',
            '',
        ] as $path) {
            $request = $this->identify($path);
            self::assertFalse($proxy->handles($request), $path.' was claimed');
            self::assertSame(404, $proxy->handle($request)->status, $path.' was proxied');
        }
        self::assertSame(0, $this->server->requestCount());
    }

    /**
     * With a proxy key the browser must present a public key for the same
     * deployment; the proxy forwards it alongside its own.
     */
    public function testRequiresAMatchingPublicKeyFromTheBrowser(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = $this->proxy();

        foreach ([
            '',
            TestServer::SECRET_KEY,
            TestServer::PROXY_KEY,
            'fly_pk_staging_otherenvironment',
            'not-a-key',
        ] as $presented) {
            $request = $this->identify(self::PATH, ['x-api-key' => $presented]);
            self::assertSame(401, $proxy->handle($request)->status, $presented.' was accepted');
        }
        self::assertSame(0, $this->server->requestCount());
    }

    /**
     * The deprecated mode authenticates on its own, so it accepts whatever the
     * browser presents and never forwards it.
     */
    public function testTheDeprecatedSecretKeyModeAuthenticatesOnItsOwn(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'secret_key' => TestServer::SECRET_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        $response = $proxy->handle($this->identify(self::PATH, ['x-api-key' => '']));

        self::assertSame(200, $response->status);
        self::assertSame('/api/v1/ingest', $this->server->first()['path']);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($this->server->first(), 'x-api-key'));
        self::assertSame('', TestServer::header($this->server->first(), 'x-fingerly-proxy-key'));
    }

    public function testRefusesABodyThatDeclaresItselfTooLarge(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle(
            $this->identify(self::PATH, ['content-length' => '2000000']),
        );

        self::assertSame(413, $response->status);
        self::assertSame(0, $this->server->requestCount());
    }

    /** The declared size is only a hint; the real one is enforced while reading. */
    public function testRefusesABodyThatTurnsOutTooLarge(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'max_body_bytes' => 16,
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        // Both shapes a framework can hand over: a buffered string, and a
        // stream the core stops reading at its own limit.
        $stream = fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat('x', 64));
        rewind($stream);

        foreach ([str_repeat('x', 64), $stream] as $body) {
            $response = $proxy->handle(new ProxyRequest(
                method: 'POST',
                path: self::PATH,
                headers: ['x-api-key' => TestServer::PUBLIC_KEY],
                body: $body,
            ));
            self::assertSame(413, $response->status);
        }
        self::assertSame(0, $this->server->requestCount());
    }

    /**
     * Only the allowlisted headers are relayed. A content blocker can match a
     * response header by name, so nothing relayed may name Fingerly, and the
     * upstream framing headers would misdescribe the bytes we hand back.
     */
    public function testRelaysOnlyTheAllowlistedHeaders(): void
    {
        $this->server = TestServer::always(429, '{}', [
            'x-request-id' => 'req_trace',
            'ratelimit-limit' => '100',
            'ratelimit-remaining' => '0',
            'retry-after' => '7',
            'fingerly-balance-micros' => '100',
            'x-fingerly-request-id' => 'req_trace',
            'set-cookie' => 'a=b',
        ]);

        $response = $this->proxy()->handle($this->identify(self::PATH));

        self::assertSame([
            'cache-control' => 'no-store',
            'content-type' => 'application/json',
            'x-request-id' => 'req_trace',
            'ratelimit-limit' => '100',
            'ratelimit-remaining' => '0',
            'retry-after' => '7',
        ], $response->headers);
        foreach ($response->headers as $name => $value) {
            self::assertStringNotContainsStringIgnoringCase('fingerly', $name.': '.$value);
        }
    }

    public function testRelaysAnUpstreamRefusalUnchanged(): void
    {
        $this->server = TestServer::always(429, '{"error":{"code":"rate_limited","message":"slow","status":429}}');
        $response = $this->proxy()->handle($this->identify(self::PATH));

        self::assertSame(429, $response->status);
        self::assertStringContainsString('rate_limited', $response->body);
    }

    public function testSendsNoBodyOnAStatusThatMayNotCarryOne(): void
    {
        $this->server = TestServer::always(204, '');
        $response = $this->proxy()->handle($this->identify(self::PATH));

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    /**
     * The documented behaviour is to give Fingerly its deadline and then
     * answer, not to hold the browser's connection open.
     */
    public function testAnswers504WhenTheUpstreamStalls(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED, [], 5.0);
        $lines = [];
        $proxy = $this->failingProxy($this->server->baseUrl(), $lines);

        $started = microtime(true);
        $response = $proxy->handle($this->identify(self::PATH));

        self::assertLessThan(2.0, microtime(true) - $started);
        $this->assertGatewayFailure($response, 504, 'Gateway timeout');
        self::assertSame(['fingerly proxy: upstream timed out after 0.3s'], $lines);
    }

    /**
     * One deadline covers the whole exchange, body included, so an upstream
     * that keeps sending a byte at a time cannot stretch it.
     */
    public function testATricklingUpstreamCannotExtendTheDeadline(): void
    {
        $this->server = TestServer::start([[
            'status' => 200, 'body' => str_repeat(' ', 40).self::IDENTIFIED, 'trickle' => 0.05,
        ]]);
        $lines = [];
        $proxy = $this->failingProxy($this->server->baseUrl(), $lines);

        $started = microtime(true);
        $response = $proxy->handle($this->identify(self::PATH));

        self::assertLessThan(1.5, microtime(true) - $started);
        $this->assertGatewayFailure($response, 504, 'Gateway timeout');
    }

    public function testAnswers502WhenTheUpstreamIsUnreachable(): void
    {
        $lines = [];
        // Port 1 is reserved and nothing listens on it.
        $proxy = $this->failingProxy('http://127.0.0.1:1', $lines);

        $this->assertGatewayFailure($proxy->handle($this->identify(self::PATH)), 502, 'Bad gateway');
        self::assertCount(1, $lines);
        self::assertStringStartsWith('fingerly proxy: upstream request failed: ', $lines[0]);
        self::assertStringNotContainsString("\n", $lines[0]);
    }

    /** A success the browser cannot read is not relayed as one. */
    public function testAnswers502WhenASuccessIsNotJson(): void
    {
        $this->server = TestServer::always(200, '{"request_id": "req_1", oops');
        $lines = [];
        $proxy = $this->failingProxy($this->server->baseUrl(), $lines);

        $this->assertGatewayFailure($proxy->handle($this->identify(self::PATH)), 502, 'Bad gateway');
        self::assertSame(['fingerly proxy: upstream answered 200 with a body that is not JSON'], $lines);
    }

    /** Logging is the integration's business; a hook that throws changes nothing. */
    public function testAThrowingErrorHookDoesNotChangeTheAnswer(): void
    {
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => 'http://127.0.0.1:1',
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
            'on_error' => static function (string $line): void {
                throw new \RuntimeException('the logger is down');
            },
        ]);

        self::assertSame(502, $proxy->handle($this->identify(self::PATH))->status);
    }

    /**
     * A client_ip that cannot be a header value is a bug in the integration,
     * and it must not become a header injection.
     */
    public function testRefusesAClientIpThatIsNotAPlainAddress(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => "1.2.3.4\r\nx-injected: yes",
        ]);

        self::assertSame(500, $proxy->handle($this->identify(self::PATH))->status);
        self::assertSame(0, $this->server->requestCount());
    }

    /** The callback sees the request, so it can read whatever header you trust. */
    public function testReadsTheAddressFromTheRequestTheIntegrationChose(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => $request->header('cf-connecting-ip'),
        ]);

        $proxy->handle($this->identify(self::PATH, ['cf-connecting-ip' => '198.51.100.9']));

        self::assertSame('198.51.100.9', TestServer::header($this->server->first(), 'x-fingerly-client-ip'));
    }

    /** A framework with no adapter builds the request itself. */
    public function testBuildsARequestFromSuperglobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = self::PATH.'?ignored=1';
        $_SERVER['HTTP_X_API_KEY'] = TestServer::PUBLIC_KEY;
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $request = ProxyRequest::fromGlobals();

        self::assertSame('POST', $request->method);
        self::assertSame(self::PATH, $request->path);

        // A path that parse_url() would read as a host name stays a path.
        $_SERVER['REQUEST_URI'] = '/'.self::PATH;
        self::assertSame('/'.self::PATH, ProxyRequest::fromGlobals()->path);
        self::assertSame(TestServer::PUBLIC_KEY, $request->header('x-api-key'));
        self::assertSame('application/json', $request->header('content-type'));
    }

    public function testAResponseCanBeWrittenDirectly(): void
    {
        $response = new ProxyResponse(204, ['cache-control' => 'no-store'], '');

        ob_start();
        $response->send();
        self::assertSame('', (string) ob_get_clean());
    }

    /**
     * A proxy with a short deadline that records what it reports.
     *
     * @param list<string> $lines
     */
    private function failingProxy(string $upstream, array &$lines): Proxy
    {
        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $upstream,
            'timeout' => 0.3,
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
            'on_error' => static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ]);
    }

    /** A short plain-text answer that is never cached and names nobody. */
    private function assertGatewayFailure(ProxyResponse $response, int $status, string $body): void
    {
        self::assertSame($status, $response->status);
        self::assertSame($body, $response->body);
        self::assertSame('no-store', $response->headers['cache-control'] ?? null);
        self::assertSame('text/plain; charset=utf-8', $response->headers['content-type'] ?? null);
        foreach ($response->headers as $name => $value) {
            self::assertStringNotContainsStringIgnoringCase('fingerly', $name.': '.$value);
        }
        self::assertStringNotContainsStringIgnoringCase('fingerly', $response->body);
    }

    private function proxy(): Proxy
    {
        self::assertNotNull($this->server);

        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);
    }

    /**
     * Builds an identification request as the browser SDK sends one.
     *
     * @param array<string, string> $overrides
     */
    private function identify(string $path, array $overrides = []): ProxyRequest
    {
        return new ProxyRequest(
            method: 'POST',
            path: $path,
            headers: array_merge([
                'x-api-key' => TestServer::PUBLIC_KEY,
                'content-type' => 'application/json',
                'origin' => 'https://shop.example.com',
                'user-agent' => 'Mozilla/5.0',
                'cookie' => 'session=secret',
                'authorization' => 'Bearer customer-token',
            ], $overrides),
            body: '{"signals":{}}',
        );
    }
}
