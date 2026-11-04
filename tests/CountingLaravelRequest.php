<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Illuminate\Http\Request as BaseRequest;

/**
 * A request that counts how often its body is read, so a test can show that
 * an adapter leaves a request it does not handle untouched.
 *
 * @internal
 */
final class CountingLaravelRequest extends BaseRequest
{
    public int $contentReads = 0;

    public function getContent(bool $asResource = false)
    {
        ++$this->contentReads;

        return parent::getContent($asResource);
    }
}
