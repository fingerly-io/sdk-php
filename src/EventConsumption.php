<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The answer to consuming an event for the first time.
 */
final class EventConsumption
{
    /**
     * @param string               $requestId  the event that was consumed
     * @param string               $consumedAt when it was consumed, RFC 3339 as the API sent it
     * @param bool                 $first      true when this call was the one that consumed it
     * @param array<string, mixed> $raw        the response body exactly as it arrived
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $consumedAt,
        public readonly bool $first,
        public readonly array $raw = [],
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException if the body is not a consumption this SDK can read
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        if (!is_bool($body['first'] ?? null)) {
            throw new ApiException(200, 'invalid_response', ['field' => 'first']);
        }

        return new self(
            requestId: Json::string($body, 'request_id'),
            consumedAt: Json::string($body, 'consumed_at'),
            first: $body['first'],
            raw: $body,
        );
    }
}
