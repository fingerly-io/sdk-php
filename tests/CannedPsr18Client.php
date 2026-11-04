<?php

declare(strict_types=1);

namespace Fingerly\Tests;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers every request with one canned response, or
 * fails it with one canned throwable, and remembers what it was sent.
 *
 * @internal
 */
final class CannedPsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $sent = [];

    public function __construct(private readonly ResponseInterface|\Throwable $answer) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
