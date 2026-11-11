<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against the server's own contract fixtures.
 *
 * The vendored snapshot in `contract/` makes these tests independent of
 * sibling repositories. Refresh it with `composer contract:sync`.
 *
 * @internal
 */
final class ContractTest extends TestCase
{
    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /**
     * The snapshot must be the one the server recorded, or the tests below
     * prove nothing about the real contract.
     */
    public function testTheSnapshotMatchesItsManifest(): void
    {
        $manifest = self::json('manifest.json');
        self::assertIsArray($manifest['files'] ?? null);
        self::assertNotEmpty($manifest['files'], 'the manifest records no files');

        foreach ($manifest['files'] as $name => $expected) {
            $path = self::contractDir().'/'.$name;
            self::assertFileExists($path);
            self::assertSame(
                $expected,
                hash_file('sha256', $path),
                $name.' is stale; run `composer contract:sync`',
            );
        }
    }

    public function testReadsEveryEventFixture(): void
    {
        $fixtures = self::fixturesFor('SDKEventOut');
        self::assertNotEmpty($fixtures, 'the snapshot records no event fixtures');

        foreach ($fixtures as $fixture) {
            $this->serve($fixture);
            $event = $this->client()->events->get('req_1');
            $body = $fixture['body'];

            self::assertSame($body['request_id'], $event->requestId, $fixture['name']);
            self::assertSame($body['visitor_id'], $event->visitorId, $fixture['name']);
            self::assertSame($body['project_id'] ?? null, $event->projectId, $fixture['name']);

            // A fixture without a score must not decode as zero.
            if (!array_key_exists('suspect_score', $body) || null === $body['suspect_score']) {
                self::assertFalse($event->isScored(), $fixture['name'].' decoded an unscored event as a number');
                self::assertNull($event->suspectScore, $fixture['name']);
            } else {
                self::assertSame($body['suspect_score'], $event->suspectScore, $fixture['name']);
            }

            // The archived submission stays opaque rather than becoming a
            // second public contract.
            self::assertSame(
                isset($body['document']),
                null !== $event->document,
                $fixture['name'],
            );
            // Whatever this build did not model is still reachable.
            self::assertSame($body, $event->raw, $fixture['name']);
        }
    }

    public function testReadsEveryPageFixture(): void
    {
        $fixtures = self::fixturesFor('SDKEventsOut');
        self::assertNotEmpty($fixtures, 'the snapshot records no page fixtures');

        foreach ($fixtures as $fixture) {
            $this->serve($fixture);
            $page = $this->client()->events->list();
            $body = $fixture['body'];

            self::assertSame($body['page'], $page->page, $fixture['name']);
            self::assertSame($body['page_size'], $page->pageSize, $fixture['name']);
            self::assertCount(count($body['rows']), $page->rows, $fixture['name']);

            foreach ($page->rows as $index => $row) {
                self::assertSame($body['rows'][$index]['request_id'], $row->requestId, $fixture['name']);
                self::assertSame($body['rows'][$index]['project_id'] ?? null, $row->projectId, $fixture['name']);
            }
        }
    }

    public function testMapsEveryErrorFixture(): void
    {
        $fixtures = self::fixturesFor('ErrorResponse');
        self::assertNotEmpty($fixtures, 'the snapshot records no error fixtures');

        foreach ($fixtures as $fixture) {
            $this->serve($fixture);

            try {
                $this->client()->events->get('req_1');
                self::fail($fixture['name'].' should have failed');
            } catch (ApiException $e) {
                self::assertSame($fixture['status'], $e->getStatus(), $fixture['name']);
                self::assertSame($fixture['body']['error']['code'] ?? null, $e->getErrorCode(), $fixture['name']);
                self::assertSame($fixture['body']['request_id'] ?? null, $e->getRequestId(), $fixture['name']);
            }
        }
    }

    /**
     * The retryable failure is recognised from the fixture the server records
     * for it, not from a status alone.
     */
    public function testRecognisesTheNotFoundFixtureAsRetryable(): void
    {
        $found = null;
        foreach (self::fixturesFor('ErrorResponse') as $fixture) {
            if (404 === $fixture['status']) {
                $found = $fixture;

                break;
            }
        }
        self::assertNotNull($found, 'the snapshot records no 404 fixture');

        $this->serve($found);

        try {
            $this->client()->events->get('req_1');
            self::fail('the read should have failed');
        } catch (ApiException $e) {
            self::assertTrue($e->isEventNotFound());
        }
    }

    public function testVerifiesEveryWebhookFixture(): void
    {
        $webhooks = self::json('fixtures.json')['webhooks'];
        self::assertNotEmpty($webhooks, 'the snapshot records no webhook fixtures');

        foreach ($webhooks as $fixture) {
            // Verify each delivery at the instant it was signed.
            $signedAt = (new \DateTimeImmutable())->setTimestamp((int) $fixture['timestamp']);
            self::assertSame($fixture['valid'], Webhook::verify(
                secret: $fixture['secret'],
                payload: $fixture['payload'],
                timestamp: $fixture['timestamp'],
                signature: $fixture['signature'],
                now: $signedAt,
            ), $fixture['name']);
        }

        // The same fixture, past the replay window, must be refused.
        $valid = null;
        foreach ($webhooks as $fixture) {
            if (true === $fixture['valid']) {
                $valid = $fixture;

                break;
            }
        }
        self::assertNotNull($valid, 'the snapshot records no valid webhook');

        $stale = (new \DateTimeImmutable())->setTimestamp((int) $valid['timestamp'] + 301);
        $arguments = [
            'secret' => $valid['secret'],
            'payload' => $valid['payload'],
            'timestamp' => $valid['timestamp'],
            'signature' => $valid['signature'],
            'now' => $stale,
        ];
        self::assertFalse(Webhook::verify(...$arguments), 'a stale delivery was accepted');
        // And it is accepted again with a window wide enough to cover it.
        self::assertTrue(Webhook::verify(...$arguments + ['tolerance' => 600]));
    }

    private static function contractDir(): string
    {
        return dirname(__DIR__).'/contract';
    }

    /** @return array<string, mixed> */
    private static function json(string $name): array
    {
        $raw = file_get_contents(self::contractDir().'/'.$name);
        self::assertIsString($raw);

        return json_decode($raw, true, 64, \JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private static function fixturesFor(string $schema): array
    {
        $matching = [];
        foreach (self::json('fixtures.json')['responses'] as $fixture) {
            if ($fixture['schema'] === $schema) {
                $matching[] = $fixture;
            }
        }

        return $matching;
    }

    /**
     * Answers with a fixture body exactly as the server recorded it.
     *
     * @param array<string, mixed> $fixture
     */
    private function serve(array $fixture): void
    {
        $this->server?->stop();
        $this->server = TestServer::always(
            $fixture['status'],
            json_encode($fixture['body'], \JSON_THROW_ON_ERROR),
        );
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);
    }
}
