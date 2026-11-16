<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * What Apple holds for the device behind an event: its two DeviceCheck bits.
 *
 * The bits mean what you decided they mean; Fingerly never reads them.
 */
final class DeviceCheckBits
{
    /**
     * @param bool                 $found      false when Apple holds no bits for the device yet; both bits
     *                                         are then false
     * @param bool                 $bit0       the first bit
     * @param bool                 $bit1       the second bit
     * @param null|string          $lastUpdate when the bits last changed, as `YYYY-MM`, or null when Apple
     *                                         holds no bits
     * @param array<string, mixed> $raw        the response body exactly as it arrived
     */
    public function __construct(
        public readonly bool $found,
        public readonly bool $bit0,
        public readonly bool $bit1,
        public readonly ?string $lastUpdate = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException if a required field is missing or not a boolean
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        foreach (['found', 'bit0', 'bit1'] as $field) {
            if (!is_bool($body[$field] ?? null)) {
                throw new ApiException(200, 'invalid_response', ['field' => $field]);
            }
        }

        return new self(
            found: (bool) $body['found'],
            bit0: (bool) $body['bit0'],
            bit1: (bool) $body['bit1'],
            lastUpdate: Json::nullableString($body, 'last_update'),
            raw: $body,
        );
    }
}
