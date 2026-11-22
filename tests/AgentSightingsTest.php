<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\AgentSighting;
use Fingerly\AgentSightings;
use Fingerly\AgentSightings\Apcu;
use Fingerly\AgentSightings\ApcuStore;
use Fingerly\AgentSightings\MemoryStore;
use Fingerly\AgentSightings\Sighting;
use Fingerly\AgentSightings\Store;
use Fingerly\Client;
use Fingerly\ConfigurationException;
use Fingerly\Proxy\ProxyRequest;
use Fingerly\VisitorKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The agent sightings cache, buffer and flusher, against a recording server,
 * on every store: in memory, APCu over a fake, and real APCu when loaded.
 *
 * @internal
 */
final class AgentSightingsTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
    private const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot';

    private ?TestServer $server = null;
    private float $now = 1000.0;
    private int $wall = 1_790_000_000;

    /** The store the last {@see self::sightings()} call used. */
    private ?Store $lastStore = null;

    /** @var list<string> */
    private array $logs = [];

    /** @var list<\Closure(): void> */
    private array $deferred = [];

    /** @var null|resource the process behind {@see self::startRawServer()} */
    private mixed $rawServer = null;

    protected function setUp(): void
    {
        AgentSightings::resetLogs();
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        if (is_resource($this->rawServer)) {
            proc_terminate($this->rawServer);
            proc_close($this->rawServer);
        }
        $this->rawServer = null;
    }

    #[DataProvider('stores')]
    public function testLookupIsUnknownUntilTheApiAnswers(string $store): void
    {
        $this->serve([$this->answer('r1', [[900, VisitorKind::AI_BOT, 'GPTBot']])]);
        $sightings = $this->sightings($store);
        $request = self::request(self::GPTBOT, '::ffff:20.171.206.9');

        self::assertSame(AgentSighting::unknown(), $sightings->lookup($request));
        $sightings->report($request, 'text/html; charset=utf-8');
        self::assertSame(AgentSighting::unknown(), $sightings->lookup($request), 'lookup never records');
        $sightings->flush();

        $items = $this->sentItems(0);
        self::assertSame([[
            'user_agent' => self::GPTBOT,
            'client_ip' => '20.171.206.9',
            'host' => 'example.com',
            'method' => 'GET',
            'count' => 1,
        ]], $items);

        $answer = $sightings->lookup($request);
        self::assertSame(VisitorKind::AI_BOT, $answer->visitorKind);
        self::assertSame('GPTBot', $answer->agent);
        self::assertSame(900, $answer->ttl);
        // The same address in its IPv4 form has the same key.
        self::assertSame('GPTBot', $sightings->lookup(self::request(self::GPTBOT, '20.171.206.9'))->agent);
    }

    #[DataProvider('stores')]
    public function testRepeatsFoldIntoOneItemWithACount(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN], [60, VisitorKind::AI_BOT]])]);
        $sightings = $this->sightings($store);
        for ($i = 0; $i < 5; ++$i) {
            $sightings->report(self::request(self::BROWSER, '203.0.113.7'), 'text/html');
        }
        $sightings->report(self::request(self::GPTBOT, '203.0.113.7'), 'text/html');
        $sightings->flush();

        $items = $this->sentItems(0);
        self::assertCount(2, $items);
        self::assertSame(self::BROWSER, $items[0]['user_agent']);
        self::assertSame(5, $items[0]['count']);
        self::assertSame(1, $items[1]['count']);
    }

    #[DataProvider('stores')]
    public function testCacheHitsAreCountedOnTheNextReport(string $store): void
    {
        $this->serve([$this->answer('r1', [[300, VisitorKind::AI_BOT, 'GPTBot']])]);
        $sightings = $this->sightings($store);
        $request = self::request(self::GPTBOT, '20.171.206.9');
        $sightings->report($request, 'text/html');
        $sightings->flush();

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('GPTBot', $sightings->lookup($request)->agent);
            $sightings->report($request, 'text/html');
        }
        $sightings->flush();

        self::assertSame(2, $this->server()->requestCount());
        $items = $this->sentItems(1);
        self::assertCount(1, $items);
        self::assertSame(3, $items[0]['count']);
    }

    #[DataProvider('stores')]
    public function testSignedRequestsAreNeverFoldedNorCached(string $store): void
    {
        $this->serve([$this->answer('r1', [[0, VisitorKind::AI_BOT, 'ChatGPT agent'], [0, VisitorKind::AI_BOT, 'ChatGPT agent'], [0, VisitorKind::AI_BOT, 'ChatGPT agent']])]);
        $sightings = $this->sightings($store);
        $request = self::request(self::BROWSER, '34.31.25.14', '/', [
            'signature' => 'sig1=:abc:',
            'signature-input' => 'sig1=("@authority");created=1',
            'signature-agent' => '"https://chatgpt.com"',
        ]);
        for ($i = 0; $i < 3; ++$i) {
            $sightings->report($request, 'text/html');
        }
        $sightings->flush();

        $items = $this->sentItems(0);
        self::assertCount(3, $items);
        foreach ($items as $item) {
            self::assertSame(1, $item['count']);
            self::assertSame('sig1=:abc:', $item['signature']);
            self::assertSame('sig1=("@authority");created=1', $item['signature_input']);
            self::assertSame('"https://chatgpt.com"', $item['signature_agent']);
        }
        // ttl 0: checked every time, never remembered.
        self::assertTrue($sightings->lookup($request)->isUnknown());
    }

    #[DataProvider('stores')]
    public function testAnAnswerExpiresAfterItsTtl(string $store): void
    {
        $this->serve([$this->answer('r1', [[10, VisitorKind::SEARCH_CRAWLER, 'Googlebot']])]);
        $sightings = $this->sightings($store);
        $request = self::request('Googlebot/2.1', '66.249.66.1');
        $sightings->report($request, 'text/html');
        $sightings->flush();

        $this->now += 9.9;
        self::assertSame(VisitorKind::SEARCH_CRAWLER, $sightings->lookup($request)->visitorKind);
        $this->now += 0.1;
        self::assertTrue($sightings->lookup($request)->isUnknown());
    }

    #[DataProvider('stores')]
    public function testATtlOfZeroIsNeverCached(string $store): void
    {
        $this->serve([$this->answer('r1', [[0, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $request = self::request(self::BROWSER, '203.0.113.7');
        $sightings->report($request, 'text/html');
        $sightings->flush();

        self::assertTrue($sightings->lookup($request)->isUnknown());
    }

    #[DataProvider('stores')]
    public function testATtlIsHeldToADay(string $store): void
    {
        $this->serve([$this->answer('r1', [[864000, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $request = self::request(self::BROWSER, '203.0.113.7');
        $sightings->report($request, 'text/html');
        $sightings->flush();

        $this->now += 86399;
        self::assertSame(VisitorKind::HUMAN, $sightings->lookup($request)->visitorKind);
        ++$this->now;
        self::assertTrue($sightings->lookup($request)->isUnknown(), 'remembered for 86400 seconds at most');
    }

    #[DataProvider('stores')]
    public function testANewRulesVersionDropsEveryAnswer(string $store): void
    {
        $this->serve([
            $this->answer('r1', [[3600, VisitorKind::HUMAN], [3600, VisitorKind::HUMAN]]),
            $this->answer('r1', [[3600, VisitorKind::HUMAN]]),
            $this->answer('r2', [[3600, VisitorKind::AI_BOT]]),
        ]);
        $sightings = $this->sightings($store);
        $a = self::request('a', '203.0.113.1');
        $b = self::request('b', '203.0.113.2');
        $c = self::request('c', '203.0.113.3');
        $d = self::request('d', '203.0.113.4');
        $sightings->report($a, 'text/html');
        $sightings->report($b, 'text/html');
        $sightings->flush();
        $sightings->report($c, 'text/html');
        $sightings->flush();
        self::assertFalse($sightings->lookup($a)->isUnknown());
        self::assertFalse($sightings->lookup($c)->isUnknown());

        $sightings->report($d, 'text/html');
        $sightings->flush();

        self::assertTrue($sightings->lookup($a)->isUnknown());
        self::assertTrue($sightings->lookup($b)->isUnknown());
        self::assertTrue($sightings->lookup($c)->isUnknown());
        self::assertSame(VisitorKind::AI_BOT, $sightings->lookup($d)->visitorKind);
    }

    #[DataProvider('stores')]
    public function testTheCacheIsBounded(string $store): void
    {
        $capacity = 8;
        $rows = array_fill(0, 40, [3600, VisitorKind::HUMAN]);
        $this->serve([$this->answer('r1', $rows)]);
        $sightings = $this->sightings($store, $capacity);
        $requests = [];
        for ($i = 0; $i < 40; ++$i) {
            $requests[] = $request = self::request('ua '.$i, '203.0.113.'.($i + 1));
            $sightings->report($request, 'text/html');
        }
        $sightings->flush();

        $remembered = array_filter($requests, static fn (ProxyRequest $request): bool => !$sightings->lookup($request)->isUnknown());
        self::assertCount($capacity, $remembered);
        // The newest answers are the ones kept.
        self::assertFalse($sightings->lookup($requests[39])->isUnknown());
        self::assertTrue($sightings->lookup($requests[0])->isUnknown());
    }

    public function testTheMemoryCacheEvictsTheLeastRecentlyUsed(): void
    {
        $this->serve([
            $this->answer('r1', [[3600, VisitorKind::HUMAN], [3600, VisitorKind::HUMAN], [3600, VisitorKind::HUMAN]]),
            $this->answer('r1', [[3600, VisitorKind::HUMAN]]),
        ]);
        $sightings = $this->sightings('memory', 3);
        $a = self::request('a', '203.0.113.1');
        $b = self::request('b', '203.0.113.2');
        $c = self::request('c', '203.0.113.3');
        $d = self::request('d', '203.0.113.4');
        foreach ([$a, $b, $c] as $request) {
            $sightings->report($request, 'text/html');
        }
        $sightings->flush();
        // Touch a, so b is now the least recently used.
        self::assertFalse($sightings->lookup($a)->isUnknown());
        $sightings->report($d, 'text/html');
        $sightings->flush();

        self::assertFalse($sightings->lookup($a)->isUnknown());
        self::assertTrue($sightings->lookup($b)->isUnknown());
        self::assertFalse($sightings->lookup($c)->isUnknown());
        self::assertFalse($sightings->lookup($d)->isUnknown());
    }

    public function testTheCapacityIsBoundedByConfiguration(): void
    {
        foreach ([0, -1, '4096', 1.5] as $capacity) {
            try {
                new AgentSightings(['secret_key' => TestServer::SECRET_KEY, 'client_ip' => static fn (): string => '192.0.2.1', 'capacity' => $capacity, 'long_running' => true]);
                self::fail('capacity '.var_export($capacity, true).' must be refused');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([1, 65536, 65537, 10_000_000] as $capacity) {
            $sightings = new AgentSightings(['secret_key' => TestServer::SECRET_KEY, 'client_ip' => static fn (): string => '192.0.2.1', 'capacity' => $capacity, 'long_running' => true]);
            self::assertTrue($sightings->isEnabled(), 'capacity '.$capacity.' is accepted');
        }
    }

    public function testACapacityOverTheMaximumIsHeldToIt(): void
    {
        $this->serve([$this->answer('r1', array_fill(0, 1, [3600, VisitorKind::HUMAN]))]);
        // Through the store the constructor builds, which reads the clamped value.
        $sightings = new AgentSightings([
            'secret_key' => TestServer::SECRET_KEY,
            'client_ip' => static fn (ProxyRequest $request): ?string => $request->remoteAddress,
            'endpoint' => $this->server()->baseUrl(),
            'allow_insecure_loopback' => true,
            'capacity' => 1_000_000,
            'long_running' => true,
        ]);
        $store = (new \ReflectionProperty(AgentSightings::class, 'store'))->getValue($sightings);
        self::assertInstanceOf(MemoryStore::class, $store);
        self::assertSame(AgentSightings::MAX_CAPACITY, (new \ReflectionProperty(MemoryStore::class, 'capacity'))->getValue($store));
    }

    #[DataProvider('stores')]
    public function testFlushesOnceTwoHundredArePending(string $store): void
    {
        $this->serve([$this->answer('r1', array_fill(0, 200, [60, VisitorKind::HUMAN]))]);
        $sightings = $this->sightings($store);
        for ($i = 0; $i < 199; ++$i) {
            $sightings->report(self::request('ua '.$i, '198.51.100.1'), 'text/html');
        }
        self::assertSame([], $this->deferred, 'not due before 200');
        $sightings->report(self::request('ua 199', '198.51.100.1'), 'text/html');
        $sightings->report(self::request('ua 200', '198.51.100.1'), 'text/html');
        self::assertCount(1, $this->deferred, 'one deferred flush per request cycle');
        self::assertSame(0, $this->server()->requestCount(), 'nothing is sent while reporting');

        $this->runDeferred();

        self::assertSame(1, $this->server()->requestCount());
        self::assertCount(200, $this->sentItems(0));
    }

    #[DataProvider('stores')]
    public function testFlushesOnceSixtyFourKibibytesArePending(string $store): void
    {
        $this->serve([$this->answer('r1', array_fill(0, 16, [60, VisitorKind::HUMAN]))]);
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        // Each item measures its user agent, address (12), host (11) and
        // method (3), with nothing for the JSON around them: 4027 bytes for
        // the first ten, 4028 after.
        $long = str_repeat('a', 4000);
        for ($i = 0; $i < 16; ++$i) {
            $sightings->report(self::request($long.$i, '198.51.100.1'), 'text/html');
        }
        self::assertSame(10 * 4027 + 6 * 4028, $backing->pending()['bytes']);
        self::assertSame([], $this->deferred, '64438 bytes is not due');
        $sightings->report(self::request($long.'16', '198.51.100.1'), 'text/html');
        self::assertCount(1, $this->deferred, '68466 bytes is');
        $this->runDeferred();

        // A batch stops before the item that would pass 64 KiB: the
        // seventeenth waits.
        self::assertSame(1, $this->server()->requestCount());
        self::assertCount(16, $this->sentItems(0));
        self::assertSame(1, $backing->pending()['items']);
        self::assertSame(4028, $backing->pending()['bytes']);
    }

    #[DataProvider('stores')]
    public function testFlushesTwoSecondsAfterTheOldestItem(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN], [60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $this->now += 1.9;
        $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
        $sightings->flushIfDue();
        self::assertSame([], $this->deferred);
        self::assertSame(0, $this->server()->requestCount());

        $this->now += 0.1;
        $sightings->flushIfDue();
        self::assertSame(1, $this->server()->requestCount());
        self::assertCount(2, $this->sentItems(0));
    }

    #[DataProvider('stores')]
    public function testADueReportDefersTheFlush(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN], [60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $this->now += 2.0;
        $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
        self::assertCount(1, $this->deferred);
        self::assertSame(0, $this->server()->requestCount());
        $this->runDeferred();
        self::assertSame(1, $this->server()->requestCount());
    }

    #[DataProvider('stores')]
    public function testOneFlushAtATime(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        $sightings->useFlightWait(0.2);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $this->now += 2;
        self::assertTrue($backing->lock());
        $sightings->flushIfDue();
        self::assertSame(0, $this->server()->requestCount(), 'a due flush does not wait');
        $started = microtime(true);
        $sightings->flush();
        self::assertGreaterThanOrEqual(0.19, microtime(true) - $started, 'an explicit flush waits for the one in flight');
        self::assertSame(0, $this->server()->requestCount());
        $backing->unlock();
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
    }

    #[DataProvider('stores')]
    public function testAnInvalidRequestDropsTheBatchAndLogsOncePerMinute(string $store): void
    {
        $this->serve([['status' => 422, 'body' => '{"error":{"code":"invalid_request","message":"x","status":422}}']]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount(), 'no pause after a 422');
        self::assertCount(1, $this->sentItems(1), 'the first batch was dropped, not resent');
        self::assertCount(1, $this->logsMatching('did not take a report'));

        $this->now += 60;
        $sightings->report(self::request('c', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertCount(2, $this->logsMatching('did not take a report'));
        foreach ($this->logs as $line) {
            self::assertStringNotContainsString('198.51.100.1', $line, 'no sighting field is logged');
            self::assertStringNotContainsString('example.com', $line);
        }
    }

    #[DataProvider('stores')]
    public function testARateLimitKeepsTheBatchUntilRetryAfter(string $store): void
    {
        $this->serve([
            ['status' => 429, 'body' => '{"error":{"code":"rate_limited","message":"x","status":429}}', 'headers' => ['retry-after' => '30']],
            $this->answer('r1', [[60, VisitorKind::HUMAN]]),
        ]);
        $sightings = $this->sightings($store);
        $request = self::request('a', '198.51.100.1');
        $sightings->report($request, 'text/html');
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());

        // Still counting while paused; nothing is sent.
        $sightings->report($request, 'text/html');
        $this->now += 29.9;
        $sightings->flush();
        $sightings->flushIfDue();
        self::assertSame(1, $this->server()->requestCount());
        self::assertTrue($sightings->lookup($request)->isUnknown());

        $this->now += 0.1;
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());
        $items = $this->sentItems(1);
        self::assertCount(1, $items);
        self::assertSame(2, $items[0]['count']);
        self::assertFalse($sightings->lookup($request)->isUnknown());
    }

    #[DataProvider('stores')]
    public function testTheDailyAllowanceWaitsForTheNextUtcDay(string $store): void
    {
        $this->wall = 86400 * 20000 + 3600;
        $this->serve([
            ['status' => 429, 'body' => '', 'headers' => ['fingerly-ratelimit-scope' => 'daily']],
            $this->answer('r1', [[60, VisitorKind::HUMAN]]),
        ]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        $this->now += 82799;
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
        ++$this->now;
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());
    }

    #[DataProvider('stores')]
    public function testARateLimitWithoutRetryAfterWaitsAMinute(string $store): void
    {
        $this->serve([['status' => 429, 'body' => ''], $this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        $this->now += 59;
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
        ++$this->now;
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());
    }

    #[DataProvider('stores')]
    public function testARetryAfterOfZeroPausesASecond(string $store): void
    {
        foreach (['0', 'Thu, 01 Jan 1970 00:00:00 GMT'] as $retryAfter) {
            $this->serve([
                ['status' => 429, 'body' => '', 'headers' => ['retry-after' => $retryAfter]],
                $this->answer('r1', [[60, VisitorKind::HUMAN]]),
            ]);
            $sightings = $this->sightings($store);
            $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
            $sightings->flush();
            $this->now += 0.9;
            $sightings->flush();
            self::assertSame(1, $this->server()->requestCount(), $retryAfter.' pauses at least a second');
            $this->now += 0.1;
            $sightings->flush();
            self::assertSame(2, $this->server()->requestCount(), $retryAfter);
        }
    }

    #[DataProvider('stores')]
    public function testARetryAfterOverADayPausesADay(string $store): void
    {
        // Far-future values must not stop reporting for the life of the
        // process, or of the PHP-FPM pool that shares the pause in APCu. The
        // twenty-digit one is not a number PHP's int can hold, so it reads as
        // no Retry-After, a minute.
        foreach (['2147483647', '9999999999', 'Fri, 01 Jan 2100 00:00:00 GMT', '99999999999999999999'] as $retryAfter) {
            $this->serve([
                ['status' => 429, 'body' => '', 'headers' => ['retry-after' => $retryAfter]],
                $this->answer('r1', [[60, VisitorKind::HUMAN]]),
            ]);
            $sightings = $this->sightings($store);
            $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
            $sightings->flush();
            self::assertLessThanOrEqual($this->now + 86400.0, $this->lastStore?->state()['resume_at'], $retryAfter.' pauses a day at most');
            $this->now += 86400;
            $sightings->flush();
            self::assertSame(2, $this->server()->requestCount(), $retryAfter.': sending resumes after a day');
        }
    }

    #[DataProvider('stores')]
    public function testAnUnreadableRetryAfterCountsAsNone(string $store): void
    {
        foreach (['-5', 'soon', '1.5'] as $retryAfter) {
            $this->serve([
                ['status' => 429, 'body' => '', 'headers' => ['retry-after' => $retryAfter]],
                $this->answer('r1', [[60, VisitorKind::HUMAN]]),
            ]);
            $sightings = $this->sightings($store);
            $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
            $sightings->flush();
            $this->now += 59;
            $sightings->flush();
            self::assertSame(1, $this->server()->requestCount(), $retryAfter.' waits a minute, as when missing');
            ++$this->now;
            $sightings->flush();
            self::assertSame(2, $this->server()->requestCount(), $retryAfter);
        }

        $this->wall = 86400 * 20000 + 3600;
        $this->serve([
            ['status' => 429, 'body' => '', 'headers' => ['retry-after' => 'soon', 'fingerly-ratelimit-scope' => 'daily']],
            $this->answer('r1', [[60, VisitorKind::HUMAN]]),
        ]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        $this->now += 82799;
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount(), 'the daily allowance waits for the next UTC day');
        ++$this->now;
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());
    }

    #[DataProvider('stores')]
    public function testARefusedKeyDropsTheBatchAndPausesAMinute(string $store): void
    {
        foreach ([401, 403] as $status) {
            AgentSightings::resetLogs();
            $this->logs = [];
            $this->serve([
                ['status' => $status, 'body' => ''],
                ['status' => $status, 'body' => ''],
                $this->answer('r1', [[60, VisitorKind::HUMAN]]),
                ['status' => $status, 'body' => ''],
            ]);
            $sightings = $this->sightings($store);
            $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
            $sightings->flush();
            $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
            $this->now += 59;
            $sightings->flush();
            self::assertSame(1, $this->server()->requestCount(), $status.' pauses for 60 seconds');

            ++$this->now;
            $sightings->flush();
            self::assertSame(2, $this->server()->requestCount());
            // a was dropped; only b went the second time.
            self::assertSame('b', $this->sentItems(1)[0]['user_agent']);
            // Logged at most once a minute: the second refusal came a minute later.
            self::assertCount(2, $this->logsMatching('refused the secret key'), $status.' is logged once a minute');

            $this->now += 60;
            $sightings->report(self::request('c', '198.51.100.1'), 'text/html');
            $sightings->flush();
            self::assertSame(3, $this->server()->requestCount());
            $sightings->report(self::request('d', '198.51.100.1'), 'text/html');
            $sightings->flush();
            self::assertSame(4, $this->server()->requestCount());
            self::assertCount(3, $this->logsMatching('refused the secret key'));
        }
    }

    #[DataProvider('stores')]
    public function testARefusedKeyIsNotLoggedTwiceWithinAMinute(string $store): void
    {
        $this->serve([['status' => 401, 'body' => '']]);
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        // Another worker could send in the same minute; lift the pause by hand.
        $state = $backing->state();
        $state['resume_at'] = 0.0;
        $backing->saveState($state);
        $this->now += 30;
        $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());
        self::assertCount(1, $this->logsMatching('refused the secret key'));
    }

    #[DataProvider('droppedStatuses')]
    public function testEveryOtherStatusDropsTheBatchAndLogsOncePerMinute(string $store, int $status): void
    {
        $this->serve([['status' => $status, 'body' => '']]);
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertSame(0, $backing->pending()['items'], 'dropped, not kept');
        self::assertSame(0.0, $backing->state()['resume_at'], 'no pause or backoff');
        $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());
        self::assertSame('b', $this->sentItems(1)[0]['user_agent']);
        self::assertCount(1, $this->logsMatching('did not take a report'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function droppedStatuses(): iterable
    {
        foreach (self::stores() as $name => [$store]) {
            foreach ([500, 502, 504, 413, 400, 404, 409] as $status) {
                yield $name.' '.$status => [$store, $status];
            }
        }
    }

    #[DataProvider('stores')]
    public function testAServerErrorKeepsTheBatchWithExponentialBackoff(string $store): void
    {
        $this->serve([
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
            $this->answer('r1', [[60, VisitorKind::HUMAN]]),
        ]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());

        $this->now += 0.9;
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount(), 'backoff 1 s');
        $this->now += 0.1;
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount());

        $this->now += 1.9;
        $sightings->flush();
        self::assertSame(2, $this->server()->requestCount(), 'backoff 2 s');
        $this->now += 0.1;
        $sightings->flush();
        self::assertSame(3, $this->server()->requestCount());

        $this->now += 4;
        $sightings->flush();
        self::assertSame(4, $this->server()->requestCount(), 'backoff 4 s');
        foreach ($this->server()->requests() as $request) {
            self::assertSame('a', json_decode($request['body'], true)['items'][0]['user_agent']);
        }
    }

    public function testTheBackoffIsCappedAtAMinute(): void
    {
        $this->serve([['status' => 503, 'body' => '']]);
        $backing = new MemoryStore(4096);
        $sightings = $this->sightings('memory', 4096, $backing);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        for ($i = 0; $i < 9; ++$i) {
            $sightings->flush();
            $this->now += 64;
        }
        self::assertSame(60.0, $backing->state()['backoff']);
        self::assertSame(9, $this->server()->requestCount());
    }

    #[DataProvider('stores')]
    public function testATransportErrorKeepsTheBatch(string $store): void
    {
        $sightings = $this->sightings($store, 4096, null, 'http://127.0.0.1:'.self::closedPort());
        $request = self::request('a', '198.51.100.1');
        $sightings->report($request, 'text/html');
        $sightings->flush();

        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $retry = $this->sightings($store, 4096, $this->lastStore);
        ++$this->now;
        $retry->flush();
        self::assertSame(1, $this->server()->requestCount());
        self::assertSame('a', $this->sentItems(0)[0]['user_agent']);
    }

    public function testATimeoutAfterSendingDropsTheBatchAndBacksOff(): void
    {
        $this->serve([['status' => 200, 'body' => '{}', 'delay' => 6.0]]);
        $backing = new MemoryStore(4096);
        $sightings = $this->sightings('memory', 4096, $backing);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $started = microtime(true);
        $sightings->flush();
        self::assertLessThan(5.9, microtime(true) - $started, 'the deadline is 5 seconds');
        self::assertSame(0, $backing->pending()['items'], 'the batch is not kept');
        self::assertSame(1.0, $backing->state()['backoff'], 'sending backs off, so a silent API does not hold a worker every time a send comes due');
        self::assertGreaterThan($this->now, $backing->state()['resume_at']);
        self::assertCount(1, $this->logsMatching('timed out'));
    }

    /**
     * Closed with no answer, reset, or a body cut short, each once the whole
     * report was read: the API may have counted it.
     */
    #[DataProvider('lostConnections')]
    public function testAConnectionLostAfterSendingDropsTheBatchAndBacksOff(string $mode): void
    {
        if ('reset' === $mode && !\function_exists('socket_import_stream')) {
            self::markTestSkipped('resetting a connection needs ext-sockets');
        }
        $backing = new MemoryStore(4096);
        $sightings = $this->sightings('memory', 4096, $backing, $this->startRawServer($mode));
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        self::assertSame(0, $backing->pending()['items'], $mode.': the batch is not kept');
        self::assertSame(1.0, $backing->state()['backoff'], $mode.': sending backs off');
        self::assertCount(1, $this->logsMatching('connection failed after a report was sent'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lostConnections(): iterable
    {
        yield 'closed' => ['close'];

        yield 'reset' => ['reset'];

        yield 'cut short' => ['short'];
    }

    #[DataProvider('stores')]
    public function testAnAnswerOfTheWrongLengthDropsTheBatch(string $store): void
    {
        $this->serve([$this->answer('r1', [])]);
        $sightings = $this->sightings($store);
        $request = self::request('a', '198.51.100.1');
        $sightings->report($request, 'text/html');
        $sightings->flush();
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
        self::assertTrue($sightings->lookup($request)->isUnknown());
        self::assertCount(1, $this->logsMatching('different number'));
    }

    #[DataProvider('stores')]
    public function testAnUnreadableAnswerDropsTheBatch(string $store): void
    {
        $this->serve([['status' => 200, 'body' => 'not json']]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1'), 'text/html');
        $sightings->flush();
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
        self::assertCount(1, $this->logsMatching('did not take a report'));
    }

    #[DataProvider('stores')]
    public function testAFullBufferDropsNewKeysAndCountsThem(string $store): void
    {
        $this->serve([$this->answer('r1', array_fill(0, 200, [60, VisitorKind::HUMAN]))]);
        $sightings = $this->sightings($store);
        $sightings->deferUsing(null);
        for ($i = 0; $i < 1000; ++$i) {
            $sightings->report(self::request('ua '.$i, '198.51.100.1'), 'text/html');
        }
        self::assertSame(0, $sightings->droppedCount());
        $sightings->report(self::request('one too many', '198.51.100.1'), 'text/html');
        $sightings->report(self::request('another', '198.51.100.1'), 'text/html');
        self::assertSame(2, $sightings->droppedCount());
        // An existing key keeps counting.
        $sightings->report(self::request('ua 0', '198.51.100.1'), 'text/html');
        $sightings->flush();

        $items = $this->sentItems(0);
        self::assertSame('ua 0', $items[0]['user_agent']);
        self::assertSame(2, $items[0]['count']);
        self::assertSame(5, $this->server()->requestCount(), 'the whole buffer, 200 at a time');
        foreach ($this->server()->requests() as $request) {
            self::assertStringNotContainsString('one too many', $request['body']);
        }
    }

    #[DataProvider('stores')]
    public function testAKeptBatchReturnsToTheFrontAndFoldsIntoNewerItems(string $store): void
    {
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        $a = self::request('a', '198.51.100.1');
        $b = self::request('b', '198.51.100.1');
        $c = self::request('c', '198.51.100.1');
        $sightings->report($a, 'text/html');
        $sightings->report($b, 'text/html');
        $batch = $backing->take(200, 65536, $this->now);
        self::assertCount(2, $batch);
        // While the batch is out, a second later: b again, and c.
        ++$this->now;
        $sightings->report($b, 'text/html');
        $sightings->report($c, 'text/html');
        $backing->putBack($batch);
        self::assertSame(3, $backing->pending()['items']);
        self::assertSame(1000.0, $backing->pending()['oldest'], 'the kept items keep their first-seen time');

        $again = $backing->take(200, 65536, $this->now);
        self::assertSame(['a', 'b', 'c'], array_map(static fn ($item): string => $item->sighting->userAgent, $again));
        self::assertSame([1, 2, 1], array_map(static fn ($item): int => $item->count, $again));
        self::assertSame([1000.0, 1000.0, 1001.0], array_map(static fn ($item): float => $item->firstSeen, $again));
        self::assertSame(0, $backing->pending()['items']);
        self::assertNull($backing->pending()['oldest']);
    }

    #[DataProvider('stores')]
    public function testTheCapCountsTheBatchInFlight(string $store): void
    {
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        $sightings->deferUsing(null);
        for ($i = 0; $i < 1000; ++$i) {
            $sightings->report(self::request('old '.$i, '198.51.100.1'), 'text/html');
        }
        $batch = $backing->take(200, 65536, $this->now);
        self::assertSame(800, $backing->pending()['items']);
        for ($i = 0; $i < 200; ++$i) {
            $sightings->report(self::request('new '.$i, '198.51.100.1'), 'text/html');
        }
        self::assertSame(200, $sightings->droppedCount(), 'no room while 200 are in flight');
        // An existing key still counts while full.
        $sightings->report(self::request('old 500', '198.51.100.1'), 'text/html');
        $backing->putBack($batch);
        self::assertSame(1000, $backing->pending()['items'], 'the kept batch always fits');
        self::assertSame(200, $sightings->droppedCount(), 'merging back drops nothing');

        // Once the batch in flight is settled, there is room again.
        $taken = $backing->take(200, 65536, $this->now);
        $backing->settle($taken);
        $sightings->report(self::request('fits now', '198.51.100.1'), 'text/html');
        self::assertSame(801, $backing->pending()['items']);
        self::assertSame(200, $sightings->droppedCount());
    }

    #[DataProvider('stores')]
    public function testCountsStopAtTheApiLimit(string $store): void
    {
        $backing = $this->store($store);
        $sightings = $this->sightings($store, 4096, $backing);
        $sightings->deferUsing(null);
        $sighting = Sighting::of('a', '198.51.100.1', 'h', 'GET', '', '', '');
        self::assertNotNull($sighting);
        $backing->add($sighting, $this->now);
        $batch = $backing->take(1, 65536, $this->now);
        $batch[0]->count = 999_999;
        $backing->add($sighting, $this->now);
        $backing->add($sighting, $this->now);
        $backing->putBack($batch);
        self::assertSame(1_000_000, $backing->take(1, 65536, $this->now)[0]->count);
    }

    #[DataProvider('stores')]
    public function testOversizeHeaders(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $long = str_repeat('x', 4097);
        $tooLong = self::request($long, '198.51.100.1');
        $sightings->report($tooLong, 'text/html');
        $sightings->report(self::request('a', '198.51.100.1', '/', [
            'host' => $long,
            'signature' => $long,
            'signature-input' => 'sig1=()',
            'signature-agent' => $long,
        ]), 'text/html');
        $sightings->flush();

        // Host is sent as received, whatever its length; the Web Bot Auth
        // headers over 4096 bytes are left out.
        $items = $this->sentItems(0);
        self::assertSame([['user_agent' => 'a', 'client_ip' => '198.51.100.1', 'host' => $long, 'method' => 'GET', 'signature_input' => 'sig1=()', 'count' => 1]], $items);
        self::assertTrue($sightings->lookup($tooLong)->isUnknown());
    }

    #[DataProvider('stores')]
    public function testBlankSignatureHeadersAreAbsent(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $blank = self::request('a', '198.51.100.1', '/', [
            'signature' => ' ',
            'signature-input' => "\t",
            'signature-agent' => '  ',
        ]);
        $bare = self::request('a', '198.51.100.1');
        $sightings->report($blank, 'text/html');
        $sightings->report($bare, 'text/html');
        $sightings->flush();

        // Left out of the item, unsigned and keyed as if never sent, so both
        // fold into one item and share one answer.
        self::assertSame([['user_agent' => 'a', 'client_ip' => '198.51.100.1', 'host' => 'example.com', 'method' => 'GET', 'count' => 2]], $this->sentItems(0));
        self::assertSame(VisitorKind::HUMAN, $sightings->lookup($blank)->visitorKind);
    }

    #[DataProvider('stores')]
    public function testNoClientAddressIsNotReportedAndLoggedOncePerMinute(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        foreach ([static fn (): ?string => null, static fn (): string => '203.0.113.1, 198.51.100.1', static function (): string {
            throw new \RuntimeException('boom');
        }] as $callback) {
            $sightings = $this->sightings($store, 4096, null, null, ['client_ip' => $callback]);
            $request = self::request('a', '198.51.100.1');
            $sightings->report($request, 'text/html');
            $sightings->flush();
            self::assertTrue($sightings->lookup($request)->isUnknown());
        }
        self::assertSame(0, $this->server()->requestCount());
        self::assertCount(1, $this->logsMatching('client_ip callback'), 'at most once a minute');

        $this->now += 60;
        $this->sightings($store, 4096, null, null, ['client_ip' => static fn (): ?string => null])
            ->report(self::request('a', '198.51.100.1'), 'text/html')
        ;
        self::assertCount(2, $this->logsMatching('client_ip callback'));
    }

    #[DataProvider('stores')]
    public function testNeverSendsThePathQueryCookiesOrAuthorization(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $sightings->report(new ProxyRequest('POST', '/account/secretpath', [
            'user-agent' => 'a',
            'host' => 'example.com',
            'cookie' => 'session=secretcookie',
            'authorization' => 'Bearer secrettoken',
            'x-forwarded-for' => '192.0.2.200',
            'referer' => 'https://example.com/secretreferer',
        ], 'secretbody', '198.51.100.1'), 'text/html');
        $sightings->flush();

        $body = $this->server()->first()['body'];
        self::assertStringNotContainsString('secret', $body);
        self::assertStringNotContainsString('192.0.2.200', $body);
        self::assertSame(['user_agent', 'client_ip', 'host', 'method', 'count'], array_keys($this->sentItems(0)[0]));
        self::assertSame('POST', $this->sentItems(0)[0]['method']);
    }

    public function testTheDefaultScope(): void
    {
        $sightings = $this->sightings('memory');
        self::assertTrue($sightings->inScope('/', 'text/html'));
        self::assertTrue($sightings->inScope('/a', 'TEXT/HTML; charset=utf-8'));
        self::assertTrue($sightings->inScope('/a', 'application/xhtml+xml'));
        self::assertFalse($sightings->inScope('/api/orders', 'application/json'));
        self::assertFalse($sightings->inScope('/app.js', 'text/javascript'));
        self::assertFalse($sightings->inScope('/health', ''));
        self::assertFalse($sightings->inScope('/a', 'text/plain'));
        foreach (AgentSightings::WELL_KNOWN_PATHS as $path) {
            self::assertTrue($sightings->inScope($path, 'text/plain'));
            self::assertTrue($sightings->inScope($path, ''));
        }
        self::assertFalse($sightings->inScope('/robots.txt.bak', 'text/plain'));
    }

    public function testTheScopeIsConfigurableByPathAndContentType(): void
    {
        $sightings = $this->sightings('memory', 4096, null, null, [
            'content_types' => ['application/json'],
            'include_path_prefixes' => ['/docs/'],
            'exclude_path_prefixes' => ['/admin', '/robots.txt'],
        ]);
        self::assertTrue($sightings->inScope('/api', 'application/json; charset=utf-8'));
        self::assertFalse($sightings->inScope('/', 'text/html'), 'content_types replaces the default');
        self::assertTrue($sightings->inScope('/docs/a.pdf', 'application/pdf'));
        self::assertFalse($sightings->inScope('/admin/page', 'application/json'));
        self::assertFalse($sightings->inScope('/robots.txt', 'text/plain'), 'exclusion wins');
        self::assertTrue($sightings->inScope('/llms.txt', 'text/plain'));

        foreach ([
            ['content_types' => 'text/html'],
            ['content_types' => ['html']],
            ['include_path_prefixes' => ['docs']],
            ['exclude_path_prefixes' => [1]],
        ] as $bad) {
            try {
                $this->sightings('memory', 4096, null, null, $bad);
                self::fail('must be refused');
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('stores')]
    public function testOutOfScopeResponsesAreNotReported(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $sightings->report(self::request('a', '198.51.100.1', '/api/orders'), 'application/json');
        $sightings->report(self::request('b', '198.51.100.1', '/robots.txt'), 'text/plain');
        $sightings->flush();
        self::assertSame(['b'], array_column($this->sentItems(0), 'user_agent'));
    }

    #[DataProvider('stores')]
    public function testCloseFlushesAndStopsReporting(string $store): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings($store);
        $request = self::request('a', '198.51.100.1');
        $sightings->report($request, 'text/html');
        $sightings->close();
        self::assertSame(1, $this->server()->requestCount());
        $sightings->report(self::request('b', '198.51.100.1'), 'text/html');
        $sightings->close();
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
        self::assertFalse($sightings->lookup($request)->isUnknown(), 'lookup still answers after close');
    }

    public function testWithoutApcuOrLongRunningNothingIsReported(): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        $make = fn (): AgentSightings => new AgentSightings([
            'secret_key' => TestServer::SECRET_KEY,
            'client_ip' => static fn (ProxyRequest $request): ?string => $request->remoteAddress,
            'endpoint' => $this->server()->baseUrl(),
            'allow_insecure_loopback' => true,
            'on_error' => function (string $line): void {
                $this->logs[] = $line;
            },
        ]);
        $sightings = $make();
        $make();
        if (Apcu::available()) {
            self::assertTrue($sightings->isEnabled(), 'APCu is loaded, so it is used');

            return;
        }

        self::assertFalse($sightings->isEnabled());
        self::assertCount(1, $this->logsMatching('APCu is not available'), 'logged once per process');
        $request = self::request('a', '198.51.100.1');
        $sightings->report($request, 'text/html');
        $sightings->flush();
        $sightings->flushIfDue();
        $sightings->close();
        self::assertSame(AgentSighting::unknown(), $sightings->lookup($request));
        self::assertSame(0, $sightings->droppedCount());
        self::assertSame(0, $this->server()->requestCount());
    }

    public function testAnApcuStoreIsBoundedInKeys(): void
    {
        $apcu = new FakeApcu();
        $this->serve([$this->answer('r1', array_fill(0, 200, [3600, VisitorKind::HUMAN]))]);
        $sightings = $this->sightings('fake-apcu', 16, new ApcuStore($apcu, 16, 'n'));
        for ($round = 0; $round < 3; ++$round) {
            for ($i = 0; $i < 200; ++$i) {
                $sightings->report(self::request('ua '.$round.' '.$i, '198.51.100.1'), 'text/html');
            }
            $sightings->flush();
        }
        self::assertSame(16, $apcu->countPrefix('fingerly:sightings:n:c:'));
        self::assertSame(16, $apcu->countPrefix('fingerly:sightings:n:cs:'));
        self::assertSame(0, $apcu->countPrefix('fingerly:sightings:n:i:'));
        self::assertSame(0, $apcu->countPrefix('fingerly:sightings:n:q:'));
        self::assertNull($apcu->fetch('fingerly:sightings:n:lock'));
    }

    public function testALostApcuQueueEntryNeverStopsReporting(): void
    {
        // Three reports wait, then their entries go: APCu expires them after
        // a day without a flush, or the worker numbering one died before
        // writing it. Reporting must resume once the gap is old enough.
        $apcu = new FakeApcu();
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN], [60, VisitorKind::HUMAN]])]);
        $sightings = $this->sightings('fake-apcu', 4096, new ApcuStore($apcu, 4096, 'n'));
        foreach (['a', 'b', 'c'] as $userAgent) {
            $sightings->report(self::request($userAgent, '198.51.100.1'), 'text/html');
        }
        $apcu->deletePrefix('fingerly:sightings:n:q:');
        $apcu->deletePrefix('fingerly:sightings:n:i:');
        $apcu->deletePrefix('fingerly:sightings:n:n:');
        $sightings->report(self::request('d', '198.51.100.1'), 'text/html');
        $sightings->report(self::request('e', '198.51.100.1'), 'text/html');

        $sightings->flush();
        self::assertSame(0, $this->server()->requestCount(), 'a fresh gap may still be written');

        $this->now += 5;
        $sightings->flush();
        self::assertSame(1, $this->server()->requestCount());
        self::assertSame(['d', 'e'], array_column($this->sentItems(0), 'user_agent'));
        self::assertSame(['items' => 0, 'bytes' => 0, 'oldest' => null], $this->lastStore?->pending(), 'the lost entries no longer count');
    }

    public function testAnApcuNamespaceNamesTheSdkVersion(): void
    {
        if (!Apcu::available()) {
            self::markTestSkipped('needs APCu');
        }
        $sightings = new AgentSightings([
            'secret_key' => TestServer::SECRET_KEY,
            'client_ip' => static fn (ProxyRequest $request): ?string => $request->remoteAddress,
        ]);
        $store = (new \ReflectionProperty(AgentSightings::class, 'store'))->getValue($sightings);
        self::assertInstanceOf(ApcuStore::class, $store);
        $prefix = (new \ReflectionProperty(ApcuStore::class, 'prefix'))->getValue($store);
        $namespace = substr(hash('sha256', Client::VERSION."\0".'https://api.fingerly.io/v1'."\0".TestServer::SECRET_KEY), 0, 16);
        self::assertSame('fingerly:sightings:'.$namespace.':', $prefix, 'objects stored by another SDK version are never read back');
    }

    #[DataProvider('stores')]
    public function testADueFlushSendsOneBatch(string $store): void
    {
        $this->serve(array_fill(0, 3, $this->answer('r1', array_fill(0, 200, [60, VisitorKind::HUMAN]))));
        $sightings = $this->sightings($store);
        $sightings->deferUsing(null);
        for ($i = 0; $i < 600; ++$i) {
            $sightings->report(self::request('ua '.$i, '198.51.100.1'), 'text/html');
        }
        $sightings->flushIfDue();
        self::assertSame(1, $this->server()->requestCount(), 'the worker is handed back after one call');
        $sightings->flushIfDue();
        self::assertSame(2, $this->server()->requestCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'memory' => ['memory'];

        yield 'apcu over a fake' => ['fake-apcu'];
        if (Apcu::available()) {
            yield 'apcu' => ['apcu'];
        }
    }

    public function testAnExplicitFlushStartsNoBatchOnceItsBudgetIsSpent(): void
    {
        $this->serve(array_fill(0, 5, $this->answer('r1', array_fill(0, 200, [60, VisitorKind::HUMAN]))));
        $sightings = $this->sightings('memory');
        $sightings->deferUsing(null);
        for ($i = 0; $i < 1000; ++$i) {
            $sightings->report(self::request('ua '.$i, '198.51.100.1'), 'text/html');
        }
        // Each call takes 4 seconds of the clock: a slow API, just inside
        // the deadline.
        $calls = 0;
        $sightings->useClocks(function () use (&$calls): float {
            $sent = $this->server()->requestCount();
            if ($sent > $calls) {
                $this->now += 4 * ($sent - $calls);
                $calls = $sent;
            }

            return $this->now;
        });
        $sightings->flush();
        self::assertSame(3, $this->server()->requestCount(), 'none started after 10 seconds');
        self::assertSame(400, $this->lastStore?->pending()['items']);
    }

    public function testTheSendAfterTheResponseRunsAfterEveryOtherShutdownFunction(): void
    {
        $this->serve([['status' => 500, 'body' => '{}']]);
        $output = self::runScript(<<<'PHP'
            $sightings = new Fingerly\AgentSightings([
                'secret_key' => 'fly_sk_production_testonlysecret',
                'client_ip' => static fn (Fingerly\Proxy\ProxyRequest $r): ?string => $r->remoteAddress,
                'endpoint' => $argv[1],
                'allow_insecure_loopback' => true,
                'on_error' => static function (string $line): void { echo "sent\n"; },
            ], new Fingerly\AgentSightings\MemoryStore(16));
            register_shutdown_function(static function (): void { echo "application, registered first\n"; });
            for ($i = 0; $i < 200; ++$i) {
                $sightings->report(new Fingerly\Proxy\ProxyRequest('GET', '/', ['user-agent' => 'ua '.$i], '', '192.0.2.1'), 'text/html');
            }
            register_shutdown_function(static function (): void { echo "application, registered later\n"; });
            PHP, $this->server()->baseUrl());
        self::assertSame("application, registered first\napplication, registered later\nsent\n", $output);
    }

    public function testALongRunningInstanceIsClosedWhenTheProcessExits(): void
    {
        $this->serve([$this->answer('r1', [[60, VisitorKind::HUMAN]])]);
        self::runScript(<<<'PHP'
            $sightings = new Fingerly\AgentSightings([
                'secret_key' => 'fly_sk_production_testonlysecret',
                'client_ip' => static fn (Fingerly\Proxy\ProxyRequest $r): ?string => $r->remoteAddress,
                'endpoint' => $argv[1],
                'allow_insecure_loopback' => true,
                'long_running' => true,
            ]);
            $sightings->report(new Fingerly\Proxy\ProxyRequest('GET', '/', ['user-agent' => 'last'], '', '192.0.2.1'), 'text/html');
            // A worker stopping gracefully: its loop ends and the script returns.
            PHP, $this->server()->baseUrl());
        self::assertSame(1, $this->server()->requestCount());
        self::assertSame('last', $this->sentItems(0)[0]['user_agent']);
    }

    public function testRequiresASecretKeyAndAClientIpCallback(): void
    {
        foreach ([
            ['client_ip' => static fn (): string => '192.0.2.1'],
            ['secret_key' => TestServer::PUBLIC_KEY, 'client_ip' => static fn (): string => '192.0.2.1'],
            ['secret_key' => TestServer::PROXY_KEY, 'client_ip' => static fn (): string => '192.0.2.1'],
            ['secret_key' => TestServer::SECRET_KEY],
            ['secret_key' => TestServer::SECRET_KEY, 'client_ip' => static fn (): string => '192.0.2.1', 'long_runnig' => true],
        ] as $options) {
            try {
                new AgentSightings($options);
                self::fail('must be refused');
            } catch (ConfigurationException $e) {
                self::assertStringNotContainsString('testonly', $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $userAgent, string $ip, string $path = '/', array $headers = []): ProxyRequest
    {
        return new ProxyRequest('GET', $path, $headers + ['user-agent' => $userAgent, 'host' => 'example.com'], '', $ip);
    }

    private function store(string $kind, int $capacity = 4096): Store
    {
        return match ($kind) {
            'memory' => new MemoryStore($capacity),
            'fake-apcu' => new ApcuStore(new FakeApcu(), $capacity, 'test'),
            default => new ApcuStore(new Apcu(), $capacity, 'test'.bin2hex(random_bytes(6))),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sightings(string $kind, int $capacity = 4096, ?Store $store = null, ?string $endpoint = null, array $options = []): AgentSightings
    {
        $store ??= $this->store($kind, $capacity);
        $this->lastStore = $store;
        $sightings = new AgentSightings($options + [
            'secret_key' => TestServer::SECRET_KEY,
            'client_ip' => static fn (ProxyRequest $request): ?string => $request->remoteAddress,
            'endpoint' => $endpoint ?? $this->server?->baseUrl() ?? 'http://127.0.0.1:'.self::closedPort(),
            'allow_insecure_loopback' => true,
            'on_error' => function (string $line): void {
                $this->logs[] = $line;
            },
        ], $store);
        $sightings->useClocks(fn (): float => $this->now, fn (): int => $this->wall);
        $sightings->deferUsing(function (\Closure $flush): void {
            $this->deferred[] = $flush;
        });

        return $sightings;
    }

    private function runDeferred(): void
    {
        $deferred = $this->deferred;
        $this->deferred = [];
        foreach ($deferred as $flush) {
            $flush();
        }
    }

    /**
     * @param list<array{0: int, 1: string, 2?: string}> $rows
     *
     * @return array{status: int, body: string}
     */
    private function answer(string $rulesVersion, array $rows): array
    {
        return ['status' => 200, 'body' => (string) json_encode([
            'rules_version' => $rulesVersion,
            'items' => array_map(static fn (array $row): array => ['ttl' => $row[0], 'visitor_kind' => $row[1]] + (isset($row[2]) ? ['agent' => $row[2]] : []), $rows),
        ])];
    }

    /**
     * @param list<array{status?: int, body?: string, headers?: array<string, string>, delay?: float}> $responses
     */
    private function serve(array $responses): void
    {
        $this->server?->stop();
        $this->server = TestServer::start($responses);
    }

    private function server(): TestServer
    {
        self::assertNotNull($this->server);

        return $this->server;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentItems(int $request): array
    {
        $requests = $this->server()->requests();
        self::assertArrayHasKey($request, $requests, 'request '.$request.' never arrived');
        $body = json_decode($requests[$request]['body'], true, 16, \JSON_THROW_ON_ERROR);

        return $body['items'];
    }

    /**
     * @return list<string>
     */
    private function logsMatching(string $needle): array
    {
        return array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, $needle)));
    }

    /**
     * A server that reads one whole request and then fails it: `close` closes
     * with no answer, `reset` resets the connection, `short` answers a body
     * shorter than its Content-Length and closes.
     */
    private function startRawServer(string $mode): string
    {
        $port = self::closedPort();
        $code = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $errno, $error);
            $mode = $argv[2];
            while ($client = @stream_socket_accept($server, 30)) {
                $request = '';
                while (!str_contains($request, "\r\n\r\n") && false !== ($chunk = fread($client, 8192)) && '' !== $chunk) {
                    $request .= $chunk;
                }
                if (!str_contains($request, "\r\n\r\n")) {
                    // The readiness probe, which sends nothing.
                    fclose($client);
                    continue;
                }
                if (preg_match('/content-length:\s*(\d+)/i', $request, $m)) {
                    $body = substr($request, strpos($request, "\r\n\r\n") + 4);
                    while (strlen($body) < (int) $m[1] && false !== ($chunk = fread($client, 8192)) && '' !== $chunk) {
                        $body .= $chunk;
                    }
                }
                if ('short' === $mode) {
                    fwrite($client, "HTTP/1.1 200 OK\r\ncontent-type: application/json\r\ncontent-length: 100\r\n\r\n{\"rules_version\"");
                } elseif ('reset' === $mode) {
                    socket_set_option(socket_import_stream($client), SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                }
                fclose($client);
            }
            PHP;
        $process = proc_open([\PHP_BINARY, '-r', $code, (string) $port, $mode], [], $pipes);
        self::assertIsResource($process);
        $this->rawServer = $process;

        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($probe)) {
                fclose($probe);

                return 'http://127.0.0.1:'.$port;
            }
            usleep(25_000);
        }
        self::fail('the raw server never started listening');
    }

    /**
     * Runs PHP code in its own CLI process with the SDK autoloaded, and
     * answers what it printed.
     */
    private static function runScript(string $code, string ...$args): string
    {
        $script = '<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).";\n".$code;
        $file = (string) tempnam(sys_get_temp_dir(), 'fingerly-script');
        file_put_contents($file, $script);

        try {
            $process = proc_open([\PHP_BINARY, '-d', 'apc.enable_cli=0', $file, ...array_values($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);

            return $output;
        } finally {
            unlink($file);
        }
    }

    private static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (!is_resource($socket)) {
            throw new \RuntimeException('could not reserve a port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) explode(':', $name)[1];
    }
}
