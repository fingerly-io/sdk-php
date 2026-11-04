<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
use Fingerly\Proxy;
use Fingerly\Proxy\ProxyRequest;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * The README is pasted into applications, so its PSR-18 snippet has to boot.
 *
 * @internal
 */
final class ReadmeTest extends TestCase
{
    /**
     * The snippet as written, with only its third-party client swapped for a
     * canned one and its factories for another PSR-17 implementation: every
     * option it passes, and every one the constructors require, stays.
     */
    public function testThePsr18SnippetBootsAndForwards(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__).'/README.md');
        preg_match('/```php\n(\/\/ Redirects off.*?)```/s', $readme, $match);
        $snippet = $match[1] ?? '';
        self::assertNotSame('', $snippet, 'the PSR-18 snippet is missing');
        self::assertStringContainsString("new \\GuzzleHttp\\Client(['allow_redirects' => false", $snippet);

        $code = str_replace(
            ["new \\GuzzleHttp\\Client(['allow_redirects' => false, 'timeout' => 5])", 'new \GuzzleHttp\Psr7\HttpFactory()'],
            ['$canned', 'new \Nyholm\Psr7\Factory\Psr17Factory()'],
            $snippet,
        );
        self::assertSame(2, substr_count($code, "'stream_factory' => \$factory"), 'the client and the proxy each pass both factories');

        // One answer that reads as an event page and relays as a submission.
        $canned = new CannedPsr18Client(new Response(200, ['content-type' => 'application/json'], '{"rows":[],"page":1,"page_size":5}'));
        putenv('FINGERLY_SECRET_KEY='.TestServer::SECRET_KEY);
        putenv('FINGERLY_PROXY_KEY='.TestServer::PROXY_KEY);

        try {
            $fingerly = $proxy = null;
            eval($code);
        } finally {
            putenv('FINGERLY_SECRET_KEY');
            putenv('FINGERLY_PROXY_KEY');
        }

        self::assertInstanceOf(Client::class, $fingerly);
        self::assertInstanceOf(Proxy::class, $proxy);
        self::assertSame(1, $fingerly->events->list()->page);
        $response = $proxy->handle(new ProxyRequest('POST', '/k7x2qm4a', ['x-api-key' => TestServer::PUBLIC_KEY], '{"signals":{}}', '203.0.113.7'));
        self::assertSame(200, $response->status);
        self::assertSame('{"signals":{}}', (string) $canned->sent[1]->getBody());
        self::assertSame('203.0.113.7', $canned->sent[1]->getHeaderLine('x-fingerly-client-ip'));
    }
}
