<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\Proxy;
use Fingerly\Proxy\LaravelMiddleware;
use Fingerly\Proxy\ProxyRequest;
use Fingerly\Proxy\Psr15Middleware;
use Fingerly\Proxy\SymfonyListener;
use Illuminate\Http\Request as LaravelRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Edge cases of the first-party proxy contract that an ordinary request never
 * reaches, one group per rule.
 *
 * Every upstream is a mock on 127.0.0.1; names under `.invalid` are only ever
 * constructed against, never sent to.
 *
 * @internal
 */
final class ProxyHardeningTest extends TestCase
{
    private const CLIENT_IP = '203.0.113.7';
    private const PATH = '/k7x2qm4a';
    private const IDENTIFIED = '{"request_id":"req_1","visitor_id":"vis_1"}';
    private const ORIGIN = 'https://shop.example.com';

    /** Raw request targets that are not the proxy's path. */
    private const NOT_THE_PATH = [
        '/k7x2qm4a/',
        '//k7x2qm4a',
        '/k7x2qm4a/.',
        '/./k7x2qm4a',
        '/x/../k7x2qm4a',
        '/k7x2qm4a/..',
        '/%6b7x2qm4a',
        '/k7x2qm4a%2f',
        '/k7x2qm4a%00',
        '/k7x2qm4a%20',
        '/k7x2qm4a;a=b',
        '/K7X2QM4A',
        '/k7x2qm4a/ingest',
        '/index.php/k7x2qm4a',
    ];

    private ?TestServer $server = null;

    /** @var null|resource */
    private $rawServer;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        $this->stopRawServer();
    }

    // Channel headers.

    public function testAnOriginWinsOverAValidPlatformHeader(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle($this->identify(['x-fingerly-sdk-platform' => 'ios']));

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->server->requestCount());
        $forwarded = $this->server->first();
        self::assertSame(self::ORIGIN, TestServer::header($forwarded, 'x-fingerly-origin'));
        self::assertSame('', TestServer::header($forwarded, 'x-fingerly-sdk-platform'));
    }

    public function testEveryAdapterSendsOnlyTheOriginChannel(): void
    {
        foreach (['psr15', 'laravel', 'symfony'] as $adapter) {
            $this->server?->stop();
            $this->server = TestServer::always(200, self::IDENTIFIED);

            [$status] = $this->throughAdapter($adapter, self::PATH, ['HTTP_X_FINGERLY_SDK_PLATFORM' => 'android'], ['x-fingerly-sdk-platform' => 'android']);
            self::assertSame(200, $status, $adapter);
            $forwarded = $this->server->first();
            self::assertSame(self::ORIGIN, TestServer::header($forwarded, 'x-fingerly-origin'), $adapter);
            self::assertSame('', TestServer::header($forwarded, 'x-fingerly-sdk-platform'), $adapter);
        }
    }

    public function testAPlatformHeaderWithoutAnOriginIsStillRestored(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $response = $this->proxy()->handle($this->identify(['origin' => '', 'x-fingerly-sdk-platform' => 'flutter-ios']));

        self::assertSame(200, $response->status);
        self::assertSame('flutter-ios', TestServer::header($this->server->first(), 'x-fingerly-sdk-platform'));
        self::assertSame('', TestServer::header($this->server->first(), 'x-fingerly-origin'));
    }

    // Upstream option.

    public function testThePlainHttpUpstreamIsRefusedUnlessLoopbackAndOptedIn(): void
    {
        $accepted = [];
        foreach ([
            ['http://upstream.invalid', false],
            ['http://upstream.invalid', true],
            ['http://192.0.2.10:8080', true],
            ['http://127.0.0.2', true],
            ['http://127.0.0.1.invalid', true],
            ['http://127.0.0.1', false],
            ['http://localhost:8080', false],
            ['http://[::1]:8080', false],
        ] as [$upstream, $optIn]) {
            try {
                $this->construct(['upstream' => $upstream, 'allow_insecure_loopback' => $optIn]);
                $accepted[] = $upstream.($optIn ? ' (opted in)' : '');
            } catch (ConfigurationException $e) {
                self::assertStringNotContainsString($upstream, $e->getMessage());
            }
        }
        self::assertSame([], $accepted, 'accepted: '.implode(', ', $accepted));

        foreach (['http://127.0.0.1', 'http://127.0.0.1:8080', 'http://localhost', 'http://LOCALHOST:8080/', 'http://[::1]:8080'] as $upstream) {
            $this->construct(['upstream' => $upstream, 'allow_insecure_loopback' => true]);
        }
        foreach (['https://upstream.invalid', 'https://upstream.invalid/', 'https://upstream.invalid:8443', 'https://127.0.0.1'] as $upstream) {
            $this->construct(['upstream' => $upstream]);
        }
        $this->addToAssertionCount(1);
    }

    public function testTheLoopbackOptInMustBeABoolean(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->construct(['upstream' => 'http://127.0.0.1:1', 'allow_insecure_loopback' => 'yes']);
    }

    public function testTheUpstreamOptionRefusesAPathOrStrayBytes(): void
    {
        $accepted = [];
        foreach ([
            'https://upstream.invalid/prefix',
            'https://upstream.invalid/a/../b',
            'https://upstream.invalid//',
            "https://upstream.invalid\n",
            'https://upstream.invalid ',
            ' https://upstream.invalid',
            "https://upstream.invalid\r\nx-injected: 1",
            "https://upstream.invalid\t",
            "https://upstream.invalid\x00",
            'https://upstream.invalid:99999',
            'https://upstream.invalid:0',
            'https://upstream.invalid\@other.invalid',
            'ftp://upstream.invalid',
            'https://user@upstream.invalid',
            'https://user:pass@upstream.invalid',
            'https://upstream.invalid?x=1',
            'https://upstream.invalid?',
            'https://upstream.invalid#x',
            'https://upstream.invalid#',
            '//upstream.invalid',
            'upstream.invalid',
            'https://',
            'https://up_stream.invalid',
            '',
        ] as $upstream) {
            try {
                $this->construct(['upstream' => $upstream]);
                $accepted[] = json_encode($upstream);
            } catch (ConfigurationException) {
            }
        }
        self::assertSame([], $accepted, 'accepted as upstream: '.implode(' ', $accepted));
    }

    public function testTheForwardedTargetIsRebuiltFromTheParsedOrigin(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => strtoupper('http').substr($this->server->baseUrl(), 4).'/',
            'allow_insecure_loopback' => true,
            'client_ip' => static fn (ProxyRequest $r): string => self::CLIENT_IP,
        ]);

        self::assertSame(200, $proxy->handle($this->identify())->status);
        self::assertSame('/api/v1/ingest', $this->server->first()['path']);
    }

    public function testApiVersionCannotCarryAPath(): void
    {
        $accepted = [];
        foreach (['v1/../../x', '../v1', 'v1/', "v1\n", 'v1?x', 'V1', 'v0', 'v1 ', 'v1%2f..', ''] as $version) {
            try {
                $this->construct(['api_version' => $version]);
                $accepted[] = json_encode($version);
            } catch (ConfigurationException) {
            }
        }
        self::assertSame([], $accepted, 'accepted as api_version: '.implode(' ', $accepted));
    }

    // Path and method.

    public function testOnlyAnExactPostIsForwarded(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $forwarded = [];
        foreach (['post', 'Post', 'pOST', 'GET', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'options', 'HEAD', 'head', 'TRACE', 'CONNECT', 'POST ', ''] as $method) {
            $response = $this->proxy()->handle(new ProxyRequest(
                method: $method,
                path: self::PATH,
                headers: ['x-api-key' => TestServer::PUBLIC_KEY, 'origin' => self::ORIGIN],
                body: '{}',
            ));
            if (405 !== $response->status || 'POST' !== ($response->headers['allow'] ?? null)
                || 'no-store' !== ($response->headers['cache-control'] ?? null)) {
                $forwarded[] = json_encode($method).'=>'.$response->status;
            }
        }
        self::assertSame([], $forwarded, implode(' ', $forwarded));
        self::assertSame(0, $this->server->requestCount());
    }

    public function testWithCorsOnlyAnExactOptionsIsAPreflight(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'allow_insecure_loopback' => true,
            'allowed_origins' => [self::ORIGIN],
            'client_ip' => static fn (ProxyRequest $r): string => self::CLIENT_IP,
        ]);

        self::assertSame(204, $proxy->handle(new ProxyRequest('OPTIONS', self::PATH, ['origin' => self::ORIGIN]))->status);
        foreach (['options', 'Options', 'post'] as $method) {
            $response = $proxy->handle(new ProxyRequest($method, self::PATH, ['origin' => self::ORIGIN, 'x-api-key' => TestServer::PUBLIC_KEY], '{}'));
            self::assertSame(405, $response->status, $method);
            self::assertSame('POST, OPTIONS', $response->headers['allow'] ?? null, $method);
            self::assertSame('no-store', $response->headers['cache-control'] ?? null, $method);
        }
        self::assertSame(0, $this->server->requestCount());
    }

    public function testEveryAdapterRefusesALowercaseMethod(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        foreach (['psr15', 'laravel', 'symfony'] as $adapter) {
            foreach (['post', 'Post'] as $method) {
                [$status, $cache, $reachedApp] = $this->throughAdapter($adapter, self::PATH, ['REQUEST_METHOD' => $method]);
                self::assertSame(405, $status, "{$adapter} {$method}");
                self::assertFalse($reachedApp, "{$adapter} {$method}");
                self::assertStringContainsString('no-store', $cache, "{$adapter} {$method}");
            }
        }
        self::assertSame(0, $this->server->requestCount());
    }

    public function testTheCoreAnswers404ForEveryOtherSpellingOfThePath(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        foreach ([...self::NOT_THE_PATH, self::PATH.'?x=1', self::PATH."\n", self::PATH.' ', ''] as $path) {
            $response = $this->proxy()->handle($this->identify([], $path));
            self::assertSame(404, $response->status, (string) json_encode($path));
            self::assertSame('no-store', $response->headers['cache-control'] ?? null);
        }
        self::assertSame(0, $this->server->requestCount());
    }

    public function testEveryAdapterMatchesOnlyTheRawPath(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        $answered = [];
        foreach (['psr15', 'laravel', 'symfony'] as $adapter) {
            foreach (self::NOT_THE_PATH as $path) {
                [$status, , $reachedApp] = $this->throughAdapter($adapter, $path);
                if (!$reachedApp) {
                    $answered[] = $adapter.' '.$path.'=>'.$status;
                }
            }
        }
        self::assertSame(0, $this->server->requestCount(), implode(' ', $answered));
        self::assertSame([], $answered, implode(' ', $answered));
    }

    /**
     * Laravel and Symfony append `private` to a `Cache-Control` without a
     * visibility directive, so there it arrives as `no-store, private`.
     */
    public function testEveryLocalAnswerThroughEveryAdapterIsNoStore(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
        foreach (['psr15', 'laravel', 'symfony'] as $adapter) {
            $cases = [
                'GET' => $this->throughAdapter($adapter, self::PATH, ['REQUEST_METHOD' => 'GET']),
                'HEAD' => $this->throughAdapter($adapter, self::PATH, ['REQUEST_METHOD' => 'HEAD']),
                'OPTIONS' => $this->throughAdapter($adapter, self::PATH, ['REQUEST_METHOD' => 'OPTIONS']),
                'post' => $this->throughAdapter($adapter, self::PATH, ['REQUEST_METHOD' => 'post']),
                '401' => $this->throughAdapter($adapter, self::PATH, ['HTTP_X_API_KEY' => 'nope'], ['x-api-key' => 'nope']),
                '400' => $this->throughAdapter($adapter, self::PATH, ['HTTP_ORIGIN' => ''], ['origin' => '']),
            ];
            foreach ($cases as $label => [$status, $cache, $reachedApp]) {
                self::assertFalse($reachedApp, "{$adapter} {$label}");
                self::assertGreaterThanOrEqual(400, $status, "{$adapter} {$label}");
                self::assertStringContainsString('no-store', $cache, "{$adapter} {$label} status {$status}");
            }
        }
        self::assertSame(0, $this->server->requestCount());
    }

    // Upstream answers.

    public function testARedirectIsNeitherFollowedNorRelayed(): void
    {
        foreach ([300, 301, 302, 303, 304, 307, 308] as $status) {
            $this->server?->stop();
            $this->server = TestServer::always($status, '', ['location' => '/api/v1/elsewhere', 'set-cookie' => 'a=b']);
            $lines = [];
            $response = $this->proxy($lines)->handle($this->identify());

            self::assertSame(502, $response->status, (string) $status);
            self::assertSame('Bad gateway', $response->body);
            self::assertArrayNotHasKey('location', $response->headers);
            self::assertArrayNotHasKey('set-cookie', $response->headers);
            self::assertSame('no-store', $response->headers['cache-control'] ?? null);
            self::assertSame(1, $this->server->requestCount());
            self::assertSame('/api/v1/ingest', $this->server->first()['path']);
            self::assertSame([sprintf('fingerly proxy: upstream answered a redirect (%d)', $status)], $lines);
        }
    }

    public function testAnUpstreamStatusOutsideTheRangeIsA502(): void
    {
        foreach (['099', '600', '700', '999'] as $status) {
            $url = $this->startRawServer('HTTP/1.1 '.$status." Odd\r\ncontent-type: application/json\r\ncontent-length: 2\r\nconnection: close\r\n\r\n{}");
            $proxy = new Proxy([
                'proxy_key' => TestServer::PROXY_KEY,
                'path' => self::PATH,
                'upstream' => $url,
                'allow_insecure_loopback' => true,
                'timeout' => 2.0,
                'client_ip' => static fn (ProxyRequest $r): string => self::CLIENT_IP,
                'on_error' => static function (string $line): void {},
            ]);
            $response = $proxy->handle($this->identify());

            self::assertSame(502, $response->status, "upstream status {$status}");
            self::assertSame('no-store', $response->headers['cache-control'] ?? null, "upstream status {$status}");
            $this->stopRawServer();
        }
    }

    public function testAnUnreachableUpstreamIsNotNamedInTheAnswerOrTheLogLine(): void
    {
        $lines = [];
        // A loopback port nothing listens on.
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => 'http://127.0.0.1:1',
            'allow_insecure_loopback' => true,
            'timeout' => 1.0,
            'client_ip' => static fn (ProxyRequest $r): string => self::CLIENT_IP,
            'on_error' => static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ]);
        $response = $proxy->handle($this->identify());

        self::assertSame(502, $response->status);
        self::assertSame('no-store', $response->headers['cache-control'] ?? null);
        self::assertStringNotContainsString('127.0.0.1', $response->body.json_encode($response->headers));
        self::assertSame(['fingerly proxy: upstream request failed: connect'], $lines);
    }

    // Keys in construction errors.

    public function testAConstructionErrorCarriesNoKeyInItsTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $leaks = [];
            foreach ([
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'pathh' => '/x'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'fly_px_production_SECRETMARK' => 1],
                ['proxy_key' => 'fly_px_production_SECRETMARK with space'],
                ['proxy_key' => 'fly_sk_production_SECRETMARK'],
                ['proxy_key' => "fly_px_production_SECRETMARK\n"],
                ['secret_key' => 'fly_px_production_SECRETMARK'],
                ['secret_key' => 'fly_sk_production_SECRETMARK'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'upstream' => 'nope'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'upstream' => 'http://upstream.invalid'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'client_ip' => 'nope'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'max_body_bytes' => 0],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'path' => 'nope'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'allowed_origins' => 'nope'],
                ['proxy_key' => 'fly_px_production_SECRETMARK', 'http_client' => new \stdClass()],
            ] as $i => $options) {
                try {
                    new Proxy($options + ['client_ip' => static fn (ProxyRequest $r): string => self::CLIENT_IP]);
                    self::fail("case {$i} should have been refused");
                } catch (ConfigurationException $e) {
                    $leaks = [...$leaks, ...self::leaks($e, "proxy case {$i}")];
                }
            }
            foreach ([['endpointt' => 'x'], ['endpoint' => 'ftp://x']] as $i => $options) {
                try {
                    new Client('fly_sk_production_SECRETMARK', $options);
                    self::fail("client case {$i} should have been refused");
                } catch (ConfigurationException $e) {
                    $leaks = [...$leaks, ...self::leaks($e, "client case {$i}")];
                }
            }
            self::assertSame([], $leaks, implode("\n", $leaks));
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    private function stopRawServer(): void
    {
        if (is_resource($this->rawServer)) {
            proc_terminate($this->rawServer, \SIGTERM);
            proc_close($this->rawServer);
        }
        $this->rawServer = null;
    }

    /**
     * Where a key shows up in an exception: its message, its string form, or
     * any frame's arguments as an error reporter would read them.
     *
     * @return list<string>
     */
    private static function leaks(ConfigurationException $e, string $label): array
    {
        $leaks = [];
        if (str_contains($e->getMessage().(string) $e.$e->getTraceAsString(), 'SECRETMARK')) {
            $leaks[] = "{$label}: message or string trace";
        }
        foreach ($e->getTrace() as $frame) {
            $args = $frame['args'] ?? [];
            if (str_contains(print_r($args, true).var_export($args, true), 'SECRETMARK')) {
                $leaks[] = sprintf('%s: args of %s::%s', $label, $frame['class'] ?? '', $frame['function']);
            }
        }

        return $leaks;
    }

    // Helpers.

    /**
     * Starts a one-shot raw socket server on 127.0.0.1 that answers each
     * connection with the bytes given, for status lines no framework emits.
     */
    private function startRawServer(string $answer): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        $code = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $errno, $error);
            $answer = base64_decode($argv[2]);
            while ($client = @stream_socket_accept($server, 30)) {
                $request = '';
                while (!str_contains($request, "\r\n\r\n") && false !== ($chunk = fread($client, 8192)) && '' !== $chunk) {
                    $request .= $chunk;
                }
                if (preg_match('/content-length:\s*(\d+)/i', $request, $m)) {
                    $body = substr($request, strpos($request, "\r\n\r\n") + 4);
                    while (strlen($body) < (int) $m[1] && false !== ($chunk = fread($client, 8192)) && '' !== $chunk) {
                        $body .= $chunk;
                    }
                }
                fwrite($client, $answer);
                fclose($client);
            }
            PHP;
        $process = proc_open([\PHP_BINARY, '-r', $code, (string) $port, base64_encode($answer)], [], $pipes);
        self::assertIsResource($process);
        $this->rawServer = $process;

        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

                return 'http://127.0.0.1:'.$port;
            }
            usleep(25_000);
        }
        self::fail('the raw server never started listening');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function construct(array $options): Proxy
    {
        return new Proxy($options + [
            'proxy_key' => TestServer::PROXY_KEY,
            'client_ip' => static fn (ProxyRequest $r): string => self::CLIENT_IP,
        ]);
    }

    /**
     * Sends one request through an adapter, with the raw request target given.
     *
     * @param array<string, string> $server     extra CGI-style server values
     * @param array<string, string> $psrHeaders the same extras as header lines
     *
     * @return array{int, string, bool} status, Cache-Control, whether the application was reached
     */
    private function throughAdapter(string $adapter, string $rawTarget, array $server = [], array $psrHeaders = [], string $body = '{"signals":{}}'): array
    {
        $proxy = $this->proxy();
        $method = $server['REQUEST_METHOD'] ?? 'POST';

        if ('psr15' === $adapter) {
            $headers = array_filter($psrHeaders + [
                'x-api-key' => TestServer::PUBLIC_KEY,
                'origin' => self::ORIGIN,
                'user-agent' => 'Mozilla/5.0',
                'content-type' => 'application/json',
            ], static fn (string $v): bool => '' !== $v);
            $request = new ServerRequest($method, 'https://shop.example.com/placeholder', $headers, $body, '1.1', [
                'REQUEST_URI' => $rawTarget,
                'REMOTE_ADDR' => '198.51.100.9',
            ]);
            $factory = new Psr17Factory();
            $response = (new Psr15Middleware($proxy, $factory))->process($request, new class($factory) implements RequestHandlerInterface {
                public function __construct(private readonly Psr17Factory $factory) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->factory->createResponse(299);
                }
            });

            return [$response->getStatusCode(), $response->getHeaderLine('cache-control'), 299 === $response->getStatusCode()];
        }

        if ('laravel' === $adapter) {
            $request = $this->rawRequest(LaravelRequest::class, $rawTarget, $server, $body);
            $response = (new LaravelMiddleware($proxy))->handle($request, static fn () => new SymfonyResponse('app', 299));
            $response->prepare($request);

            return [$response->getStatusCode(), (string) $response->headers->get('cache-control'), 299 === $response->getStatusCode()];
        }

        $request = $this->rawRequest(SymfonyRequest::class, $rawTarget, $server, $body);
        $event = new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
        (new SymfonyListener($proxy))->onKernelRequest($event);
        $response = $event->getResponse();
        if (null === $response) {
            return [299, '', true];
        }
        $response->prepare($request);

        return [$response->getStatusCode(), (string) $response->headers->get('cache-control'), false];
    }

    /**
     * A framework request built from CGI-style values, so the raw request
     * target and method are exactly what was given.
     *
     * @template T of SymfonyRequest
     *
     * @param class-string<T>       $class
     * @param array<string, string> $server
     *
     * @return T
     */
    private function rawRequest(string $class, string $rawTarget, array $server = [], string $body = '{"signals":{}}'): SymfonyRequest
    {
        $base = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $rawTarget,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
            'SERVER_NAME' => 'shop.example.com',
            'SERVER_PORT' => '443',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'shop.example.com',
            'HTTP_X_API_KEY' => TestServer::PUBLIC_KEY,
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_ORIGIN' => self::ORIGIN,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.9',
        ];
        $merged = array_filter($server + $base, static fn (string $v): bool => '' !== $v);

        return new $class([], [], [], [], [], $merged, $body);
    }

    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse('app', 299);
            }
        };
    }

    /**
     * @param list<string> $lines filled with what the proxy reports
     */
    private function proxy(array &$lines = []): Proxy
    {
        self::assertNotNull($this->server);

        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'allow_insecure_loopback' => true,
            'client_ip' => static fn (ProxyRequest $request): string => self::CLIENT_IP,
            'on_error' => static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ]);
    }

    /**
     * @param array<string, string> $overrides an empty value removes the header
     */
    private function identify(array $overrides = [], string $path = self::PATH): ProxyRequest
    {
        $headers = [
            'x-api-key' => TestServer::PUBLIC_KEY,
            'content-type' => 'application/json',
            'origin' => self::ORIGIN,
            'user-agent' => 'Mozilla/5.0',
        ];
        foreach ($overrides as $name => $value) {
            unset($headers[strtolower($name)]);
            if ('' !== $value) {
                $headers[$name] = $value;
            }
        }

        return new ProxyRequest(method: 'POST', path: $path, headers: $headers, body: '{"signals":{}}');
    }
}
