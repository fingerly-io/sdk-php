<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Thrown when the API could not be reached, or the connection failed
 * mid-response.
 *
 * The secret key is stripped from the message, which can otherwise carry the
 * request URL.
 */
final class ConnectionException extends \RuntimeException implements FingerlyException
{
    /**
     * @param string $message        the failure, with the key stripped
     * @param string $kind           one short word for the failure that names no host or
     *                               URL: `connect`, `dns`, `tls`, `connection_lost`,
     *                               `too_large`, `client` or `other`, for a log line that
     *                               must not disclose
     *                               where the request was going
     * @param bool   $requestWritten whether the whole request had been written
     *                               before the connection failed, so the server
     *                               may have acted on it; false when it cannot
     *                               be told
     */
    public function __construct(string $message, public readonly string $kind = 'other', public readonly bool $requestWritten = false)
    {
        parent::__construct($message);
    }
}
