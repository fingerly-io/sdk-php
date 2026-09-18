<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Implemented by everything this package throws.
 *
 * Catch it to handle any Fingerly failure at once, or catch the concrete
 * classes to tell them apart.
 */
interface FingerlyException extends \Throwable {}
