<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Thrown by {@see Webhook::parse()} when a payload is not a delivery this SDK
 * can read: not JSON, not an object, or missing a field every delivery
 * carries.
 *
 * It is not an {@see ApiException}: no API call was made, and a webhook
 * receiver that maps API errors to retries must not retry a bad payload. The
 * message names the field that disagreed, never its value.
 */
final class WebhookException extends \UnexpectedValueException implements FingerlyException {}
