<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Thrown for any non-2xx response from the Fingerly API.
 *
 * The message carries only the HTTP status: response bodies are never echoed,
 * and any occurrence of the secret key in the metadata is redacted.
 *
 * ```php
 * try {
 *     $event = $fingerly->events->get($requestId);
 * } catch (\Fingerly\ApiException $e) {
 *     if ($e->isEventNotFound()) {
 *         // Not archived yet.
 *     }
 * }
 * ```
 */
final class ApiException extends \RuntimeException implements FingerlyException
{
    /**
     * @param int         $status     the HTTP status code
     * @param null|string $errorCode  stable machine-readable code, such as `event_not_found`
     * @param mixed       $details    additional context; its shape depends on the code
     * @param null|string $requestId  server-side trace id to quote in support requests
     * @param null|int    $retryAfter seconds the API asked the caller to wait, from Retry-After
     */
    public function __construct(
        private readonly int $status,
        private readonly ?string $errorCode = null,
        private readonly mixed $details = null,
        private readonly ?string $requestId = null,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct(
            null === $errorCode
                ? sprintf('Fingerly API returned HTTP %d', $status)
                : sprintf('Fingerly API returned HTTP %d (%s)', $status, $errorCode),
            // Throwable::getCode() is the HTTP status too, so a generic
            // handler that only logs the code still logs something useful.
            $status,
        );
    }

    /** The HTTP status code. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * The stable machine-readable code, such as `event_not_found`.
     *
     * It is not `getCode()`, which `Throwable` already defines as the integer
     * status.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Additional context; its shape depends on {@see self::getErrorCode()}. */
    public function getDetails(): mixed
    {
        return $this->details;
    }

    /** The server-side trace id to quote in support requests. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * How many seconds the API asked you to wait before trying again, from
     * its `Retry-After` header, or null when it sent none.
     *
     * The SDK never retries a read itself; this is what a caller's own retry
     * or queue should honour after a `429`.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * True when the API is asking the caller to slow down.
     */
    public function isRateLimited(): bool
    {
        return 429 === $this->status;
    }

    /**
     * True when there is no such event.
     *
     * One is readable as soon as identification answers, so this means a
     * request id that was never issued rather than one that has not landed.
     */
    public function isEventNotFound(): bool
    {
        return 404 === $this->status && 'event_not_found' === $this->errorCode;
    }
}
