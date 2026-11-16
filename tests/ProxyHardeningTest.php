<?php

declare(strict_types=1);

namespace Fingerly\Tests;

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

    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
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

    // Helpers.

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
