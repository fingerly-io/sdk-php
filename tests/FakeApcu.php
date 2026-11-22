<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Fingerly\AgentSightings\Apcu;
use Fingerly\AgentSightings\ApcuStore;

/**
 * APCu's semantics over a PHP array, so {@see ApcuStore}
 * is tested on machines without the extension. Values are serialised as APCu
 * does, so an object read back is a copy, never the instance stored.
 */
final class FakeApcu extends Apcu
{
    /** @var array<string, string> */
    public array $values = [];

    public function fetch(string $key): mixed
    {
        return isset($this->values[$key]) ? unserialize($this->values[$key]) : null;
    }

    public function store(string $key, mixed $value, int $ttl = 0): void
    {
        $this->values[$key] = serialize($value);
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if (isset($this->values[$key])) {
            return false;
        }
        $this->values[$key] = serialize($value);

        return true;
    }

    public function inc(string $key, int $step = 1, int $ttl = 0): int
    {
        $value = $this->fetch($key);
        $value = (is_int($value) ? $value : 0) + $step;
        $this->values[$key] = serialize($value);

        return $value;
    }

    public function cas(string $key, int $old, int $new): bool
    {
        if ($this->fetch($key) !== $old) {
            return false;
        }
        $this->values[$key] = serialize($new);

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    public function deletePrefix(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }

    /** How many keys start with $prefix. */
    public function countPrefix(string $prefix): int
    {
        return count(array_filter(array_keys($this->values), static fn (string $key): bool => str_starts_with($key, $prefix)));
    }
}
