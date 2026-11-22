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
    public function testRefusesAClientOrFactoryItCannotUse(): void
    {
        $http = new CannedPsr18Client(new Response(200, [], '{}'));
        $factory = new Psr17Factory();
        foreach ([
            ['http_client' => 'guzzle', 'request_factory' => $factory],
            ['http_client' => new \stdClass(), 'request_factory' => $factory],
            ['http_client' => $http, 'request_factory' => new \stdClass()],
            ['http_client' => $http, 'request_factory' => $factory, 'stream_factory' => 'factory'],
            ['request_factory' => $factory],
            ['stream_factory' => $factory],
        ] as $options) {
            try {
                new Client(TestServer::SECRET_KEY, $options);
                self::fail('accepted '.json_encode(array_map(get_debug_type(...), $options)));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame([], $http->sent);
    }

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
     * The proxy always sends over its own transport, which never follows a
     * redirect, so it takes no HTTP client: one that followed a redirect
     * would replay the proxy key before any answer came back.
     */
    public function testTheProxyTakesNoHttpClient(): void
    {
        $http = new CannedPsr18Client(new Response(200, [], '{"request_id":"req_1"}'));
        $factory = new Psr17Factory();
        foreach ([
            ['http_client' => $http, 'request_factory' => $factory, 'stream_factory' => $factory],
            ['http_client' => $http],
            ['request_factory' => $factory],
            ['stream_factory' => $factory],
        ] as $options) {
            try {
                new Proxy($options + [
                    'proxy_key' => TestServer::PROXY_KEY,
                    'path' => '/k7x2qm4a',
                    'client_ip' => fn (ProxyRequest $request): string => '203.0.113.7',
                ]);
                self::fail('the proxy accepted '.implode(', ', array_keys($options)));
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('Unknown option', $e->getMessage());
            }
        }
        self::assertSame([], $http->sent);
    }
}
