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
     * new item (always, for a signed request). False when the buffer is full
     * and it was not buffered.
     */
    public function add(Sighting $sighting, float $now): bool;

    /**
     * @return array{items: int, bytes: int, oldest: null|float}
     */
    public function pending(): array;

    /**
     * Removes and returns the oldest items: at most $maxItems, and no more
     * than $maxBytes estimated unless one item alone is larger.
     *
     * @return list<Pending>
     */
    public function take(int $maxItems, int $maxBytes): array;

    /**
     * Returns a batch the API did not take to the front of the buffer, folding
     * each into a newer item for its key. Items that no longer fit are
     * dropped and counted.
     *
     * @param list<Pending> $batch
     */
    public function putBack(array $batch): void;

    /** Claims the right to flush; false when another flush holds it. */
    public function lock(): bool;

    public function unlock(): void;

    /**
     * The flusher's state: when sending may resume, the current backoff, and
     * what has been logged.
     *
     * @return array{resume_at: float, backoff: float, auth_logged: bool, invalid_logged_at: float}
     */
    public function state(): array;

    /**
     * @param array{resume_at: float, backoff: float, auth_logged: bool, invalid_logged_at: float} $state
     */
    public function saveState(array $state): void;

    /** How many sightings were not buffered because the buffer was full. */
    public function drops(): int;
}
