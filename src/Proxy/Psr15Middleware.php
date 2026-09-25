<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\ConfigurationException;
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
 * The {@see Proxy} must be given the `path` the dashboard issued. It is
 * matched against the PSR-7 URI path, so add the middleware to the whole
 * application rather than piping it under that path, which would strip it.
 *
 * Requires `psr/http-server-middleware`, which is a suggest rather than a
 * dependency: a build that only reads events needs none of it.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    /**
     * @throws ConfigurationException if the proxy has no `path`: middleware
     *                                sees every request, so without one it would answer them all
     */
    public function __construct(
        private readonly Proxy $proxy,
        private readonly ResponseFactoryInterface $responses,
    ) {
        if (null === $proxy->path()) {
            throw new ConfigurationException('Psr15Middleware needs a Proxy with the path the dashboard issued');
        }
    }

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
