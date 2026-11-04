<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Holds a credential so that no dump, export or serialisation can show it.
 *
 * The value is not a property of the object at all: it lives in a private
 * static map keyed by the object, so `var_dump()`, `print_r()`,
 * `var_export()`, `json_encode()`, Symfony's `dump()` and Laravel's `dd()`
 * all see an object with nothing in it but the `[redacted]` placeholder.
 * Serialising one is refused, because a serialised credential is a
 * credential written to wherever the payload ends up.
 *
 * @internal
 */
final class Secret implements \Stringable
{
    public const REDACTED = '[redacted]';

    /** @var null|\WeakMap<Secret, string> */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::values()[$this] = $value;
    }

    /** Never the value, so string interpolation into a log line is safe. */
    public function __toString(): string
    {
        return self::REDACTED;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Fingerly credential cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A Fingerly credential cannot be unserialized');
    }

    /** The credential itself, for the one place that sends it. */
    public function reveal(): string
    {
        return self::values()[$this] ?? '';
    }

    /** @return \WeakMap<Secret, string> */
    private static function values(): \WeakMap
    {
        return self::$values ??= new \WeakMap();
    }
}
