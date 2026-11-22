<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\AgentSightings;
use Fingerly\AgentSightings\LaravelMiddleware;
use Fingerly\AgentSightings\MemoryStore;
use Fingerly\AgentSightings\Psr15Middleware;
use Fingerly\AgentSightings\SymfonyListener;
use Fingerly\Proxy\ProxyRequest;
use Fingerly\VisitorKind;
use Illuminate\Http\Request as LaravelRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The agent sightings adapters: what each reports, that the response passes
 * through untouched, and that nothing is sent while the request is served.
 *
 * @internal
 */
final class AgentSightingsAdaptersTest extends TestCase
{
    private const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot';

    private ?TestServer $server = null;
    private float $now = 1000.0;

    /** @var list<\Closure(): void> */
    private array $deferred = [];

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testPsr15ReportsOnlyInScopeResponsesAndPassesThemThrough(): void
    {
        $sightings = $this->sightings();
        $middleware = new Psr15Middleware($sightings);
        $html = new Psr7Response(200, ['content-type' => 'text/html; charset=utf-8'], '<p>hi</p>');
        $json = new Psr7Response(200, ['content-type' => 'application/json'], '{}');

        self::assertSame($html, $middleware->process(self::psr7('/'), self::handler($html)));
        self::assertSame($json, $middleware->process(self::psr7('/api/orders'), self::handler($json)));
        self::assertSame($json, $middleware->process(self::psr7('/robots.txt'), self::handler($json)));
        $sightings->flush();

        // The page and robots.txt were reported, the API route was not, and
        // the same visitor twice is one item.
        $items = $this->sentItems();
        self::assertCount(1, $items);
        self::assertSame(2, $items[0]['count']);
        self::assertSame([
            'user_agent' => self::GPTBOT,
            'client_ip' => '20.171.206.9',
            'host' => 'shop.example.com',
            'method' => 'GET',
            'count' => 2,
        ], $items[0]);

        self::assertSame(VisitorKind::AI_BOT, $middleware->lookup(self::psr7('/'))->visitorKind);
    }

    public function testPsr15NeverSendsWhileTheRequestIsServed(): void
    {
        $sightings = $this->sightings(['status' => 200, 'body' => $this->answerBody(200), 'delay' => 3.0]);
        $sightings->useClocks(fn (): float => $this->now);
        $middleware = new Psr15Middleware($sightings);
        $html = new Psr7Response(200, ['content-type' => 'text/html']);

        $started = microtime(true);
        for ($i = 0; $i < 200; ++$i) {
            $middleware->process(self::psr7('/', 'agent '.$i), self::handler($html));
        }
        self::assertLessThan(1.0, microtime(true) - $started, 'reporting adds no network wait');
        self::assertSame(0, $this->server()->requestCount());
        self::assertCount(1, $this->deferred, 'the flush runs after the response');

        foreach ($this->deferred as $flush) {
            $flush();
        }
        self::assertSame(1, $this->server()->requestCount());
    }

    public function testLaravelReportsAfterTheResponseInTerminate(): void
    {
        $sightings = $this->sightings();
        $middleware = new LaravelMiddleware($sightings);
        $request = self::laravel('/pricing');
        $response = new SymfonyResponse('<p>hi</p>', 200, ['content-type' => 'text/html; charset=UTF-8']);

        self::assertSame($response, $middleware->handle($request, static fn (): SymfonyResponse => $response));
        $sightings->flush();
        self::assertSame(0, $this->server()->requestCount(), 'handle() reports nothing');

        $middleware->terminate($request, $response);
        $middleware->terminate(self::laravel('/api/orders'), new SymfonyResponse('{}', 200, ['content-type' => 'application/json']));
        $sightings->flush();

        $items = $this->sentItems();
        self::assertCount(1, $items);
        self::assertSame('20.171.206.9', $items[0]['client_ip']);
        self::assertSame('shop.example.com', $items[0]['host']);
        self::assertSame(VisitorKind::AI_BOT, $middleware->lookup($request)->visitorKind);
    }

    public function testLaravelMatchesTheApplicationRelativePath(): void
    {
        $sightings = $this->sightings(null, ['exclude_path_prefixes' => ['/admin']]);
        $middleware = new LaravelMiddleware($sightings);
        $request = LaravelRequest::create('/shop/admin/users', 'GET', [], [], [], self::serverVars() + [
            'SCRIPT_NAME' => '/shop/index.php',
            'SCRIPT_FILENAME' => '/var/www/shop/index.php',
        ]);
        self::assertSame('/admin/users', $request->getPathInfo());
        $middleware->terminate($request, new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        $sightings->flush();
        self::assertSame(0, $this->server()->requestCount(), '/admin is excluded under the sub-URI too');

        $this->server?->stop();
        $sightings = $this->sightings(null, ['exclude_path_prefixes' => ['/shop/admin']]);
        (new LaravelMiddleware($sightings))->terminate($request, new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount(), 'the base path is not part of the matched path');
    }

    public function testLaravelFlushesADueBatchFromTerminateWhenLongRunning(): void
    {
        $sightings = $this->sightings(null, ['long_running' => true]);
        $middleware = new LaravelMiddleware($sightings);
        $middleware->terminate(self::laravel('/'), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        self::assertSame(0, $this->server()->requestCount());
        $this->now += 2;
        $middleware->terminate(self::laravel('/'), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        self::assertSame(1, $this->server()->requestCount());
        self::assertSame(2, $this->sentItems()[0]['count']);
        self::assertSame([], $this->deferred, 'a long-running server sends from terminate, not at shutdown');
    }

    public function testSymfonyFlushesADueBatchOnKernelTerminateWhenLongRunning(): void
    {
        $sightings = $this->sightings(null, ['long_running' => true]);
        $listener = new SymfonyListener($sightings);
        $listener->onKernelTerminate(self::terminateEvent('/'));
        $this->now += 2;
        $listener->onKernelTerminate(self::terminateEvent('/'));
        self::assertSame(1, $this->server()->requestCount());
    }

    public function testTerminateNeverSendsWhenTheServerHoldsTheResponseOpen(): void
    {
        // Laravel and Symfony run terminate after send(), but only PHP-FPM
        // and LiteSpeed have finished the response by then. Under the
        // built-in server or Apache's module the client is still waiting,
        // so a due batch must not be sent from there.
        $sightings = $this->sightings(['status' => 200, 'body' => $this->answerBody(1), 'delay' => 3.0]);
        $sightings->deferUsing(null);
        $laravel = new LaravelMiddleware($sightings);
        $symfony = new SymfonyListener($sightings);

        $started = microtime(true);
        $laravel->terminate(self::laravel('/'), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        $this->now += 2;
        $laravel->terminate(self::laravel('/'), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        $symfony->onKernelTerminate(self::terminateEvent('/'));
        self::assertLessThan(1.0, microtime(true) - $started, 'terminate adds no network wait');
        self::assertSame(0, $this->server()->requestCount());
    }

    public function testUnderPhpFpmADueBatchIsSentOnceTheResponseIsFinished(): void
    {
        $sightings = $this->sightings();
        $middleware = new LaravelMiddleware($sightings);
        $middleware->terminate(self::laravel('/'), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        $this->now += 2;
        $middleware->terminate(self::laravel('/'), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
        self::assertSame(0, $this->server()->requestCount(), 'not from terminate');
        self::assertCount(1, $this->deferred, 'but after the response is finished');
        foreach ($this->deferred as $flush) {
            $flush();
        }
        self::assertSame(2, $this->sentItems()[0]['count']);
    }

    public function testSymfonyReportsOnKernelTerminate(): void
    {
        self::assertSame([KernelEvents::TERMINATE => ['onKernelTerminate', 0]], SymfonyListener::getSubscribedEvents());
        $sightings = $this->sightings();
        $listener = new SymfonyListener($sightings);
        $kernel = new class implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse();
            }
        };
        $request = SymfonyRequest::create('/llms.txt', 'GET', [], [], [], self::serverVars());
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new SymfonyResponse('x', 200, ['content-type' => 'text/plain'])));
        $listener->onKernelTerminate(new TerminateEvent($kernel, SymfonyRequest::create('/app.css', 'GET', [], [], [], self::serverVars()), new SymfonyResponse('x', 200, ['content-type' => 'text/css'])));
        $sightings->flush();

        $items = $this->sentItems();
        self::assertCount(1, $items);
        self::assertSame(self::GPTBOT, $items[0]['user_agent']);
        self::assertSame(VisitorKind::AI_BOT, $listener->lookup($request)->visitorKind);
    }

    public function testAdaptersNeverFilterByUserAgent(): void
    {
        $sightings = $this->sightings(['status' => 200, 'body' => $this->answerBody(3)]);
        $middleware = new Psr15Middleware($sightings);
        $html = new Psr7Response(200, ['content-type' => 'text/html']);
        foreach (['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0.0.0', '', 'curl/8.7.1'] as $userAgent) {
            $middleware->process(self::psr7('/', $userAgent), self::handler($html));
        }
        $sightings->flush();
        self::assertSame(['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/141.0.0.0', '', 'curl/8.7.1'], array_column($this->sentItems(), 'user_agent'));
    }

    /**
     * @param null|array{status: int, body: string, delay?: float} $canned
     * @param array<string, mixed>                                 $options
     */
    private function sightings(?array $canned = null, array $options = []): AgentSightings
    {
        $this->server = TestServer::start([$canned ?? ['status' => 200, 'body' => $this->answerBody(1)]]);
        $sightings = new AgentSightings($options + [
            'secret_key' => TestServer::SECRET_KEY,
            // Trust the edge header only because the test puts it there.
            'client_ip' => static fn (ProxyRequest $request): ?string => '' !== $request->header('cf-connecting-ip') ? $request->header('cf-connecting-ip') : $request->remoteAddress,
            'endpoint' => $this->server->baseUrl(),
            'allow_insecure_loopback' => true,
            'long_running' => false,
            'on_error' => static function (): void {},
        ], new MemoryStore(4096));
        $sightings->useClocks(fn (): float => $this->now);
        $sightings->deferUsing(function (\Closure $flush): void {
            $this->deferred[] = $flush;
        });

        return $sightings;
    }

    private function answerBody(int $items): string
    {
        return (string) json_encode([
            'rules_version' => 'r1',
            'items' => array_fill(0, $items, [
                'ttl' => 900,
                'visitor_kind' => 'ai_bot',
                'agent' => 'GPTBot',
                'ai' => ['name' => 'GPTBot', 'provider' => 'OpenAI', 'purpose' => 'training', 'runtime' => 'provider_cloud', 'declared' => true, 'verification' => ['method' => 'none', 'result' => 'not_attempted']],
            ]),
        ]);
    }

    private static function psr7(string $path, string $userAgent = self::GPTBOT): ServerRequest
    {
        return new ServerRequest('GET', 'https://shop.example.com'.$path.'?q=secret', [
            'user-agent' => $userAgent,
            'host' => 'shop.example.com',
            'cf-connecting-ip' => '20.171.206.9',
            'cookie' => 'session=secret',
        ], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1', 'REQUEST_METHOD' => 'GET']);
    }

    /**
     * @return array<string, string>
     */
    private static function serverVars(): array
    {
        return [
            'HTTP_USER_AGENT' => self::GPTBOT,
            'HTTP_HOST' => 'shop.example.com',
            'HTTP_CF_CONNECTING_IP' => '20.171.206.9',
            'REMOTE_ADDR' => '10.0.0.1',
        ];
    }

    private static function laravel(string $path): LaravelRequest
    {
        return LaravelRequest::create($path, 'GET', [], [], [], self::serverVars());
    }

    private static function terminateEvent(string $path): TerminateEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(SymfonyRequest $request, int $type = self::MAIN_REQUEST, bool $catch = true): SymfonyResponse
            {
                return new SymfonyResponse();
            }
        };

        return new TerminateEvent($kernel, SymfonyRequest::create($path, 'GET', [], [], [], self::serverVars()), new SymfonyResponse('', 200, ['content-type' => 'text/html']));
    }

    private static function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function server(): TestServer
    {
        self::assertNotNull($this->server);

        return $this->server;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentItems(): array
    {
        $body = json_decode($this->server()->first()['body'], true, 16, \JSON_THROW_ON_ERROR);

        return $body['items'];
    }
}
