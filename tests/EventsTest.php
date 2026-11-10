<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use PHPUnit\Framework\TestCase;

/**
 * Event reads and the list filters.
 *
 * @internal
 */
final class EventsTest extends TestCase
{
    private ?TestServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    public function testReadsOneEvent(): void
    {
        $this->server = TestServer::always(200, Fixtures::event());
        $event = $this->client()->events->get('req_1');

        self::assertSame('req_1', $event->requestId);
        self::assertSame('vis_1', $event->visitorId);
        self::assertSame(12, $event->suspectScore);
        self::assertTrue($event->isScored());
        self::assertSame('low', $event->suspectLevel);
        self::assertSame('2026-09-18T08:00:00+00:00', $event->occurredAt->format(\DateTimeInterface::RFC3339));
    }

    /**
     * A request id is part of a URL path, so it is escaped rather than trusted
     * to contain no separator of its own.
     */
    public function testEscapesTheRequestIdInThePath(): void
    {
        $this->server = TestServer::always(200, Fixtures::event('req/../secret'));
        $this->client()->events->get('req/../secret?x=1');

        $request = $this->server->first();
        self::assertSame('/api/v1/events/req%2F..%2Fsecret%3Fx%3D1', $request['path']);
        self::assertSame('', $request['query']);
    }

    /**
     * Escaping leaves dots alone and cURL resolves dot segments, so `..` would
     * address a route above /events with the secret key, and `` or `.` the
     * event list. They are refused before anything is sent.
     */
    public function testRefusesARequestIdThatIsADotSegment(): void
    {
        $this->server = TestServer::always(200, Fixtures::event());
        $client = $this->client();

        foreach (['', '.', '..'] as $requestId) {
            try {
                $client->events->get($requestId);
                self::fail(sprintf('request id "%s" should have been refused', $requestId));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(0, $this->server->requestCount(), 'a dot-segment request id was sent');
    }

    /** Only a whole dot segment is refused: a dot inside an id is ordinary. */
    public function testReadsARequestIdThatContainsADot(): void
    {
        $this->server = TestServer::always(200, Fixtures::event('a.b'));
        $event = $this->client()->events->get('a.b');

        self::assertSame('a.b', $event->requestId);
        self::assertSame('/api/v1/events/a.b', $this->server->first()['path']);
    }

    /** null means unscored and must never be read as zero. */
    public function testAnUnscoredEventIsNotAZero(): void
    {
        $this->server = TestServer::always(200, Fixtures::event('req_1', null));
        $event = $this->client()->events->get('req_1');

        self::assertNull($event->suspectScore);
        self::assertFalse($event->isScored());
        self::assertNull($event->suspectLevel);
    }

    /** A field the API adds after this build shipped is still reachable. */
    public function testKeepsTheResponseBodyAsItArrived(): void
    {
        $body = json_decode(Fixtures::event(), true, 32, \JSON_THROW_ON_ERROR);
        $body['invented_later'] = 'still here';
        $this->server = TestServer::always(200, json_encode($body, \JSON_THROW_ON_ERROR));

        $event = $this->client()->events->get('req_1');
        self::assertSame('still here', $event->raw['invented_later']);
    }

    public function testSendsNoQueryWhenNothingIsFiltered(): void
    {
        $this->server = TestServer::always(200, Fixtures::page(1, 50, 0));
        $page = $this->client()->events->list();

        self::assertSame('/api/v1/events', $this->server->first()['path']);
        self::assertSame('', $this->server->first()['query']);
        self::assertSame(1, $page->page);
        self::assertSame(50, $page->pageSize);
        self::assertSame([], $page->rows);
    }

    public function testSendsOnlyTheFiltersThatWereSet(): void
    {
        $this->server = TestServer::always(200, Fixtures::page(2, 25, 1));
        $page = $this->client()->events->list([
            'from' => new \DateTimeImmutable('2026-09-17T00:00:00+00:00'),
            'to' => '2026-09-18T00:00:00Z',
            'page' => 2,
            'limit' => 25,
            'visitor' => 'vis_1',
            'level' => 'high',
        ]);

        $query = $this->server->first()['query'];
        foreach ([
            'from=2026-09-17T00%3A00%3A00%2B00%3A00',
            'to=2026-09-18T00%3A00%3A00Z',
            'page=2',
            'limit=25',
            'visitor=vis_1',
            'level=high',
        ] as $expected) {
            self::assertStringContainsString($expected, $query);
        }
        self::assertCount(1, $page->rows);
        self::assertSame('req_0', $page->rows[0]->requestId);
    }

    public function testRefusesAPageOrLimitOfZero(): void
    {
        $this->server = TestServer::always(200, Fixtures::page());
        $client = $this->client();

        foreach ([['page' => 0], ['limit' => 0], ['page' => -1]] as $query) {
            try {
                $client->events->list($query);
                self::fail('an invalid filter should have been refused');
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(0, $this->server->requestCount(), 'an invalid filter was sent');
    }

    public function testRefusesAFilterItDoesNotKnow(): void
    {
        $this->server = TestServer::always(200, Fixtures::page());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/levl/');
        $this->client()->events->list(['levl' => 'high']);
    }

    public function testRefusesAWindowBoundThatIsNotADate(): void
    {
        $this->server = TestServer::always(200, Fixtures::page());

        $this->expectException(ConfigurationException::class);
        $this->client()->events->list(['from' => 12345]);
    }

    /**
     * A missing event (never issued, or past retention) is reported at once,
     * in one request: there is nothing to wait for.
     */
    public function testReportsAMissingEventWithoutRetrying(): void
    {
        $this->server = TestServer::always(404, Fixtures::notFound());

        try {
            $this->client()->events->get('req_1');
            self::fail('a missing event should have raised');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertTrue($e->isEventNotFound());
            self::assertFalse($e->isPending());
            self::assertSame('req_trace', $e->getRequestId());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /**
     * An accepted request id whose event is still being recorded is a 404 of
     * its own: an API error carrying Retry-After, distinct from a missing
     * event, and never retried by the SDK.
     */
    public function testReportsAPendingEventWithRetryAfterWithoutRetrying(): void
    {
        $this->server = TestServer::always(404, Fixtures::pending(), ['retry-after' => '2']);

        try {
            $this->client()->events->get('req_1');
            self::fail('a pending event should have raised');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertSame('event_pending', $e->getErrorCode());
            self::assertTrue($e->isPending());
            self::assertFalse($e->isEventNotFound());
            self::assertSame(2, $e->getRetryAfter());
            self::assertSame('req_trace', $e->getRequestId());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /** Only a 404 with the pending code is pending. */
    public function testPendingIsNarrow(): void
    {
        $this->server = TestServer::always(429, '{"error":{"code":"event_pending","status":429}}');

        try {
            $this->client()->events->get('req_1');
            self::fail('the read should have raised');
        } catch (ApiException $e) {
            self::assertFalse($e->isPending());
        }
    }

    /** A plain 404 without the structured code is not the missing-event one. */
    public function testDoesNotReadAnUnstructured404AsAMissingEvent(): void
    {
        $this->server = TestServer::always(404, 'not json at all');

        try {
            $this->client()->events->get('req_1');
            self::fail('the read should have raised');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertFalse($e->isEventNotFound());
        }
    }

    /** Every other failure propagates as it arrived, once. */
    public function testPropagatesAnyOtherFailure(): void
    {
        $this->server = TestServer::always(403, json_encode([
            'error' => ['code' => 'forbidden', 'message' => 'no', 'status' => 403],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->client()->events->get('req_1');
            self::fail('the read should have raised');
        } catch (ApiException $e) {
            self::assertSame(403, $e->getStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);
    }
}
