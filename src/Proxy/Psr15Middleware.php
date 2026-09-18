<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\Proxy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Mounts the proxy as PSR-15 middleware, for Slim, Mezzio and anything else
 * that speaks it.
 *
 * ```php
 * $app->add(new \Fingerly\Proxy\Psr15Middleware($proxy, $responseFactory));
 * ```
 *
 * Requires `psr/http-server-middleware`, which is a suggest rather than a
 * dependency: a build that only reads events needs none of it.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Proxy $proxy,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $proxied = $this->toProxyRequest($request);
        // Ask before answering, so everything else reaches the application
        // rather than getting a 404 of ours.
        if (!$this->proxy->handles($proxied)) {
            return $handler->handle($request);
        }

        $answer = $this->proxy->handle($proxied);
        $response = $this->responses->createResponse($answer->status);
        foreach ($answer->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write($answer->body);

        return $response;
    }

    private function toProxyRequest(ServerRequestInterface $request): ProxyRequest
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return new ProxyRequest(
            method: $request->getMethod(),
            path: $request->getUri()->getPath(),
            headers: $headers,
            // Read one byte past the limit and no further, so an oversized
            // body is refused without being held in memory first.
            body: $stream->read($this->proxy->maxBodyBytes() + 1),
        );
    }
}
