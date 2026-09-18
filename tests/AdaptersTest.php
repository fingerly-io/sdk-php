<?php

declare(strict_types=1);

namespace Fingerly\Tests;

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

    public function testPsr15MiddlewareForwardsAnIdentify(): void
    {
        $factory = new Psr17Factory();
        $middleware = new Psr15Middleware($this->proxy(), $factory);

        $response = $middleware->process(
            $this->psrRequest('/metrics/api/v1/identify'),
            $this->refusingHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::IDENTIFIED, (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('cache-control'));
        self::assertSame('/api/v1/identify', $this->server?->first()['path']);
    }

    public function testPsr15MiddlewarePassesEverythingElseThrough(): void
    {
        $factory = new Psr17Factory();
        $middleware = new Psr15Middleware($this->proxy(), $factory);

        $response = $middleware->process(
            $this->psrRequest('/orders/42'),
            $this->applicationHandler($factory),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('the application answered', (string) $response->getBody());
        self::assertSame(0, $this->server?->requestCount());
    }

    public function testLaravelMiddlewareForwardsAnIdentify(): void
    {
        $middleware = new LaravelMiddleware($this->proxy());

        $response = $middleware->handle(
            $this->laravelRequest('/metrics/api/v1/identify'),
            static fn (LaravelRequest $request): SymfonyResponse => new SymfonyResponse('not reached', 500),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::IDENTIFIED, $response->getContent());
        // Symfony appends `private` to any Cache-Control without a visibility
        // directive. That is a narrowing, not a weakening, so it is left alone.
        self::assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        self::assertSame('/api/v1/identify', $this->server?->first()['path']);
    }

    public function testLaravelMiddlewarePassesEverythingElseThrough(): void
    {
        $middleware = new LaravelMiddleware($this->proxy());

        $response = $middleware->handle(
            $this->laravelRequest('/orders/42'),
            static fn (LaravelRequest $request): SymfonyResponse => new SymfonyResponse('the application answered'),
        );

        self::assertSame('the application answered', $response->getContent());
        self::assertSame(0, $this->server?->requestCount());
    }

    /**
     * Laravel and Symfony both put a sub-URI mount into the base URL, so
     * getPathInfo() is the only path the configured prefix can match.
     */
    public function testLaravelMiddlewareMatchesUnderASubUri(): void
    {
        $middleware = new LaravelMiddleware($this->proxy());

        $request = LaravelRequest::create('/shop/metrics/api/v1/identify', 'POST', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
            'SCRIPT_NAME' => '/shop/index.php',
            'PHP_SELF' => '/shop/index.php',
            'HTTP_X_API_KEY' => TestServer::PUBLIC_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], '{"signals":{}}');

        self::assertSame('/metrics/api/v1/identify', $request->getPathInfo());
        $response = $middleware->handle(
            $request,
            static fn (LaravelRequest $r): SymfonyResponse => new SymfonyResponse('not reached', 500),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/api/v1/identify', $this->server?->first()['path']);
    }

    public function testSymfonyListenerForwardsAnIdentify(): void
    {
        $listener = new SymfonyListener($this->proxy());
        $event = $this->symfonyEvent('/metrics/api/v1/identify');

        $listener->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(SymfonyResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::IDENTIFIED, $response->getContent());
        self::assertSame('/api/v1/identify', $this->server?->first()['path']);
    }

    public function testSymfonyListenerLeavesEverythingElseToTheRouter(): void
    {
        $listener = new SymfonyListener($this->proxy());
        $event = $this->symfonyEvent('/orders/42');

        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame(0, $this->server?->requestCount());
    }

    /** A sub-request is the application's own; the proxy must not answer it. */
    public function testSymfonyListenerIgnoresSubRequests(): void
    {
        $listener = new SymfonyListener($this->proxy());
        $event = $this->symfonyEvent('/metrics/api/v1/identify', HttpKernelInterface::SUB_REQUEST);

        $listener->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame(0, $this->server?->requestCount());
    }

    /** It answers before routing, so the proxy's paths need no route. */
    public function testSymfonyListenerRunsBeforeTheRouter(): void
    {
        $events = SymfonyListener::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertGreaterThan(32, $events[KernelEvents::REQUEST][1]);
    }

    private function proxy(): Proxy
    {
        self::assertNotNull($this->server);

        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'prefix' => '/metrics',
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
