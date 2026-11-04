<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
use Fingerly\ConnectionException;
use Fingerly\Proxy;
use Fingerly\Proxy\ProxyRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * No response body is buffered without a limit.
 *
 * @internal
 */
final class ResponseCapTest extends TestCase
{
    /** Just over the 4 MiB cap, and still valid JSON. */
    private const OVERSIZED_BYTES = 4194304 + 1024;

    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testTheProxyRefusesAnOversizedUpstreamBodyWith502(): void
    {
        $this->server = TestServer::always(200, self::oversized());
        $lines = [];
        $proxy = new Proxy([
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => '/k7x2qm4a',
            'upstream' => $this->server->baseUrl(),
            'client_ip' => fn (ProxyRequest $request): string => '203.0.113.7',
            'on_error' => static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ]);

        $response = $proxy->handle(new ProxyRequest(
            method: 'POST',
            path: '/k7x2qm4a',
            headers: ['x-api-key' => TestServer::PUBLIC_KEY, 'content-type' => 'application/json'],
            body: '{"signals":{}}',
        ));

        self::assertSame(502, $response->status);
        self::assertSame('Bad gateway', $response->body);
        self::assertSame(['fingerly proxy: upstream request failed: the response body is over 4 MiB'], $lines);
    }

    public function testAReadRefusesAnOversizedBody(): void
    {
        $this->server = TestServer::always(200, self::oversized());
        $fingerly = new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('the response body is over 4 MiB');
        $fingerly->events->list();
    }

    public function testABodyUnderTheCapIsRead(): void
    {
        $body = '{"rows":[],"page":1,"page_size":5,"pad":"'.str_repeat('x', 4194304 - 100).'"}';
        $this->server = TestServer::always(200, $body);
        $fingerly = new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);

        self::assertSame(1, $fingerly->events->list()->page);
    }

    /** A supplied PSR-18 client gets the same cap. */
    public function testAReadThroughAPsr18ClientRefusesAnOversizedBody(): void
    {
        $factory = new Psr17Factory();
        $fingerly = new Client(TestServer::SECRET_KEY, [
            'http_client' => new CannedPsr18Client(new Response(200, [], self::oversized())),
            'request_factory' => $factory,
        ]);

        $this->expectException(ConnectionException::class);
        $fingerly->events->list();
    }

    private static function oversized(): string
    {
        return '{"rows":[],"page":1,"page_size":5,"pad":"'.str_repeat('x', self::OVERSIZED_BYTES).'"}';
    }
}
