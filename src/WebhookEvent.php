<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The envelope every webhook delivery shares.
 *
 * `$data` is left as a decoded array rather than a typed payload, so a new
 * event type needs no SDK upgrade.
 */
final class WebhookEvent
{
    /**
     * @param string               $id             identifies this delivery; persist it to deduplicate, because a
     *                                             webhook may arrive more than once
     * @param string               $type           the event name, such as `identification.completed`
     * @param int                  $version        the envelope version
     * @param string               $organizationId the organisation the event belongs to
     * @param string               $environment    the deployment the event came from
     * @param \DateTimeImmutable   $createdAt      when Fingerly recorded the event
     * @param array<string, mixed> $data           the event-specific payload
     * @param array<string, mixed> $raw            the delivery exactly as it was decoded
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $version,
        public readonly string $organizationId,
        public readonly string $environment,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $data,
        public readonly array $raw = [],
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException if the body is not a delivery this SDK can read
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        return new self(
            id: Json::string($body, 'id'),
            type: Json::string($body, 'type'),
            version: (int) ($body['version'] ?? 0),
            organizationId: Json::nullableString($body, 'organization_id') ?? '',
            environment: Json::nullableString($body, 'environment') ?? '',
            createdAt: Json::timestamp($body, 'created_at'),
            data: Json::nullableArray($body, 'data') ?? [],
            raw: $body,
        );
    }
}
