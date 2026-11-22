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
    /**
     * @param bool $requestSent whether any of the request had been written when the
     *                          deadline elapsed, so the server may have acted on it;
     *                          false when it never got past connecting
     */
    public function __construct(?\Throwable $previous = null, public readonly bool $requestSent = true)
    {
        parent::__construct('Fingerly request timed out', 0, $previous);
    }
}
