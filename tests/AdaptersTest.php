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
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The three adapters, against the real framework types they exist for.
 *
 * Each one has to do the same two things: hand a proxied request to the core,
 * and let everything else through to the application. The second is what an
 * adapter gets wrong.
 *
 * @internal
 */
final class AdaptersTest extends TestCase
{
    private const IDENTIFIED = '{"request_id":"req_1","visitor_id":"vis_1"}';
    private const PATH = '/k7x2qm4a';

    /** Paths that are not the proxy's, including one under it. */
    private const OTHER_PATHS = ['/orders/42', self::PATH.'/admin', '/'];

    private ?TestServer $server = null;

    protected function setUp(): void
    {
        $this->server = TestServer::always(200, self::IDENTIFIED);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testPsr15MiddlewareForwardsToTheIngestUrl(): void
    {
        $factory = new Psr17Factory();
        $middleware = new Psr15Middleware($this->proxy(), $factory);

        $response = $middleware->process(
            $this->psrRequest(self::PATH),
            $this->refusingHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::IDENTIFIED, (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('cache-control'));
        self::assertSame('/api/v1/ingest', $this->server?->first()['path']);
    }

    public function testPsr15MiddlewarePassesEverythingElseThrough(): void
    {
        $factory = new Psr17Factory();
        $middleware = new Psr15Middleware($this->proxy(), $factory);

        foreach (self::OTHER_PATHS as $path) {
            $response = $middleware->process(
                $this->psrRequest($path),
                $this->applicationHandler($factory),
            );

            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertSame('the application answered', (string) $response->getBody(), $path);
        }
        self::assertSame(0, $this->server?->requestCount());
    }

    /**
     * StreamInterface::read() may return less than asked for, and over
     * php://input it returns 8 KiB at a time, so one read() cut every larger
     * body short and the API refused the truncated identification.
     */
    public function testPsr15MiddlewareForwardsABodyLargerThanOneRead(): void
    {
        $middleware = new Psr15Middleware($this->proxy(), new Psr17Factory());
        $body = '{"signals":{"pad":"'.str_repeat('x', 20000).'"}}';

        $response = $middleware->process(
            $this->psrRequest(self::PATH)->withBody(new OneChunkStream($body)),
            $this->refusingHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($body, $this->server?->first()['body']);
    }

    /** With no Content-Length the limit is enforced while reading, past one chunk. */
    public function testPsr15MiddlewareRefusesAnOversizedBodyThatDeclaresNoLength(): void
    {
        $middleware = new Psr15Middleware($this->proxy(), new Psr17Factory());

        $response = $middleware->process(
            $this->psrRequest(self::PATH)->withBody(new OneChunkStream(str_repeat('x', 1048577))),
            $this->refusingHandler(),
        );

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $this->server?->requestCount());
    }

    public function testLaravelMiddlewareForwardsToTheIngestUrl(): void
    {
        $middleware = new LaravelMiddleware($this->proxy());

        $response = $middleware->handle(
            $this->laravelRequest(self::PATH),
            static fn (LaravelRequest $request): SymfonyResponse => new SymfonyResponse('not reached', 500),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::IDENTIFIED, $response->getContent());
        // Symfony appends `private` to any Cache-Control without a visibility
        // directive. That is a narrowing, not a weakening, so it is left alone.
        self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        self::assertSame('/api/v1/ingest', $this->server?->first()['path']);
    }

    public function testLaravelMiddlewarePassesEverythingElseThrough(): void
    {
        $middleware = new LaravelMiddleware($this->proxy());

        foreach (self::OTHER_PATHS as $path) {
            $response = $middleware->handle(
                $this->laravelRequest($path),
                static fn (LaravelRequest $request): SymfonyResponse => new SymfonyResponse('the application answered'),
            );

            self::assertSame('the application answered', $response->getContent(), $path);
        }
        self::assertSame(0, $this->server?->requestCount());
    }

    /**
     * Laravel and Symfony both put a sub-URI mount into the base URL, so
     * getPathInfo() is the only path the configured path can match.
     */
    public function testLaravelMiddlewareMatchesUnderASubUri(): void
    {
        $middleware = new LaravelMiddleware($this->proxy());

        $request = LaravelRequest::create('/shop/k7x2qm4a', 'POST', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
            'SCRIPT_NAME' => '/shop/index.php',
            'PHP_SELF' => '/shop/index.php',
            'HTTP_X_API_KEY' => TestServer::PUBLIC_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], '{"signals":{}}');

        self::assertSame(self::PATH, $request->getPathInfo());
        $response = $middleware->handle(
            $request,
            static fn (LaravelRequest $r): SymfonyResponse => new SymfonyResponse('not reached', 500),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/api/v1/ingest', $this->server?->first()['path']);
    }

    public function testSymfonyListenerForwardsToTheIngestUrl(): void
    {
        $listener = new SymfonyListener($this->proxy());
        $event = $this->symfonyEvent(self::PATH);

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(SymfonyResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::IDENTIFIED, $response->getContent());
        self::assertSame('/api/v1/ingest', $this->server?->first()['path']);
    }

    public function testSymfonyListenerLeavesEverythingElseToTheRouter(): void
    {
        $listener = new SymfonyListener($this->proxy());

        foreach (self::OTHER_PATHS as $path) {
            $event = $this->symfonyEvent($path);
            $listener->onKernelRequest($event);
            self::assertNull($event->getResponse(), $path);
        }
        self::assertSame(0, $this->server?->requestCount());
    }

    /** A sub-request is the application's own; the proxy must not answer it. */
    public function testSymfonyListenerIgnoresSubRequests(): void
    {
        $listener = new SymfonyListener($this->proxy());
        $event = $this->symfonyEvent(self::PATH, HttpKernelInterface::SUB_REQUEST);

        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame(0, $this->server?->requestCount());
    }

    /** It answers before routing, so the proxy's path needs no route. */
    public function testSymfonyListenerRunsBeforeTheRouter(): void
    {
        $events = SymfonyListener::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertGreaterThan(32, $events[KernelEvents::REQUEST][1]);
    }

    /**
     * Every adapter sees every request, so a proxy without a path would answer
     * them all. There is no default path to fall back on.
     */
    public function testEveryAdapterRequiresAPath(): void
    {
        self::assertNotNull($this->server);
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => static fn (ProxyRequest $request): string => '203.0.113.7',
        ]);

        foreach ([
            static fn () => new Psr15Middleware($proxy, new Psr17Factory()),
            static fn () => new LaravelMiddleware($proxy),
            static fn () => new SymfonyListener($proxy),
        ] as $mount) {
            try {
                $mount();
                self::fail('an adapter accepted a proxy without a path');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('path', $e->getMessage());
            }
        }
    }

    private function proxy(): Proxy
    {
        self::assertNotNull($this->server);

        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => self::PATH,
            'upstream' => $this->server->baseUrl(),
            'client_ip' => static fn (ProxyRequest $request): string => '203.0.113.7',
        ]);
    }

    private function psrRequest(string $path): ServerRequestInterface
    {
        return new ServerRequest('POST', 'https://shop.example.com'.$path, [
            'x-api-key' => TestServer::PUBLIC_KEY,
            'content-type' => 'application/json',
        ], '{"signals":{}}');
    }

    private function laravelRequest(string $path): LaravelRequest
    {
        return LaravelRequest::create($path, 'POST', [], [], [], [
            'HTTP_X_API_KEY' => TestServer::PUBLIC_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], '{"signals":{}}');
    }

    private function symfonyEvent(string $path, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = SymfonyRequest::create($path, 'POST', [], [], [], [
            'HTTP_X_API_KEY' => TestServer::PUBLIC_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], '{"signals":{}}');

        $kernel = new class implements HttpKernelInterface {
            public function handle(
                SymfonyRequest $request,
                int $type = self::MAIN_REQUEST,
                bool $catch = true,
            ): SymfonyResponse {
                return new SymfonyResponse('the application answered');
            }
        };

        return new RequestEvent($kernel, $request, $type);
    }

    /** A handler that fails the test if the middleware ever reaches it. */
    private function refusingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('the proxy should have answered this');
            }
        };
    }

    private function applicationHandler(Psr17Factory $factory): RequestHandlerInterface
    {
        return new class($factory) implements RequestHandlerInterface {
            public function __construct(private readonly Psr17Factory $factory) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = $this->factory->createResponse(200);
                $response->getBody()->write('the application answered');

                return $response;
            }
        };
    }
}
