<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
use Fingerly\Proxy;
use Fingerly\Proxy\ProxyRequest;
use PHPUnit\Framework\TestCase;

/**
 * One connection per process, not one per request.
 *
 * A connection per request leaves every socket in TIME_WAIT, which exhausts
 * the host's ephemeral ports at a few hundred requests a second and takes
 * every other outbound connection on the host down with it.
 *
 * @internal
 */
final class ConnectionReuseTest extends TestCase
{
    private const PAGE = '{"rows":[],"page":1,"page_size":5}';

    private ?KeepAliveServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testTheClientReusesOneConnection(): void
    {
        $this->server = KeepAliveServer::start(200, self::PAGE);
        $fingerly = new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);

        for ($i = 0; $i < 5; ++$i) {
            $fingerly->events->list();
        }

        self::assertCount(5, $this->server->requests());
        self::assertCount(1, $this->server->connections());
    }

    public function testTheProxyReusesOneConnection(): void
    {
        $this->server = KeepAliveServer::start(200, '{"request_id":"req_1"}');
        $proxy = $this->proxy();

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $proxy->handle($this->identify())->status);
        }

        self::assertCount(5, $this->server->requests());
        self::assertCount(1, $this->server->connections());
    }

    /** The handle is reused, so nothing of one request may reach the next. */
    public function testNothingOfOneRequestReachesTheNext(): void
    {
        $this->server = KeepAliveServer::start(200, '{"request_id":"req_1"}');
        $proxy = $this->proxy();

        $proxy->handle($this->identify(['idempotency-key' => 'first-only', 'content-type' => 'text/plain']));
        $proxy->handle($this->identify());

        [$first, $second] = $this->server->requests();
        self::assertSame('first-only', $first['headers']['idempotency-key'] ?? null);
        self::assertArrayNotHasKey('idempotency-key', $second['headers']);
        self::assertSame('application/json', $second['headers']['content-type'] ?? null);
    }

    /**
     * Under PHP-FPM every request builds a new client, so the connection cache
     * has to outlive it. PHP 8.5 added persistent share handles for exactly
     * this; earlier versions reuse within one client only.
     */
    public function testANewClientReusesTheConnectionOfTheLastOne(): void
    {
        if (!\function_exists('curl_share_init_persistent')) {
            self::markTestSkipped('persistent cURL share handles need PHP 8.5');
        }
        $this->server = KeepAliveServer::start(200, self::PAGE);

        for ($i = 0; $i < 3; ++$i) {
            (new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]))->events->list();
        }

        self::assertCount(3, $this->server->requests());
        self::assertCount(1, $this->server->connections());
    }

    private function proxy(): Proxy
    {
        self::assertNotNull($this->server);

        return new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => '/k7x2qm4a',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => '203.0.113.7',
        ]);
    }

    /** @param array<string, string> $overrides */
    private function identify(array $overrides = []): ProxyRequest
    {
        return new ProxyRequest(
            method: 'POST',
            path: '/k7x2qm4a',
            headers: array_merge([
                'x-api-key' => TestServer::PUBLIC_KEY,
                'content-type' => 'application/json',
                'origin' => 'https://shop.example.com',
                'user-agent' => 'Mozilla/5.0',
            ], $overrides),
            body: '{"signals":{}}',
        );
    }
}
