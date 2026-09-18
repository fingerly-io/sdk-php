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
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Fingerly request timed out', 0, $previous);
    }
}
