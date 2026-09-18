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
final class ConnectionException extends \RuntimeException implements FingerlyException {}
