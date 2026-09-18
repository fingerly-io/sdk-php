<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Thrown when a caller-supplied option is rejected. Nothing was sent.
 *
 * It extends `InvalidArgumentException` rather than `RuntimeException`,
 * because a key that was never valid will not become valid on a retry: it is
 * a programming mistake rather than something to catch around a call.
 */
final class ConfigurationException extends \InvalidArgumentException implements FingerlyException {}
