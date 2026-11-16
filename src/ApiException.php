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
 *     if ($e->isPending()) {
 *         // Not recorded yet: read again after $e->getRetryAfter() seconds.
 *     } elseif ($e->isEventNotFound()) {
 *         // Never issued, or past retention.
 *     }
 * }
 * ```
 *
 * {@see EventAlreadyConsumedException} extends it for an event that was
 * already consumed.
 */
class ApiException extends \RuntimeException implements FingerlyException
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
     * or queue should honour after a `429` or a pending event.
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
     * True when there is no such event: a request id that was never issued,
     * or one past retention.
     *
     * It is false for {@see self::isPending()}: that event exists and is
     * still being recorded.
     */
    public function isEventNotFound(): bool
    {
        return 404 === $this->status && 'event_not_found' === $this->errorCode;
    }

    /**
     * True when the API accepted the request id but has not recorded its
     * event yet (`404 event_pending`).
     *
     * Nothing is decided yet: read again after {@see self::getRetryAfter()}
     * seconds, or verify asynchronously. The SDK never retries by itself.
     */
    public function isPending(): bool
    {
        return 404 === $this->status && 'event_pending' === $this->errorCode;
    }

    /**
     * True when the event was already consumed (`409 event_already_consumed`):
     * another call already used it to back a decision. Such an error is an
     * {@see EventAlreadyConsumedException}, which says when.
     */
    public function isAlreadyConsumed(): bool
    {
        return 409 === $this->status && 'event_already_consumed' === $this->errorCode;
    }

    /**
     * True when no DeviceCheck token is held for the event
     * (`404 device_check_not_held`): not an iOS event with App Attest,
     * DeviceCheck is off, or the hour has passed. Decide without the bits.
     */
    public function isDeviceCheckNotHeld(): bool
    {
        return 404 === $this->status && 'device_check_not_held' === $this->errorCode;
    }

    /**
     * True when the event's DeviceCheck token was already used, or the call
     * was a retry that is not the first request made with it
     * (`409 device_check_spent`). Use another identification.
     */
    public function isDeviceCheckSpent(): bool
    {
        return 409 === $this->status && 'device_check_spent' === $this->errorCode;
    }

    /** True when another call is using the event's token (`409 device_check_busy`). */
    public function isDeviceCheckBusy(): bool
    {
        return 409 === $this->status && 'device_check_busy' === $this->errorCode;
    }

    /**
     * True when the project's Apple key is missing, refused by Apple, or has
     * DeviceCheck off (`409 credential_unavailable`). The token is kept.
     */
    public function isCredentialUnavailable(): bool
    {
        return 409 === $this->status && 'credential_unavailable' === $this->errorCode;
    }

    /** True when a DeviceCheck write named neither bit (`422 bits_required`). */
    public function isBitsRequired(): bool
    {
        return 422 === $this->status && 'bits_required' === $this->errorCode;
    }

    /**
     * True when Apple rejected the event's token (`422 apple_rejected`). The
     * token is used up; {@see self::getAppleStatus()} says how Apple answered.
     */
    public function isAppleRejected(): bool
    {
        return 422 === $this->status && 'apple_rejected' === $this->errorCode;
    }

    /**
     * True when Apple or Fingerly's storage could not answer
     * (`503 device_check_unavailable`). The token is kept for a retry of the
     * same request within the hour.
     */
    public function isDeviceCheckUnavailable(): bool
    {
        return 503 === $this->status && 'device_check_unavailable' === $this->errorCode;
    }

    /**
     * Apple's HTTP status for an `apple_rejected` answer, from the error
     * detail whose `field` is `apple_status`, or null for any other error.
     */
    public function getAppleStatus(): ?int
    {
        if (!$this->isAppleRejected() || !is_array($this->details)) {
            return null;
        }
        foreach ($this->details as $detail) {
            if (is_array($detail) && 'apple_status' === ($detail['field'] ?? null)) {
                $issue = $detail['issue'] ?? null;
                if (is_int($issue)) {
                    return $issue;
                }
                if (is_string($issue) && 1 === preg_match('/^\d{3}$/', $issue)) {
                    return (int) $issue;
                }
            }
        }

        return null;
    }
}
