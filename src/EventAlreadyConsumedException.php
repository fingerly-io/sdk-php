<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Thrown when an event was already consumed (`409 event_already_consumed`):
 * an earlier call used it to back a decision, so it must not back another.
 *
 * It is an {@see ApiException}, so the status, code, details, trace id and
 * Retry-After are all there, and {@see ApiException::isAlreadyConsumed()} is
 * true.
 *
 * ```php
 * try {
 *     $fingerly->events->consume($requestId);
 * } catch (\Fingerly\EventAlreadyConsumedException $e) {
 *     // Already used, at $e->getConsumedAt().
 * }
 * ```
 */
final class EventAlreadyConsumedException extends ApiException
{
    /**
     * @param null|string $consumedAt when the event was first consumed, RFC 3339 as the API sent it
     * @param mixed       $details    additional context from the error envelope
     * @param null|string $requestId  server-side trace id to quote in support requests
     * @param null|int    $retryAfter seconds the API asked the caller to wait, from Retry-After
     *
     * @internal
     */
    public function __construct(
        private readonly ?string $consumedAt = null,
        mixed $details = null,
        ?string $requestId = null,
        ?int $retryAfter = null,
    ) {
        parent::__construct(409, 'event_already_consumed', $details, $requestId, $retryAfter);
    }

    /**
     * When the event was first consumed, as the API sent it (RFC 3339), or
     * null when the answer did not say.
     */
    public function getConsumedAt(): ?string
    {
        return $this->consumedAt;
    }
}
