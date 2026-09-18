<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Thrown when an SDK deadline elapses.
 *
 * Deadlines cover the whole operation, including receiving the response body,
 * so a stalled stream times out like a stalled connection.
 */
final class TimeoutException extends \RuntimeException implements FingerlyException
{
    /** A single HTTP request, including reading its body. */
    public const OPERATION_REQUEST = 'request';

    /** The whole polling loop in {@see Events::waitFor()}. */
    public const OPERATION_EVENT_WAIT = 'event_wait';

    /**
     * @param self::OPERATION_* $operation which deadline elapsed
     */
    public function __construct(
        private readonly string $operation = self::OPERATION_REQUEST,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            self::OPERATION_EVENT_WAIT === $operation
                ? 'Timed out waiting for the event'
                : 'Fingerly request timed out',
            0,
            $previous,
        );
    }

    /**
     * Which deadline elapsed.
     *
     * @return self::OPERATION_*
     */
    public function getOperation(): string
    {
        return $this->operation;
    }
}
