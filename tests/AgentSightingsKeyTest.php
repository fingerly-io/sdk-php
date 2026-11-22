<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\AgentSightings\Sighting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cache key and address form of the agent sightings contract, section 4.1.
 *
 * @internal
 */
final class AgentSightingsKeyTest extends TestCase
{
    /**
     * @param array<string, mixed> $vector
     */
    #[DataProvider('vectors')]
    public function testReproducesEveryVector(array $vector): void
    {
        self::assertIsString($vector['client_ip']);
        self::assertIsString($vector['user_agent']);
        self::assertIsString($vector['signature_agent']);
        self::assertIsBool($vector['signed']);

        self::assertSame($vector['canonical_ip'], Sighting::canonicalIp($vector['client_ip']));
        self::assertSame(
            $vector['key'],
            Sighting::key($vector['user_agent'], $vector['canonical_ip'], $vector['signature_agent'], $vector['signed']),
        );

        // The same key from the request as the SDK receives it.
        $sighting = Sighting::of(
            $vector['user_agent'],
            $vector['client_ip'],
            'example.com',
            'GET',
            $vector['signed'] ? 'sig1=:abc:' : '',
            $vector['signed'] ? 'sig1=("@authority");created=1' : '',
            $vector['signature_agent'],
        );
        self::assertNotNull($sighting);
        self::assertSame($vector['key'], $sighting->key);
        self::assertSame($vector['signed'], $sighting->signed);
        self::assertSame($vector['canonical_ip'], $sighting->clientIp);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function vectors(): iterable
    {
        $file = json_decode((string) file_get_contents(\dirname(__DIR__).'/contract/agent-sightings-vectors.json'), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($file);
        self::assertSame(1, $file['schema']);
        foreach ($file['vectors'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    #[DataProvider('addresses')]
    public function testCanonicalAddress(string $input, ?string $expected): void
    {
        self::assertSame($expected, Sighting::canonicalIp($input));
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['192.0.2.1', '192.0.2.1'];

        yield 'mapped IPv4' => ['::ffff:192.0.2.1', '192.0.2.1'];

        yield 'mapped IPv4 in hex' => ['::FFFF:c000:0201', '192.0.2.1'];

        yield 'loopback' => ['::1', '::1'];

        yield 'unspecified' => ['::', '::'];

        yield 'leading zeros' => ['2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1'];

        yield 'one zero group is not compressed' => ['2001:db8:0:1:1:1:1:1', '2001:db8:0:1:1:1:1:1'];

        yield 'the longest run wins' => ['2001:0:0:1:0:0:0:1', '2001:0:0:1::1'];

        yield 'the first run wins a tie' => ['2001:db8:0:0:1:0:0:1', '2001:db8::1:0:0:1'];

        yield 'trailing run' => ['fe80:1:0:0:0:0:0:0', 'fe80:1::'];

        yield 'IPv4-compatible is not dotted' => ['::192.0.2.1', '::c000:201'];

        yield 'zone id' => ['fe80::1%eth0', null];

        yield 'list' => ['192.0.2.1, 198.51.100.1', null];

        yield 'padded' => [' 192.0.2.1', null];

        yield 'empty' => ['', null];

        yield 'host name' => ['localhost', null];

        yield 'with a port' => ['192.0.2.1:80', null];
    }

    public function testAUserAgentOverTheLimitIsNotReported(): void
    {
        self::assertNull(Sighting::of(str_repeat('a', 4097), '192.0.2.1', 'h', 'GET', '', '', ''));
        self::assertNotNull(Sighting::of(str_repeat('a', 4096), '192.0.2.1', 'h', 'GET', '', '', ''));
        self::assertNull(Sighting::of('ua', '192.0.2.1', 'h', str_repeat('A', 4097), '', '', ''), 'nor is a method over it');
        self::assertNotNull(Sighting::of('ua', '192.0.2.1', 'h', str_repeat('A', 4096), '', '', ''));
    }

    public function testNoAddressIsNotReported(): void
    {
        self::assertNull(Sighting::of('ua', null, 'h', 'GET', '', '', ''));
        self::assertNull(Sighting::of('ua', 'unknown', 'h', 'GET', '', '', ''));
    }

    public function testAHeaderOverTheLimitIsLeftOutBeforeTheKey(): void
    {
        $long = str_repeat('x', 4097);
        $sighting = Sighting::of('ua', '192.0.2.1', $long, 'GET', $long, 'sig1=()', $long);
        self::assertNotNull($sighting);
        self::assertNull($sighting->host, 'Host over the limit is left out');
        self::assertSame(str_repeat('x', 4096), Sighting::of('ua', '192.0.2.1', str_repeat('x', 4096), 'GET', '', '', '')?->host);
        self::assertNull($sighting->signature);
        self::assertNull($sighting->signatureAgent);
        self::assertSame('sig1=()', $sighting->signatureInput);
        // Signature-Input alone is not a signed request.
        self::assertFalse($sighting->signed);
        self::assertSame(Sighting::key('ua', '192.0.2.1', '', false), $sighting->key);

        $item = $sighting->report(1)->toArray();
        self::assertArrayNotHasKey('host', $item);
        self::assertSame(1, $item['count'], 'count is sent even when 1');
        self::assertArrayNotHasKey('signature', $item);
        self::assertArrayNotHasKey('signature_agent', $item);
        self::assertSame('sig1=()', $item['signature_input']);
    }

    public function testTheKeyDependsOnEveryPart(): void
    {
        $base = Sighting::key('ua', '192.0.2.1', '', false);
        self::assertSame(32, strlen($base));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $base);
        self::assertNotSame($base, Sighting::key('ua ', '192.0.2.1', '', false));
        self::assertNotSame($base, Sighting::key('UA', '192.0.2.1', '', false));
        self::assertNotSame($base, Sighting::key('ua', '192.0.2.2', '', false));
        self::assertNotSame($base, Sighting::key('ua', '192.0.2.1', 'a', false));
        self::assertNotSame($base, Sighting::key('ua', '192.0.2.1', '', true));
    }
}
