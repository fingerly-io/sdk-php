<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The bits Apple accepted on a DeviceCheck write. A bit the write left out is
 * null: Apple kept its value and does not say what it is.
 */
final class DeviceCheckWrite
{
    /**
     * @param null|bool            $bit0 the value set for the first bit, or null when it was left out
     * @param null|bool            $bit1 the value set for the second bit, or null when it was left out
     * @param array<string, mixed> $raw  the response body exactly as it arrived
     */
    public function __construct(
        public readonly ?bool $bit0 = null,
        public readonly ?bool $bit1 = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        return new self(
            bit0: is_bool($body['bit0'] ?? null) ? $body['bit0'] : null,
            bit1: is_bool($body['bit1'] ?? null) ? $body['bit1'] : null,
            raw: $body,
        );
    }
}
