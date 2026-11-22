<?php

declare(strict_types=1);

namespace Fingerly;

use Fingerly\AgentSightings\Apcu;
use Fingerly\AgentSightings\ApcuStore;
use Fingerly\AgentSightings\MemoryStore;
use Fingerly\AgentSightings\Pending;
use Fingerly\AgentSightings\Sighting;
use Fingerly\AgentSightings\Store;
use Fingerly\Proxy\ProxyRequest;

/**
 * Reports the requests your server served to Fingerly, so AI crawlers and
 * fetchers that never run a page's JavaScript are seen, and answers from what
 * Fingerly said about each one.
 *
 * Mount one of the adapters ({@see AgentSightings\Psr15Middleware},
 * {@see AgentSightings\LaravelMiddleware}, {@see AgentSightings\SymfonyListener})
 * and ask {@see self::lookup()} when a decision needs it:
 *
 * ```php
 * $answer = $sightings->lookup($request);
 * if (VisitorKind::AI_BOT === $answer->visitorKind) {
 *     // Fingerly said an AI agent sent this request.
 * }
 * ```
 *
 * The SDK decides nothing about the visitor: it sends the raw `User-Agent`,
 * the client address, `Host`, the method and the Web Bot Auth headers, and
 * remembers the answers. What it does decide is which of your own responses
 * are worth reporting: HTML pages and `/robots.txt`, `/llms.txt` and
 * `/llms-full.txt` by default.
 *
 * Reporting never delays a response and never throws. Under PHP-FPM the cache
 * and the buffer live in APCu, shared by the pool's workers, and a request
 * that crosses a flush threshold sends the batch after its response is
 * finished. On a long-running server (Octane, RoadRunner, Swoole) pass
 * `long_running` and both live in the process. Without APCu and without
 * `long_running`, nothing is reported, every lookup answers
 * {@see AgentSighting::unknown()}, and that is logged once.
 */
final class AgentSightings
{
    /** Answers remembered by default. */
    public const DEFAULT_CAPACITY = 4096;

    /** The most answers `capacity` may ask for. */
    public const MAX_CAPACITY = 65536;

    /** The most reports buffered; past it, a new request is not reported and is counted. */
    public const MAX_PENDING = Store::MAX_PENDING;

    /** The response content types reported by default. */
    public const DEFAULT_CONTENT_TYPES = ['text/html', 'application/xhtml+xml'];

    /** Paths reported whatever their content type and status, unless excluded. */
    public const WELL_KNOWN_PATHS = ['/robots.txt', '/llms.txt', '/llms-full.txt'];

    /** A flush sends at most this many items, and runs once this many are pending. */
    private const FLUSH_ITEMS = Agents::MAX_ITEMS;

    /** A flush runs once the pending items reach this many bytes, estimated. */
    private const FLUSH_BYTES = 65536;

    /** A flush runs this many seconds after its oldest pending item. */
    private const FLUSH_AFTER = 2.0;

    /** The deadline for one flush, in seconds. */
    private const DEADLINE = 5.0;

    /** How long a wrong or revoked key stops flushing, in seconds. */
    private const AUTH_PAUSE = 60.0;

    /** The backoff after a server or transport failure, doubling up to the cap. */
    private const BACKOFF_FIRST = 1.0;
    private const BACKOFF_MAX = 60.0;

    /** How long a 429 without a usable Retry-After stops flushing, in seconds. */
    private const RATE_LIMIT_PAUSE = 60.0;

    /** The shortest a 429 stops flushing, whatever its Retry-After, in seconds. */
    private const MIN_RATE_LIMIT_PAUSE = 1.0;

    /**
     * The longest a 429 stops flushing, whatever its Retry-After, in seconds:
     * a day, the longest the daily allowance can last. A Retry-After of years
     * would otherwise stop reporting for the life of the process, and under
     * PHP-FPM for every worker of the pool.
     */
    private const MAX_RATE_LIMIT_PAUSE = 86400.0;

    /** How long an explicit flush waits for one already in flight, in seconds. */
    private const WAIT_FOR_FLIGHT = self::DEADLINE + 1.0;

    /**
     * An explicit flush starts no batch after this many seconds, so with the
     * deadline it ends well before the 30 second APCu lock could expire
     * under it, and a process exiting is never held for long.
     */
    private const FLUSH_BUDGET = 10.0;

    /** How often a throttled line may be logged, in seconds. */
    private const LOG_EVERY = 60.0;

    private const OPTIONS = [
        'secret_key',
        'client_ip',
        'endpoint',
        'allow_insecure_loopback',
        'api_version',
        'capacity',
        'content_types',
        'include_path_prefixes',
        'exclude_path_prefixes',
        'long_running',
        'on_error',
    ];

    /** @var array<string, true> what this process has logged once */
    private static array $loggedOnce = [];

    /** @var array<string, float> when this process last logged each throttled line */
    private static array $loggedAt = [];

    /** @var null|\WeakMap<self, true> the long-running instances closed when the process exits */
    private static ?\WeakMap $closeOnExit = null;

    private readonly Agents $agents;
    private readonly ?Store $store;
    private readonly bool $longRunning;

    /** @var list<string> */
    private readonly array $contentTypes;

    /** @var list<string> */
    private readonly array $includePrefixes;

    /** @var list<string> */
    private readonly array $excludePrefixes;

    /** @var callable(ProxyRequest): (null|string) */
    private $clientIp;

    /** @var null|callable(string): void */
    private $onError;

    /** @var \Closure(): float seconds on the monotonic clock */
    private \Closure $now;

    /** @var \Closure(): int seconds since the epoch */
    private \Closure $wallClock;

    /** @var null|\Closure(\Closure(): void): void runs a flush once the response is finished */
    private ?\Closure $defer;

    private bool $deferred = false;
    private bool $closed = false;
    private float $flightWait = self::WAIT_FOR_FLIGHT;

    /**
     * @param array<string, mixed> $options `secret_key` (a `fly_sk_` key) and `client_ip` (a
     *                                      `callable(ProxyRequest): ?string` answering the visitor's
     *                                      address from a source you trust, exactly as for
     *                                      {@see Proxy}) are required. The rest: `capacity` (answers
     *                                      remembered, 4096 by default; above 65536 is held to 65536),
     *                                      `content_types` (the response media types reported,
     *                                      replacing the default `text/html` and
     *                                      `application/xhtml+xml`), `include_path_prefixes` (paths
     *                                      reported whatever their content type),
     *                                      `exclude_path_prefixes` (paths never reported; they win),
     *                                      `long_running` (true on Octane, RoadRunner, Swoole or any
     *                                      server that keeps the process between requests),
     *                                      `on_error` (a `callable(string): void` given each log
     *                                      line instead of `error_log`), `endpoint`,
     *                                      `allow_insecure_loopback` and `api_version` as for
     *                                      {@see Client}. Like the other option arrays, it is
     *                                      deliberately not a shape, and an unknown option is refused.
     * @param null|Store           $store   @internal for tests
     *
     * @throws ConfigurationException if an option is invalid; nothing is sent until then
     */
    public function __construct(#[\SensitiveParameter] array $options, ?Store $store = null)
    {
        Config::rejectUnknownKeys(array_keys($options), self::OPTIONS);

        $secretKey = $options['secret_key'] ?? null;
        if (!is_string($secretKey)) {
            throw new ConfigurationException('A valid Fingerly secret key is required');
        }
        Config::parseKey($secretKey, Config::KEY_SECRET);
        $clientIp = $options['client_ip'] ?? null;
        if (!is_callable($clientIp)) {
            throw new ConfigurationException('client_ip is required and must be callable');
        }
        $endpoint = $options['endpoint'] ?? null;
        if (null !== $endpoint && !is_string($endpoint)) {
            throw new ConfigurationException('endpoint must be a string');
        }
        $allowInsecureLoopback = $options['allow_insecure_loopback'] ?? false;
        if (!is_bool($allowInsecureLoopback)) {
            throw new ConfigurationException('allow_insecure_loopback must be a boolean');
        }
        $origin = Config::bareOrigin($endpoint ?? Config::DEFAULT_ENDPOINT, $allowInsecureLoopback, 'endpoint');
        $apiVersion = $options['api_version'] ?? null;
        $apiVersion = Config::normalizeApiVersion(is_string($apiVersion) ? $apiVersion : null);
        // Above the hard maximum is held to it rather than refused.
        $capacity = min(self::MAX_CAPACITY, Config::positiveInt($options['capacity'] ?? self::DEFAULT_CAPACITY, 'capacity'));
        $longRunning = $options['long_running'] ?? false;
        if (!is_bool($longRunning)) {
            throw new ConfigurationException('long_running must be a boolean');
        }
        $onError = $options['on_error'] ?? null;
        if (null !== $onError && !is_callable($onError)) {
            throw new ConfigurationException('on_error must be callable');
        }
        $this->contentTypes = self::contentTypes($options['content_types'] ?? self::DEFAULT_CONTENT_TYPES);
        $this->includePrefixes = self::prefixes($options['include_path_prefixes'] ?? [], 'include_path_prefixes');
        $this->excludePrefixes = self::prefixes($options['exclude_path_prefixes'] ?? [], 'exclude_path_prefixes');
        $this->clientIp = $clientIp;
        $this->onError = $onError;
        $this->longRunning = $longRunning;

        $secret = new Secret($secretKey);
        // Always the bundled ext-curl transport, whose deadline covers the
        // whole exchange; a supplied PSR-18 client can express none.
        $this->agents = new Agents(new Transport(timeout: self::DEADLINE, redactKey: $secret), $origin.'/api/'.$apiVersion, $secret);
        $this->now = static fn (): float => hrtime(true) / 1e9;
        $this->wallClock = static fn (): int => time();
        $this->defer = self::defaultDefer();

        if (null !== $store) {
            $this->store = $store;
        } elseif ($longRunning) {
            $this->store = new MemoryStore($capacity);
        } elseif (Apcu::available()) {
            // One namespace per key and API, so two applications on one pool
            // never read each other's answers, and per SDK version, so a
            // deploy that keeps APCu never reads objects stored by another
            // version's classes.
            $this->store = new ApcuStore(new Apcu(), $capacity, substr(hash('sha256', Client::VERSION."\0".$origin.'/'.$apiVersion."\0".$secretKey), 0, 16));
        } else {
            $this->store = null;
            $this->logOnce('no-store', 'APCu is not available and long_running is off, so no request is reported and every lookup answers unknown');
        }
        if ($longRunning) {
            $this->closeOnExit();
        }
    }

    /**
     * What Fingerly said sent this request, from memory only: it never waits
     * on the network and never throws.
     *
     * The answer is {@see AgentSighting::unknown()} (its `visitorKind` is
     * `''`) until a report of the same user agent,
     * address and Signature-Agent has been answered, after the answer's
     * `ttl`, for a signed request, and whenever reporting is off. Serve such
     * a request as usual.
     */
    public function lookup(ProxyRequest $request): AgentSighting
    {
        try {
            if (null === $this->store) {
                return AgentSighting::unknown();
            }
            $sighting = $this->sighting($request);
            if (null === $sighting) {
                return AgentSighting::unknown();
            }

            return $this->store->lookup($sighting->key, ($this->now)()) ?? AgentSighting::unknown();
        } catch (\Throwable) {
            return AgentSighting::unknown();
        }
    }

    /**
     * Whether a response is one this instance reports: never under an
     * excluded prefix; always for `/robots.txt`, `/llms.txt`, `/llms-full.txt`
     * and under an included prefix; otherwise by its media type.
     *
     * @param string $path        the application-relative request path, without the query string
     * @param string $contentType the response's `Content-Type`, `''` when it has none
     */
    public function inScope(string $path, string $contentType): bool
    {
        foreach ($this->excludePrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        if (in_array($path, self::WELL_KNOWN_PATHS, true)) {
            return true;
        }
        foreach ($this->includePrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return in_array(strtolower(trim(explode(';', $contentType, 2)[0])), $this->contentTypes, true);
    }

    /**
     * Buffers one served request when its response is in scope, after the
     * response exists. In memory only: it never sends, never waits and never
     * throws. The adapters call it; a framework without one calls it once
     * per response.
     *
     * @param string $contentType the response's `Content-Type`, `''` when it has none
     */
    public function report(ProxyRequest $request, string $contentType): void
    {
        try {
            if ($this->closed || null === $this->store || !$this->inScope($request->path, $contentType)) {
                return;
            }
            $sighting = $this->sighting($request);
            if (null === $sighting) {
                return;
            }
            $now = ($this->now)();
            $this->store->add($sighting, $now);
            if ($this->due($now)) {
                $this->schedule();
            }
        } catch (\Throwable) {
            // Reporting must never change the response.
        }
    }

    /**
     * Sends the batch when one is due: 200 items or 64 KiB pending, or the
     * oldest pending for 2 seconds, and sending not paused. Call it after the
     * response is written on a long-running server whose framework has no
     * adapter here (RoadRunner after `respond()`, for one). The Laravel and
     * Symfony adapters do so from their terminate hooks when `long_running`
     * is on. It sends one batch at most, so a slow API holds the worker for
     * one deadline at most.
     */
    public function flushIfDue(): void
    {
        $this->run(false);
    }

    /**
     * Sends everything pending now, batch after batch, until the buffer is
     * empty, a refusal pauses sending, or a batch is dropped. It first waits
     * for a flush already in flight. For a script, a queue worker or a
     * serverless invocation that ends before a flush would come due. It never
     * throws.
     */
    public function flush(): void
    {
        $this->run(true);
    }

    /**
     * What the Laravel and Symfony adapters call once the response was sent.
     * On a long-running server it sends a due batch, as
     * {@see self::flushIfDue()} does. Anywhere else it does nothing: the
     * report already arranged its send for after the response is finished,
     * and sending here would hold the response open where the server cannot
     * finish it first (the built-in server, Apache's module).
     *
     * @internal
     */
    public function responseSent(): void
    {
        if ($this->longRunning) {
            $this->flushIfDue();
        }
    }

    /**
     * Flushes once more and stops reporting; lookups keep answering from
     * what is remembered. A `long_running` instance is closed this way when
     * the process exits normally, so a worker that stops gracefully sends
     * what it still holds.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->flush();
        $this->closed = true;
    }

    /**
     * How many requests were not reported because the buffer was full (shared
     * by the pool under PHP-FPM).
     */
    public function droppedCount(): int
    {
        try {
            return $this->store?->drops() ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Whether requests are reported at all: false without APCu and without `long_running`. */
    public function isEnabled(): bool
    {
        return null !== $this->store;
    }

    /**
     * Replaces the clocks, for tests.
     *
     * @param \Closure(): float    $now       seconds on a monotonic clock
     * @param null|\Closure(): int $wallClock seconds since the epoch
     *
     * @internal
     */
    public function useClocks(\Closure $now, ?\Closure $wallClock = null): void
    {
        $this->now = $now;
        if (null !== $wallClock) {
            $this->wallClock = $wallClock;
        }
    }

    /**
     * Replaces how a flush is run after the response, for tests.
     *
     * @param null|\Closure(\Closure(): void): void $defer
     *
     * @internal
     */
    public function deferUsing(?\Closure $defer): void
    {
        $this->defer = $defer;
    }

    /**
     * Forgets what this process logged once, for tests.
     *
     * @internal
     */
    public static function resetLogs(): void
    {
        self::$loggedOnce = [];
        self::$loggedAt = [];
    }

    /**
     * Replaces how long an explicit flush waits for one in flight, for tests.
     *
     * @internal
     */
    public function useFlightWait(float $seconds): void
    {
        $this->flightWait = $seconds;
    }

    /**
     * After the response: PHP-FPM and LiteSpeed finish the response first,
     * and the CLI has no client waiting. Any other SAPI would hold the
     * response open, so there is none.
     *
     * @return null|\Closure(\Closure(): void): void
     */
    private static function defaultDefer(): ?\Closure
    {
        $finish = \function_exists('fastcgi_finish_request') || \function_exists('litespeed_finish_request');
        if (!$finish && !in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return null;
        }

        return static function (\Closure $flush): void {
            // Registered again from inside the first shutdown function, so it
            // runs after every shutdown function the application registers,
            // even one registered later: finishing the response earlier would
            // cut off whatever those still print.
            register_shutdown_function(static function () use ($flush): void {
                register_shutdown_function(static function () use ($flush): void {
                    if (\function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    } elseif (\function_exists('litespeed_finish_request')) {
                        litespeed_finish_request();
                    }
                    // PHP writes an open session, and releases its lock, only
                    // once the script ends; held across the send, it would
                    // keep the visitor's next request waiting for it.
                    if (\function_exists('session_status') && \PHP_SESSION_ACTIVE === session_status()) {
                        session_write_close();
                    }
                    $flush();
                });
            });
        };
    }

    /**
     * Closes this instance when the process exits normally, which is how a
     * long-running worker stops gracefully. Held weakly, so an instance the
     * application drops is never kept alive by this.
     */
    private function closeOnExit(): void
    {
        if (null === self::$closeOnExit) {
            self::$closeOnExit = new \WeakMap();
            register_shutdown_function(static function (): void {
                foreach (self::$closeOnExit ?? [] as $sightings => $_) {
                    $sightings->close();
                }
            });
        }
        self::$closeOnExit[$this] = true;
    }

    private function schedule(): void
    {
        if ($this->longRunning || $this->deferred) {
            // A long-running server flushes from the adapter's terminate hook
            // or the caller's flushIfDue(), never on the request.
            return;
        }
        if (null === $this->defer) {
            $this->logOnce('no-defer', sprintf('the %s SAPI cannot finish a response before the script ends, so reports are sent only by flush()', \PHP_SAPI));

            return;
        }
        $this->deferred = true;
        ($this->defer)(function (): void {
            $this->deferred = false;
            $this->flushIfDue();
        });
    }

    private function due(float $now): bool
    {
        if (null === $this->store || $this->store->state()['resume_at'] > $now) {
            return false;
        }
        $pending = $this->store->pending();

        return $pending['items'] >= self::FLUSH_ITEMS
            || $pending['bytes'] >= self::FLUSH_BYTES
            || (null !== $pending['oldest'] && $now - $pending['oldest'] >= self::FLUSH_AFTER);
    }

    private function run(bool $everything): void
    {
        $store = $this->store;
        if (null === $store) {
            return;
        }

        try {
            if (!$this->acquire($store, $everything)) {
                // Another flush is in flight.
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $items = [];

        try {
            // A due flush sends one batch: it runs after a response, on a
            // worker the server is waiting to have back, and the next due
            // response sends the next. An explicit flush sends batch after
            // batch, but starts none once its budget is spent, so the lock
            // is never held past its expiry while requests keep adding.
            $started = ($this->now)();
            for ($batch = 0; $everything ? $batch <= Store::MAX_PENDING : 0 === $batch; ++$batch) {
                $now = ($this->now)();
                if ($store->state()['resume_at'] > $now || $now - $started >= self::FLUSH_BUDGET) {
                    return;
                }
                if ($everything ? 0 === $store->pending()['items'] : !$this->due($now)) {
                    return;
                }
                $items = $store->take(self::FLUSH_ITEMS, self::FLUSH_BYTES, $now);
                if ([] === $items) {
                    $store->settle([]);

                    return;
                }
                $sent = $this->send($store, $items);
                $items = [];
                if (!$sent) {
                    return;
                }
            }
        } catch (\Throwable) {
            // A flush must never surface; the batch it held is lost.
            try {
                $store->settle($items);
            } catch (\Throwable) {
                // Deliberately ignored.
            }
        } finally {
            try {
                $store->unlock();
            } catch (\Throwable) {
                // Deliberately ignored: the lock expires on its own.
            }
        }
    }

    /**
     * Claims the flush. An explicit flush waits for one already in flight
     * (another worker's, under PHP-FPM) for up to a deadline and a second;
     * a due flush does not wait.
     */
    private function acquire(Store $store, bool $wait): bool
    {
        if ($store->lock()) {
            return true;
        }
        if (!$wait) {
            return false;
        }
        $until = microtime(true) + $this->flightWait;
        while (microtime(true) < $until) {
            usleep(20_000);
            if ($store->lock()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends one batch and applies the contract's answer table. True when it
     * was answered and the next batch may follow.
     *
     * @param list<Pending> $items
     */
    private function send(Store $store, array $items): bool
    {
        try {
            $answer = $this->agents->reportSightings(array_map(
                static fn (Pending $item): AgentSightingReport => $item->sighting->report($item->count),
                $items,
            ));
        } catch (ApiException $e) {
            $status = $e->getStatus();
            if (429 === $status) {
                $store->putBack($items);
                $this->pauseFor($store, $this->rateLimitPause($e));
            } elseif (503 === $status) {
                $store->putBack($items);
                $this->backOff($store);
            } elseif (401 === $status || 403 === $status) {
                $store->settle($items);
                $this->pauseFor($store, self::AUTH_PAUSE);
                $this->logThrottled($store, 'auth_logged_at', sprintf('the API refused the secret key (%d): it is wrong or revoked; reports are dropped and sending pauses for 60 seconds', $status));
            } else {
                // 422, every other 4xx and 5xx, and a 200 this SDK cannot read.
                $store->settle($items);
                $this->logThrottled($store, 'invalid_logged_at', sprintf('the API did not take a report (%d %s); the batch was dropped', $status, $e->getErrorCode() ?? 'error'));
            }

            return false;
        } catch (TimeoutException $e) {
            if ($e->requestSent) {
                // The API may have counted it; sending it again would count
                // twice. Sending still backs off: an API that does not answer
                // would otherwise hold a worker for the whole deadline every
                // time a send comes due.
                $store->settle($items);
                $this->logThrottled($store, 'invalid_logged_at', 'a report timed out after it was sent; the batch was dropped');
            } else {
                $store->putBack($items);
            }
            $this->backOff($store);

            return false;
        } catch (ConnectionException $e) {
            if ('too_large' === $e->kind) {
                // The API answered, with more than any answer could be.
                $store->settle($items);
                $this->logThrottled($store, 'invalid_logged_at', 'the API answered a report with an oversized body; the batch was dropped');
            } elseif ($e->requestWritten) {
                // Closed, reset or cut short once the report was written: the
                // API may have counted it, as after a timeout, and sending
                // backs off as after one.
                $store->settle($items);
                $this->logThrottled($store, 'invalid_logged_at', 'the connection failed after a report was sent; the batch was dropped');
                $this->backOff($store);
            } else {
                $store->putBack($items);
                $this->backOff($store);
            }

            return false;
        }

        $store->settle($items);
        if (count($answer->items) !== count($items)) {
            $this->logThrottled($store, 'invalid_logged_at', 'the API answered a different number of items than were sent; the batch was dropped');

            return false;
        }
        $remember = [];
        foreach ($answer->items as $index => $item) {
            if ($item->ttl > 0) {
                $remember[] = [$items[$index]->sighting->key, $item];
            }
        }
        $now = ($this->now)();
        $store->remember($answer->rulesVersion, $remember, $now);
        $state = $store->state();
        $state['backoff'] = 0.0;
        $store->saveState($state);

        return true;
    }

    /**
     * Seconds a 429 pauses sending: Retry-After, held between a second and a
     * day, else the next UTC day for the daily allowance, else a minute. An
     * unreadable Retry-After counts as none.
     */
    private function rateLimitPause(ApiException $e): float
    {
        $retryAfter = $e->getRetryAfter();
        if (null !== $retryAfter) {
            return min(self::MAX_RATE_LIMIT_PAUSE, max(self::MIN_RATE_LIMIT_PAUSE, (float) $retryAfter));
        }
        if (ApiException::RATE_LIMIT_SCOPE_DAILY === $e->getRateLimitScope()) {
            return (float) (86400 - (($this->wallClock)() % 86400));
        }

        return self::RATE_LIMIT_PAUSE;
    }

    private function pauseFor(Store $store, float $seconds): void
    {
        $state = $store->state();
        $state['resume_at'] = ($this->now)() + $seconds;
        $store->saveState($state);
    }

    private function backOff(Store $store): void
    {
        $state = $store->state();
        $state['backoff'] = $state['backoff'] > 0 ? min(self::BACKOFF_MAX, $state['backoff'] * 2) : self::BACKOFF_FIRST;
        $state['resume_at'] = ($this->now)() + $state['backoff'];
        $store->saveState($state);
    }

    /** The request as the contract reports it, or null when it cannot be. */
    private function sighting(ProxyRequest $request): ?Sighting
    {
        $clientIp = null;

        try {
            $value = ($this->clientIp)($request);
            $clientIp = is_string($value) ? $value : null;
        } catch (\Throwable) {
            // No address, as when the callback answers null.
        }
        if (null === $clientIp || null === Sighting::canonicalIp($clientIp)) {
            $this->logEveryMinute('client-ip', 'the client_ip callback returned no valid IP address; the request was not reported');

            return null;
        }

        return Sighting::of(
            userAgent: $request->header('user-agent'),
            clientIp: $clientIp,
            host: $request->header('host'),
            method: $request->method,
            signature: $request->header('signature'),
            signatureInput: $request->header('signature-input'),
            signatureAgent: $request->header('signature-agent'),
        );
    }

    /**
     * Logs a flush outcome at most once a minute, timed in the store so the
     * workers of a PHP-FPM pool share it.
     *
     * @param 'auth_logged_at'|'invalid_logged_at' $field
     */
    private function logThrottled(Store $store, string $field, string $line): void
    {
        $state = $store->state();
        $now = ($this->now)();
        if ($now - $state[$field] < self::LOG_EVERY) {
            return;
        }
        $state[$field] = $now;
        $store->saveState($state);
        $this->log($line);
    }

    /** Logs a line at most once a minute per process. */
    private function logEveryMinute(string $what, string $line): void
    {
        $now = ($this->now)();
        if ($now - (self::$loggedAt[$what] ?? -\INF) < self::LOG_EVERY) {
            return;
        }
        self::$loggedAt[$what] = $now;
        $this->log($line);
    }

    private function logOnce(string $what, string $line): void
    {
        if (isset(self::$loggedOnce[$what])) {
            return;
        }
        self::$loggedOnce[$what] = true;
        $this->log($line);
    }

    /** One line to `on_error`, or `error_log` without one. Never throws. */
    private function log(string $line): void
    {
        $line = 'fingerly agent sightings: '.$line;

        try {
            null === $this->onError ? error_log($line) : ($this->onError)($line);
        } catch (\Throwable) {
            // Deliberately ignored.
        }
    }

    /**
     * @return list<string>
     */
    private static function contentTypes(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ConfigurationException('content_types must be a list of media types');
        }
        $types = [];
        foreach ($value as $type) {
            if (!is_string($type) || 1 !== preg_match('#\A[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+\z#', $type)) {
                throw new ConfigurationException('content_types must contain only media types such as text/html');
            }
            $types[] = strtolower($type);
        }

        return array_values(array_unique($types));
    }

    /**
     * @return list<string>
     */
    private static function prefixes(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf('%s must be a list of paths', $name));
        }
        $prefixes = [];
        foreach ($value as $prefix) {
            if (!is_string($prefix) || !str_starts_with($prefix, '/')) {
                throw new ConfigurationException(sprintf('%s must contain only paths starting with /', $name));
            }
            $prefixes[] = $prefix;
        }

        return $prefixes;
    }
}
