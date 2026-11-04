<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Symfony\Component\HttpFoundation\Request as BaseRequest;

/**
 * A request that counts how often its body is read, so a test can show that
 * an adapter leaves a request it does not handle untouched.
 *
 * @internal
 */
final class CountingSymfonyRequest extends BaseRequest
{
    public int $contentReads = 0;

    public function getContent(bool $asResource = false)
    {
        ++$this->contentReads;

        return parent::getContent($asResource);
    }
}
