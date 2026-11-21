<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\AgentSighting;

/**
 * The cache and buffer in this process's memory, for a long-running server
 * (Octane, RoadRunner, Swoole, a worker loop).
 *
 * A PHP array keeps insertion order, so the cache is an LRU by moving a key to
 * the end on every hit and evicting from the front, and the buffer is
 * oldest-first by construction.
 *
 * @internal
 */
final class MemoryStore implements Store
{
    /** @var array<string, array{AgentSighting, float}> answer and expiry, least recently used first */
    private array $cache = [];

    private ?string $rulesVersion = null;

    /** @var array<string, Pending> oldest first */
    private array $pending = [];

    private int $bytes = 0;
    private int $inFlight = 0;
    private int $drops = 0;
    private int $signedSeq = 0;
    private bool $locked = false;

    /** @var array{resume_at: float, backoff: float, auth_logged_at: float, invalid_logged_at: float} */
    private array $state = ['resume_at' => 0.0, 'backoff' => 0.0, 'auth_logged_at' => -\INF, 'invalid_logged_at' => -\INF];

    public function __construct(private readonly int $capacity) {}

    public function lookup(string $key, float $now): ?AgentSighting
    {
        $entry = $this->cache[$key] ?? null;
        if (null === $entry) {
            return null;
        }
        unset($this->cache[$key]);
        if ($entry[1] <= $now) {
            return null;
        }
        $this->cache[$key] = $entry;

        return $entry[0];
    }

    public function remember(string $rulesVersion, array $answers, float $now): void
    {
        if (null !== $this->rulesVersion && $rulesVersion !== $this->rulesVersion) {
            $this->cache = [];
        }
        $this->rulesVersion = $rulesVersion;
        foreach ($answers as [$key, $answer]) {
            unset($this->cache[$key]);
            $this->cache[$key] = [$answer, $now + min($answer->ttl, self::MAX_TTL)];
        }
        while (count($this->cache) > $this->capacity) {
            unset($this->cache[array_key_first($this->cache)]);
        }
    }

    public function add(Sighting $sighting, float $now): bool
    {
        if (!$sighting->signed && isset($this->pending[$sighting->key])) {
            $this->pending[$sighting->key]->add(1);

            return true;
        }
        if (count($this->pending) + $this->inFlight >= self::MAX_PENDING) {
            ++$this->drops;

            return false;
        }
        $id = $sighting->signed ? 's'.++$this->signedSeq : $sighting->key;
        $this->pending[$id] = new Pending($id, $sighting, 1, $now);
        $this->bytes += $sighting->size;

        return true;
    }

    public function pending(): array
    {
        $first = array_key_first($this->pending);

        return [
            'items' => count($this->pending),
            'bytes' => $this->bytes,
            'oldest' => null === $first ? null : $this->pending[$first]->firstSeen,
        ];
    }

    public function take(int $maxItems, int $maxBytes): array
    {
        $batch = [];
        $bytes = 0;
        foreach ($this->pending as $id => $item) {
            if (count($batch) >= $maxItems || ([] !== $batch && $bytes + $item->sighting->size > $maxBytes)) {
                break;
            }
            $batch[] = $item;
            $bytes += $item->sighting->size;
            unset($this->pending[$id]);
        }
        $this->bytes -= $bytes;
        $this->inFlight += count($batch);

        return $batch;
    }

    public function putBack(array $batch): void
    {
        $front = [];
        foreach ($batch as $item) {
            $newer = $this->pending[$item->id] ?? null;
            if (null !== $newer) {
                // The same key was seen again while the batch was out: one
                // item, at the older position, counting both.
                unset($this->pending[$item->id]);
                $this->bytes -= $newer->sighting->size;
                $item->add($newer->count);
            }
            $front[$item->id] = $item;
            $this->bytes += $item->sighting->size;
        }
        $this->pending = $front + $this->pending;
        $this->inFlight = max(0, $this->inFlight - count($batch));
    }

    public function settle(array $batch): void
    {
        $this->inFlight = max(0, $this->inFlight - count($batch));
    }

    public function lock(): bool
    {
        if ($this->locked) {
            return false;
        }

        return $this->locked = true;
    }

    public function unlock(): void
    {
        $this->locked = false;
    }

    public function state(): array
    {
        return $this->state;
    }

    public function saveState(array $state): void
    {
        $this->state = $state;
    }

    public function drops(): int
    {
        return $this->drops;
    }
}
