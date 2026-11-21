<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\AgentSighting;

/**
 * Where the answer cache and the pending buffer live: this process's memory
 * for a long-running server, APCu for PHP-FPM.
 *
 * Times are seconds on the monotonic clock, which is the same for every
 * process on the host.
 *
 * @internal
 */
interface Store
{
    /** The most pending items held. */
    public const MAX_PENDING = 1000;

    /** The remembered answer for a key, when present and unexpired. */
    public function lookup(string $key, float $now): ?AgentSighting;

    /**
     * Remembers answers, first dropping every one remembered under another
     * rules version.
     *
     * @param list<array{string, AgentSighting}> $answers key and answer, each with a ttl over 0
     */
    public function remember(string $rulesVersion, array $answers, float $now): void;

    /**
     * Buffers one sighting: folded into the pending item for its key, or a
     * new item (always, for a signed request). False, and counted as a drop,
     * when pending and in-flight items together already reach the cap.
     */
    public function add(Sighting $sighting, float $now): bool;

    /**
     * The pending items, not counting the batch in flight.
     *
     * @return array{items: int, bytes: int, oldest: null|float}
     */
    public function pending(): array;

    /**
     * Moves the oldest items in flight: at most $maxItems, stopping before
     * the next would pass $maxBytes, and always at least one.
     *
     * @return list<Pending>
     */
    public function take(int $maxItems, int $maxBytes): array;

    /**
     * Returns the batch in flight to the front of the buffer as its oldest
     * items, each keeping its first-seen time and taking in the count of a
     * newer item for its key. It always fits: the cap counted it in flight.
     *
     * @param list<Pending> $batch
     */
    public function putBack(array $batch): void;

    /**
     * Forgets the batch in flight once the API answered it or it was
     * dropped.
     *
     * @param list<Pending> $batch
     */
    public function settle(array $batch): void;

    /**
     * Claims the right to flush; false when another flush holds it.
     *
     * @phpstan-impure
     */
    public function lock(): bool;

    public function unlock(): void;

    /**
     * The flusher's state: when sending may resume, the current backoff, and
     * what has been logged.
     *
     * @return array{resume_at: float, backoff: float, auth_logged_at: float, invalid_logged_at: float}
     */
    public function state(): array;

    /**
     * @param array{resume_at: float, backoff: float, auth_logged_at: float, invalid_logged_at: float} $state
     */
    public function saveState(array $state): void;

    /** How many sightings were not buffered because the buffer was full. */
    public function drops(): int;
}
