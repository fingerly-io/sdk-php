<?php

declare(strict_types=1);

namespace Fingerly\Tests;

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
            ['prefix' => 'metrics'],
            ['prefix' => '/a?b'],
            ['prefix' => '/a#b'],
            ['api_version' => 'beta'],
            ['max_body_bytes' => 0],
            ['timeout' => 0],
            ['upstream' => 'https://user:pass@example.com'],
            ['prefx' => '/metrics'],
        ] as $override) {
            try {
                new Proxy(array_merge($base, $override));
                self::fail(sprintf('%s should have been refused', json_encode(array_keys($override))));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testForwardsIdentifyAndSendsOnlyVettedMetadata(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle($this->identify('/metrics/api/v1/identify'));

        self::assertSame(200, $response->status);
        self::assertSame(self::IDENTIFIED, $response->body);

        $forwarded = $this->server->first();
        self::assertSame('POST', $forwarded['method']);
        self::assertSame('/api/v1/identify', $forwarded['path']);
        self::assertSame('{"signals":{}}', $forwarded['body']);
        self::assertSame(TestServer::PUBLIC_KEY, TestServer::header($forwarded, 'x-api-key'));
        self::assertSame(TestServer::PROXY_KEY, TestServer::header($forwarded, 'x-fingerly-proxy-key'));
        self::assertSame(self::CLIENT_IP, TestServer::header($forwarded, 'x-fingerly-client-ip'));
        self::assertSame('https://shop.example.com', TestServer::header($forwarded, 'x-fingerly-origin'));
        self::assertSame('Mozilla/5.0', TestServer::header($forwarded, 'x-fingerly-user-agent'));
        self::assertStringStartsWith('fingerly-php/', TestServer::header($forwarded, 'user-agent'));

        // The customer's own credentials are never passed on.
        self::assertSame('', TestServer::header($forwarded, 'cookie'));
        self::assertSame('', TestServer::header($forwarded, 'authorization'));
    }

    public function testForwardsASupplementForOneRequestId(): void
    {
        $this->server = TestServer::always(202, '{}');
        $response = $this->proxy()->handle($this->identify('/metrics/api/v1/events/req_1/supplement'));

        self::assertSame(202, $response->status);
        self::assertSame('/api/v1/events/req_1/supplement', $this->server->first()['path']);
    }

    public function testPassesOnAnIdempotencyKeyWhenTheSdkSentOne(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $this->proxy()->handle($this->identify('/metrics/api/v1/identify', ['idempotency-key' => 'abc-123']));

        self::assertSame('abc-123', TestServer::header($this->server->first(), 'idempotency-key'));
    }

    /**
     * A framework that mounts the middleware under a path removes it before
     * the adapter sees it, and gives no way to detect that it did.
     */
    public function testAcceptsThePrefixPresentOrAlreadyStripped(): void
    {
        foreach (['/metrics/api/v1/identify', '/api/v1/identify'] as $path) {
            $this->server?->stop();
            $this->server = TestServer::always(200, self::IDENTIFIED);

            $response = $this->proxy()->handle($this->identify($path));
            self::assertSame(200, $response->status, $path.' was refused');
            self::assertSame('/api/v1/identify', $this->server->first()['path']);
        }
    }

    public function testRefusesEveryMethodButPost(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle(new ProxyRequest(
            method: 'GET',
            path: '/metrics/api/v1/identify',
            headers: ['x-api-key' => TestServer::PUBLIC_KEY],
        ));

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->headers['allow']);
        self::assertSame(0, $this->server->requestCount());
    }

    /** Event reads need a secret key and must stay unreachable from a browser. */
    public function testRefusesEveryRouteButIdentifyAndSupplement(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = $this->proxy();

        foreach ([
            '/metrics/api/v1/events',
            '/metrics/api/v1/events/req_1',
            '/metrics/api/v1/events/req_1/supplement/extra',
            '/metrics/api/v1/events//supplement',
            '/metrics/api/v1/identify/extra',
            '/metrics/api/v2/identify',
            '/other/api/v1/identify',
            '/metrics/identify',
            '/',
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
            $request = $this->identify('/metrics/api/v1/identify', ['x-api-key' => $presented]);
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
            'prefix' => '/metrics',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        $response = $proxy->handle($this->identify('/metrics/api/v1/identify', ['x-api-key' => '']));

        self::assertSame(200, $response->status);
        self::assertSame(TestServer::SECRET_KEY, TestServer::header($this->server->first(), 'x-api-key'));
        self::assertSame('', TestServer::header($this->server->first(), 'x-fingerly-proxy-key'));
    }

    public function testRefusesABodyThatDeclaresItselfTooLarge(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle(
            $this->identify('/metrics/api/v1/identify', ['content-length' => '2000000']),
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
            'prefix' => '/metrics',
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
                path: '/metrics/api/v1/identify',
                headers: ['x-api-key' => TestServer::PUBLIC_KEY],
                body: $body,
            ));
            self::assertSame(413, $response->status);
        }
        self::assertSame(0, $this->server->requestCount());
    }

    /**
     * The body has already been read and framed by the time it is relayed, so
     * the upstream framing headers would misdescribe the bytes we hand back.
     * Everything else the API said is passed through.
     */
    public function testStripsTheHeadersThatDescribedTheUpstreamConnection(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED, [
            'x-fingerly-request-id' => 'req_trace',
        ]);

        $response = $this->proxy()->handle($this->identify('/metrics/api/v1/identify'));

        self::assertArrayNotHasKey('content-length', $response->headers);
        self::assertArrayNotHasKey('transfer-encoding', $response->headers);
        self::assertArrayNotHasKey('connection', $response->headers);
        self::assertSame('req_trace', $response->headers['x-fingerly-request-id']);
        self::assertSame('no-store', $response->headers['cache-control']);
    }

    public function testRelaysAnUpstreamRefusalUnchanged(): void
    {
        $this->server = TestServer::always(429, '{"error":{"code":"rate_limited","message":"slow","status":429}}');
        $response = $this->proxy()->handle($this->identify('/metrics/api/v1/identify'));

        self::assertSame(429, $response->status);
        self::assertStringContainsString('rate_limited', $response->body);
    }

    public function testSendsNoBodyOnAStatusThatMayNotCarryOne(): void
    {
        $this->server = TestServer::always(204, '');
        $response = $this->proxy()->handle($this->identify('/metrics/api/v1/identify'));

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    /**
     * The documented behaviour is to give Fingerly its deadline and then
     * answer, not to hold the browser's connection open.
     */
    public function testAnswers502WhenTheUpstreamStalls(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED, [], 5.0);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'prefix' => '/metrics',
            'upstream' => $this->server->baseUrl(),
            'timeout' => 0.3,
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        self::assertSame(502, $proxy->handle($this->identify('/metrics/api/v1/identify'))->status);
    }

    public function testAnswers502WhenTheUpstreamIsUnreachable(): void
    {
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'prefix' => '/metrics',
            // Port 1 is reserved and nothing listens on it.
            'upstream' => 'http://127.0.0.1:1',
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);

        self::assertSame(502, $proxy->handle($this->identify('/metrics/api/v1/identify'))->status);
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
            'prefix' => '/metrics',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => "1.2.3.4\r\nx-injected: yes",
        ]);

        self::assertSame(500, $proxy->handle($this->identify('/metrics/api/v1/identify'))->status);
        self::assertSame(0, $this->server->requestCount());
    }

    /** The callback sees the request, so it can read whatever header you trust. */
    public function testReadsTheAddressFromTheRequestTheIntegrationChose(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'prefix' => '/metrics',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => $request->header('cf-connecting-ip'),
        ]);

        $proxy->handle($this->identify('/metrics/api/v1/identify', ['cf-connecting-ip' => '198.51.100.9']));

        self::assertSame('198.51.100.9', TestServer::header($this->server->first(), 'x-fingerly-client-ip'));
    }

    /** A framework with no adapter builds the request itself. */
    public function testBuildsARequestFromSuperglobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/metrics/api/v1/identify?ignored=1';
        $_SERVER['HTTP_X_API_KEY'] = TestServer::PUBLIC_KEY;
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $request = ProxyRequest::fromGlobals();

        self::assertSame('POST', $request->method);
        self::assertSame('/metrics/api/v1/identify', $request->path);
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

    private function proxy(): Proxy
    {
        self::assertNotNull($this->server);

        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'prefix' => '/metrics',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => self::CLIENT_IP,
        ]);
    }

    /**
     * Builds an identify request as the browser SDK sends one.
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
