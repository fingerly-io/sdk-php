<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\AgentSighting;
use Fingerly\AgentSightings;
use Fingerly\Proxy\ProxyRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reports the responses your application served as PSR-15 middleware, for
 * Slim, Mezzio and anything else that speaks it.
 *
 * ```php
 * $app->add(new \Fingerly\AgentSightings\Psr15Middleware($sightings));
 * ```
 *
 * The response is handed back untouched; the report is buffered in memory
 * after the application answered. Under PHP-FPM the batch is sent once the
 * response is finished. On a long-running server, call
 * {@see AgentSightings::flushIfDue()} after each response is written.
 *
 * Requires `psr/http-server-middleware`, which is a suggest rather than a
 * dependency.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function __construct(private readonly AgentSightings $sightings) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $this->sightings->report(self::toProxyRequest($request), $response->getHeaderLine('content-type'));

        return $response;
    }

    /** What Fingerly said sent this request; see {@see AgentSightings::lookup()}. */
    public function lookup(ServerRequestInterface $request): AgentSighting
    {
        return $this->sightings->lookup(self::toProxyRequest($request));
    }

    /**
     * The request's method, path, headers and peer, never its body, in the
     * shape the `client_ip` callback reads.
     */
    public static function toProxyRequest(ServerRequestInterface $request): ProxyRequest
    {
        $server = $request->getServerParams();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        // Host as the client sent it: slim/psr7 replaces the header with the
        // URI's host, lowercased and without its port.
        if (is_string($server['HTTP_HOST'] ?? null)) {
            $headers['host'] = $server['HTTP_HOST'];
        }

        return new ProxyRequest(
            method: is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : $request->getMethod(),
            path: $request->getUri()->getPath(),
            headers: $headers,
            remoteAddress: is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null,
        );
    }
}
