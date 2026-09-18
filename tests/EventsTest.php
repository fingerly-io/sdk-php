<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\ApiException;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\TimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * Event reads, the list filters, and the polling loop.
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
     * Identification answers before the event is archived, so the one
     * retryable failure is polled through.
     */
    public function testPollsUntilTheEventLands(): void
    {
        $this->server = TestServer::start([
            ['status' => 404, 'body' => Fixtures::notFound()],
            ['status' => 404, 'body' => Fixtures::notFound()],
            ['status' => 200, 'body' => Fixtures::event()],
        ]);

        $event = $this->client()->events->waitFor('req_1', ['interval' => 0.01]);

        self::assertSame('req_1', $event->requestId);
        self::assertSame(3, $this->server->requestCount());
    }

    /** Only `404 event_not_found` is retried; everything else propagates. */
    public function testStopsPollingOnAnyOtherFailure(): void
    {
        $this->server = TestServer::always(403, json_encode([
            'error' => ['code' => 'forbidden', 'message' => 'no', 'status' => 403],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->client()->events->waitFor('req_1', ['interval' => 0.01]);
            self::fail('polling should have stopped');
        } catch (ApiException $e) {
            self::assertSame(403, $e->getStatus());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /** A plain 404 without the structured code is not the retryable one. */
    public function testDoesNotPollOnAnUnstructured404(): void
    {
        $this->server = TestServer::always(404, 'not json at all');

        try {
            $this->client()->events->waitFor('req_1', ['interval' => 0.01]);
            self::fail('polling should have stopped');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertFalse($e->isEventNotFound());
        }
        self::assertSame(1, $this->server->requestCount());
    }

    /** The reason it kept waiting is kept, not just the fact of a deadline. */
    public function testGivesUpAtTheWaitDeadlineAndSaysWhy(): void
    {
        $this->server = TestServer::always(404, Fixtures::notFound());

        try {
            $this->client()->events->waitFor('req_1', ['timeout' => 0.3, 'interval' => 0.05]);
            self::fail('the wait should have timed out');
        } catch (TimeoutException $e) {
            self::assertSame(TimeoutException::OPERATION_EVENT_WAIT, $e->getOperation());
            $previous = $e->getPrevious();
            self::assertInstanceOf(ApiException::class, $previous);
            self::assertTrue($previous->isEventNotFound());
        }
    }

    public function testRefusesAWaitThatCouldNeverPoll(): void
    {
        $this->server = TestServer::always(200, Fixtures::event());
        $client = $this->client();

        foreach ([['timeout' => 0], ['interval' => 0], ['timeout' => -1]] as $options) {
            try {
                $client->events->waitFor('req_1', $options);
                self::fail('an impossible wait should have been refused');
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(0, $this->server->requestCount());
    }

    private function client(): Client
    {
        self::assertNotNull($this->server);

        return new Client(TestServer::SECRET_KEY, ['endpoint' => $this->server->baseUrl()]);
    }
}
