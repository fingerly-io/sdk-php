<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\AgentSighting;

/**
 * The cache and buffer in APCu, shared by every PHP-FPM worker of the pool,
 * bounded by the same counts as in memory.
 *
 * Every operation is a handful of single-key APCu calls, each atomic, so a
 * request never waits on another; only the flusher holds the lock, and only
 * the flusher removes from the buffer or writes the cache.
 *
 * The cache is a ring of `capacity` slots, each naming the entry stored in it
 * and the sequence number it was stored under. Storing evicts the entry the
 * slot named, unless that entry was stored again since (its sequence moved
 * on), so there are never more than `capacity` entries. A hit on an entry
 * that is more than half a ring old stores it again, which keeps entries in
 * use from being evicted first: the in-memory store's LRU, approximated.
 *
 * The buffer is a queue of sequence numbers, each naming an item; an item's
 * count is its own integer so concurrent requests fold into it with one
 * `apcu_inc`.
 *
 * @internal
 */
final class ApcuStore implements Store
{
    private const ITEM_TTL = 86400;
    private const LOCK_TTL = 30;

    private readonly string $prefix;

    /**
     * @param string $namespace separates the cache of one key from another's on a shared host
     */
    public function __construct(
        private readonly Apcu $apcu,
        private readonly int $capacity,
        string $namespace,
    ) {
        $this->prefix = 'fingerly:sightings:'.$namespace.':';
    }

    public function lookup(string $key, float $now): ?AgentSighting
    {
        $entry = $this->apcu->fetch($this->prefix.'c:'.$key);
        if (!is_array($entry) || !($entry[0] ?? null) instanceof AgentSighting || !is_float($entry[1] ?? null) || !is_int($entry[2] ?? null)) {
            return null;
        }
        if ($entry[1] <= $now) {
            return null;
        }
        $seq = $this->apcu->fetch($this->prefix.'cseq');
        if (is_int($seq) && $seq - $entry[2] > intdiv($this->capacity, 2)) {
            $this->put($key, $entry[0], $entry[1], $now);
        }

        return $entry[0];
    }

    public function remember(string $rulesVersion, array $answers, float $now): void
    {
        $current = $this->apcu->fetch($this->prefix.'rv');
        if (is_string($current) && $current !== $rulesVersion) {
            $this->apcu->deletePrefix($this->prefix.'c:');
            $this->apcu->deletePrefix($this->prefix.'cs:');
        }
        $this->apcu->store($this->prefix.'rv', $rulesVersion);
        foreach ($answers as [$key, $answer]) {
            $this->put($key, $answer, $now + $answer->ttl, $now);
        }
    }

    public function add(Sighting $sighting, float $now): bool
    {
        $id = $sighting->signed ? 's'.bin2hex(random_bytes(8)) : $sighting->key;
        $itemKey = $this->prefix.'i:'.$id;
        if (!$this->apcu->add($itemKey, [$sighting, $now, 0], self::ITEM_TTL)) {
            // Seen before and still pending: fold into it.
            $this->apcu->inc($this->prefix.'n:'.$id, 1, self::ITEM_TTL);

            return true;
        }
        if ($this->apcu->inc($this->prefix.'pn') > self::MAX_PENDING) {
            $this->apcu->inc($this->prefix.'pn', -1);
            $this->apcu->delete($itemKey);
            $this->apcu->inc($this->prefix.'drops');

            return false;
        }
        $seq = $this->apcu->inc($this->prefix.'pseq');
        $this->apcu->store($itemKey, [$sighting, $now, $seq], self::ITEM_TTL);
        $this->apcu->inc($this->prefix.'n:'.$id, 1, self::ITEM_TTL);
        $this->apcu->inc($this->prefix.'pb', $sighting->size);
        $this->apcu->store($this->prefix.'q:'.$seq, $id, self::ITEM_TTL);
        $this->apcu->add($this->prefix.'poldest', $now);

        return true;
    }

    public function pending(): array
    {
        $items = $this->apcu->fetch($this->prefix.'pn');
        $bytes = $this->apcu->fetch($this->prefix.'pb');
        $oldest = $this->apcu->fetch($this->prefix.'poldest');

        return [
            'items' => is_int($items) ? max(0, $items) : 0,
            'bytes' => is_int($bytes) ? max(0, $bytes) : 0,
            'oldest' => is_float($oldest) ? $oldest : null,
        ];
    }

    public function take(int $maxItems, int $maxBytes): array
    {
        $head = $this->apcu->fetch($this->prefix.'phead');
        $head = is_int($head) ? $head : 1;
        $tail = $this->apcu->fetch($this->prefix.'pseq');
        $tail = is_int($tail) ? $tail : 0;

        $batch = [];
        $bytes = 0;
        $seq = $head;
        for (; $seq <= $tail && count($batch) < $maxItems; ++$seq) {
            $id = $this->apcu->fetch($this->prefix.'q:'.$seq);
            if (!is_string($id)) {
                // Numbered but not written yet by the request adding it: stop
                // here and read it next time rather than lose it.
                break;
            }
            $item = $this->item($id);
            if (null === $item || $item[2] !== $seq) {
                // Taken or moved since; nothing to send for this number.
                $this->apcu->delete($this->prefix.'q:'.$seq);

                continue;
            }
            if ([] !== $batch && $bytes + $item[0]->size > $maxBytes) {
                break;
            }
            $this->apcu->delete($this->prefix.'q:'.$seq);
            $this->apcu->delete($this->prefix.'i:'.$id);
            $count = $this->claim($this->prefix.'n:'.$id);
            $this->apcu->inc($this->prefix.'pn', -1);
            $this->apcu->inc($this->prefix.'pb', -$item[0]->size);
            if ($count > 0) {
                $batch[] = new Pending($id, $item[0], min(Pending::MAX_COUNT, $count), $item[1]);
                $bytes += $item[0]->size;
            }
        }
        $this->apcu->store($this->prefix.'phead', $seq);
        $this->resetOldest($seq, $tail);

        return $batch;
    }

    public function putBack(array $batch): void
    {
        $head = $this->apcu->fetch($this->prefix.'phead');
        $head = is_int($head) ? $head : 1;
        $oldest = $this->apcu->fetch($this->prefix.'poldest');
        $oldest = is_float($oldest) ? $oldest : \INF;
        foreach (array_reverse($batch) as $item) {
            $itemKey = $this->prefix.'i:'.$item->id;
            if (!$this->apcu->add($itemKey, [$item->sighting, $item->firstSeen, 0], self::ITEM_TTL)) {
                // A newer item for the key exists: count into it.
                $this->apcu->inc($this->prefix.'n:'.$item->id, $item->count, self::ITEM_TTL);

                continue;
            }
            if ($this->apcu->inc($this->prefix.'pn') > self::MAX_PENDING) {
                $this->apcu->inc($this->prefix.'pn', -1);
                $this->apcu->delete($itemKey);
                $this->apcu->inc($this->prefix.'drops');

                continue;
            }
            --$head;
            $this->apcu->store($itemKey, [$item->sighting, $item->firstSeen, $head], self::ITEM_TTL);
            $this->apcu->inc($this->prefix.'n:'.$item->id, $item->count, self::ITEM_TTL);
            $this->apcu->inc($this->prefix.'pb', $item->sighting->size);
            $this->apcu->store($this->prefix.'q:'.$head, $item->id, self::ITEM_TTL);
            $oldest = min($oldest, $item->firstSeen);
        }
        $this->apcu->store($this->prefix.'phead', $head);
        if (\INF !== $oldest) {
            $this->apcu->store($this->prefix.'poldest', $oldest);
        }
    }

    public function lock(): bool
    {
        return $this->apcu->add($this->prefix.'lock', 1, self::LOCK_TTL);
    }

    public function unlock(): void
    {
        $this->apcu->delete($this->prefix.'lock');
    }

    public function state(): array
    {
        $state = $this->apcu->fetch($this->prefix.'state');
        $default = ['resume_at' => 0.0, 'backoff' => 0.0, 'auth_logged' => false, 'invalid_logged_at' => -\INF];
        if (!is_array($state)) {
            return $default;
        }

        return [
            'resume_at' => is_float($state['resume_at'] ?? null) ? $state['resume_at'] : 0.0,
            'backoff' => is_float($state['backoff'] ?? null) ? $state['backoff'] : 0.0,
            'auth_logged' => true === ($state['auth_logged'] ?? false),
            'invalid_logged_at' => is_float($state['invalid_logged_at'] ?? null) ? $state['invalid_logged_at'] : -\INF,
        ];
    }

    public function saveState(array $state): void
    {
        $this->apcu->store($this->prefix.'state', $state);
    }

    public function drops(): int
    {
        $drops = $this->apcu->fetch($this->prefix.'drops');

        return is_int($drops) ? $drops : 0;
    }

    /**
     * Stores an entry in the next ring slot, evicting the entry the slot held
     * unless that one was stored again since.
     */
    private function put(string $key, AgentSighting $answer, float $expires, float $now): void
    {
        $seq = $this->apcu->inc($this->prefix.'cseq');
        $slotKey = $this->prefix.'cs:'.($seq % $this->capacity);
        $previous = $this->apcu->fetch($slotKey);
        if (is_array($previous) && is_string($previous[0] ?? null) && $previous[0] !== $key) {
            $held = $this->apcu->fetch($this->prefix.'c:'.$previous[0]);
            if (is_array($held) && ($held[2] ?? null) === ($previous[1] ?? null)) {
                $this->apcu->delete($this->prefix.'c:'.$previous[0]);
            }
        }
        $this->apcu->store($slotKey, [$key, $seq]);
        // APCu's own TTL is a backstop that frees the memory; expiry is
        // decided on the monotonic clock.
        $this->apcu->store($this->prefix.'c:'.$key, [$answer, $expires, $seq], max(1, (int) ceil($expires - $now)) + 1);
    }

    /**
     * @return null|array{Sighting, float, int}
     */
    private function item(string $id): ?array
    {
        $item = $this->apcu->fetch($this->prefix.'i:'.$id);
        if (!is_array($item) || !($item[0] ?? null) instanceof Sighting || !is_float($item[1] ?? null) || !is_int($item[2] ?? null)) {
            return null;
        }

        return [$item[0], $item[1], $item[2]];
    }

    /** Reads an integer and sets it to 0 in one step, so no increment is lost. */
    private function claim(string $key): int
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $value = $this->apcu->fetch($key);
            if (!is_int($value)) {
                return 0;
            }
            // Left at 0 rather than deleted: an increment landing between a
            // delete and its read would be lost.
            if (0 === $value || $this->apcu->cas($key, $value, 0)) {
                return $value;
            }
        }

        return 0;
    }

    /** Points `poldest` at the first item still queued, or clears it. */
    private function resetOldest(int $from, int $tail): void
    {
        for ($seq = $from; $seq <= $tail; ++$seq) {
            $id = $this->apcu->fetch($this->prefix.'q:'.$seq);
            $item = is_string($id) ? $this->item($id) : null;
            if (null !== $item && $item[2] === $seq) {
                $this->apcu->store($this->prefix.'poldest', $item[1]);

                return;
            }
        }
        $this->apcu->delete($this->prefix.'poldest');
    }
}
