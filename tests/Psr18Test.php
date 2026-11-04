<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\ConnectionException;
use Fingerly\Proxy;
use Fingerly\Proxy\ProxyRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Routing calls through the host's own PSR-18 client.
 *
 * @internal
 */
final class Psr18Test extends TestCase
{
    public function testAReadGoesThroughTheSuppliedClient(): void
    {
        $http = new CannedPsr18Client(new Response(200, [], '{"rows":[],"page":1,"page_size":5}'));
        $fingerly = new Client(TestServer::SECRET_KEY, ['http_client' => $http, 'request_factory' => new Psr17Factory()]);

        self::assertSame(1, $fingerly->events->list()->page);
        self::assertCount(1, $http->sent);
        self::assertSame(TestServer::SECRET_KEY, $http->sent[0]->getHeaderLine('x-api-key'));
    }

    /**
     * Whatever the client throws, PSR-18 or not, arrives as this SDK's own
     * error, never a raw runtime error, and without the original attached:
     * client exceptions commonly hold the request and its key header.
     */
    public function testEveryClientFailureBecomesAConnectionException(): void
    {
        $psr18 = new class('connection reset') extends \RuntimeException implements ClientExceptionInterface {};

        foreach ([$psr18, new \RuntimeException('socket closed'), new \TypeError('bad body')] as $thrown) {
            $fingerly = new Client(TestServer::SECRET_KEY, [
                'http_client' => new CannedPsr18Client($thrown),
                'request_factory' => new Psr17Factory(),
            ]);

            try {
                $fingerly->events->list();
                self::fail($thrown::class.' escaped');
            } catch (ConnectionException $e) {
                self::assertStringContainsString($thrown->getMessage(), $e->getMessage());
                self::assertNull($e->getPrevious());
            }
        }
    }

    public function testANon2xxThroughTheClientIsAnApiError(): void
    {
        $fingerly = new Client(TestServer::SECRET_KEY, [
            'http_client' => new CannedPsr18Client(new Response(429, ['retry-after' => '3'], '{"error":{"code":"rate_limited"}}')),
            'request_factory' => new Psr17Factory(),
        ]);

        try {
            $fingerly->events->list();
            self::fail('the read should have failed');
        } catch (ApiException $e) {
            self::assertSame(3, $e->getRetryAfter());
        }
    }

    /**
     * Without a stream factory the request would leave without its body, and
     * the proxy would forward an empty identification.
     */
    public function testTheProxyNeedsBothFactoriesAlongsideAClient(): void
    {
        $http = new CannedPsr18Client(new Response(200, [], '{"request_id":"req_1"}'));
        $base = [
            'proxy_key' => TestServer::PROXY_KEY,
            'path' => '/k7x2qm4a',
            'client_ip' => fn (ProxyRequest $request): string => '203.0.113.7',
            'http_client' => $http,
        ];

        foreach ([[], ['request_factory' => new Psr17Factory()], ['stream_factory' => new Psr17Factory()]] as $factories) {
            try {
                new Proxy($base + $factories);
                self::fail('the proxy accepted a client without both factories');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('stream_factory', $e->getMessage());
            }
        }

        $factory = new Psr17Factory();
        $proxy = new Proxy($base + ['request_factory' => $factory, 'stream_factory' => $factory]);
        $response = $proxy->handle(new ProxyRequest('POST', '/k7x2qm4a', ['x-api-key' => TestServer::PUBLIC_KEY], '{"signals":{}}'));

        self::assertSame(200, $response->status);
        self::assertSame('{"signals":{}}', (string) $http->sent[0]->getBody());
    }
}
