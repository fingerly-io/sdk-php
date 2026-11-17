<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\Client;
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
        self::assertStringNotContainsString('Proxy', $snippet, 'the proxy takes no HTTP client');

        $canned = new CannedPsr18Client(new Response(200, ['content-type' => 'application/json'], '{"rows":[],"page":1,"page_size":5}'));
        putenv('FINGERLY_SECRET_KEY='.TestServer::SECRET_KEY);

        try {
            $fingerly = null;
            eval($code);
        } finally {
            putenv('FINGERLY_SECRET_KEY');
        }

        self::assertInstanceOf(Client::class, $fingerly);
        self::assertSame(1, $fingerly->events->list()->page);
        self::assertCount(1, $canned->sent);
        self::assertSame('https://api.fingerly.io/api/v1/events', (string) $canned->sent[0]->getUri());
    }
}
