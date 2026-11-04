<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\ConfigurationException;
use Fingerly\Proxy;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
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
        // Ask before answering, so everything else reaches the application
        // rather than getting a 404 of ours. Match on the path alone first:
        // building the full request reads the body stream, which the
        // application's own handler still needs.
        if (!$this->proxy->handles(new ProxyRequest($request->getMethod(), self::path($request)))) {
            return $handler->handle($request);
        }

        $answer = $this->proxy->handle($this->toProxyRequest($request));
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
            path: self::path($request),
            headers: $headers,
            body: self::readAtMost($stream, $this->proxy->maxBodyBytes() + 1),
            remoteAddress: is_string($remote = $request->getServerParams()['REMOTE_ADDR'] ?? null) ? $remote : null,
        );
    }

    /**
     * The path exactly as the request carried it.
     *
     * Some PSR-7 implementations normalise the URI they build (folding `//`
     * into `/`, for one), so the raw request target from the server
     * parameters is preferred whenever the runtime provides it.
     */
    private static function path(ServerRequestInterface $request): string
    {
        $raw = $request->getServerParams()['REQUEST_URI'] ?? null;
        if (is_string($raw) && str_starts_with($raw, '/')) {
            return explode('?', $raw, 2)[0];
        }

        return $request->getUri()->getPath();
    }

    /**
     * Reads up to $limit bytes, looping because StreamInterface::read() may
     * return fewer bytes than asked for: over php://input or a socket, fread()
     * returns one 8 KiB chunk per call, so a single read() silently truncated
     * every body larger than that. Reading one byte past the proxy's limit and
     * no further still refuses an oversized body without holding it whole.
     */
    private static function readAtMost(StreamInterface $stream, int $limit): string
    {
        $body = '';
        while (strlen($body) < $limit && !$stream->eof()) {
            $chunk = $stream->read($limit - strlen($body));
            if ('' === $chunk) {
                break;
            }
            $body .= $chunk;
        }

        return $body;
    }
}
