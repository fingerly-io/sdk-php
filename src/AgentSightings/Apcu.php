<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

/**
 * The few APCu operations {@see ApcuStore} uses, in one place.
 *
 * APCu is shared by every worker of a PHP-FPM pool, and each of these is
 * atomic on its own; nothing here holds a lock across calls.
 *
 * @internal
 */
class Apcu
{
    /** Whether APCu is loaded and enabled for this SAPI (`apc.enable_cli` for the CLI). */
    public static function available(): bool
    {
        return \function_exists('apcu_enabled') && apcu_enabled();
    }

    /** The value, or null when there is none. */
    public function fetch(string $key): mixed
    {
        $success = false;
        $value = apcu_fetch($key, $success);

        return $success ? $value : null;
    }

    public function store(string $key, mixed $value, int $ttl = 0): void
    {
        apcu_store($key, $value, $ttl);
    }

    /** Stores only when the key is absent; false when it was present. */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        return true === apcu_add($key, $value, $ttl);
    }

    /**
     * Adds to an integer, starting from 0 when absent, and answers the new
     * value. $ttl applies only when the integer is created.
     */
    public function inc(string $key, int $step = 1, int $ttl = 0): int
    {
        apcu_add($key, 0, $ttl);
        $value = apcu_inc($key, $step);

        return false === $value ? 0 : $value;
    }

    /** Replaces an integer only when it still holds $old. */
    public function cas(string $key, int $old, int $new): bool
    {
        return apcu_cas($key, $old, $new);
    }

    public function delete(string $key): void
    {
        apcu_delete($key);
    }

    /** Deletes every key that starts with $prefix. */
    public function deletePrefix(string $prefix): void
    {
        apcu_delete(new \APCUIterator('/^'.preg_quote($prefix, '/').'/', \APC_ITER_KEY));
    }
}
