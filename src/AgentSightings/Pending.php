<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

/**
 * One buffered report: the first sighting of a key and how many times it was
 * seen since.
 *
 * @internal
 */
final class Pending
{
    /** The most one item may count; the API refuses a larger count. */
    public const MAX_COUNT = 1000000;

    /**
     * @param string $id        the buffer slot: the cache key, or a unique id for a signed request
     * @param float  $firstSeen when the first sighting was buffered, on the monotonic clock
     */
    public function __construct(
        public readonly string $id,
        public readonly Sighting $sighting,
        public int $count,
        public readonly float $firstSeen,
    ) {}

    /** Adds to the count, stopping at the API's limit. */
    public function add(int $count): void
    {
        $this->count = min(self::MAX_COUNT, $this->count + $count);
    }
}
