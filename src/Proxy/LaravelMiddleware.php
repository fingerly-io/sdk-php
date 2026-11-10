<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\ConfigurationException;
use Fingerly\Proxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mounts the proxy as Laravel middleware.
 *
 * Resolve the {@see Proxy} in a service provider, with the `path` the dashboard
 * issued, and append the middleware:
 *
 * ```php
 * // bootstrap/app.php (Laravel 11) or app/Http/Kernel.php (Laravel 10)
 * $middleware->append(\Fingerly\Proxy\LaravelMiddleware::class);
 * ```
 *
 * It matches that one path against `$request->getPathInfo()`, which is the
 * path relative to the application, never the whole URI: a deployment under a
 * sub-URI carries a prefix the configuration knows nothing about. Every other
 * request goes on to the application.
 *
 * Symfony's `ResponseHeaderBag` appends `private` to any `Cache-Control`
 * without a visibility directive, so the relayed `no-store` arrives as
 * `no-store, private`. That is a narrowing rather than a weakening, and it is
 * left alone rather than fought.
 * Requires `illuminate/http`, which is a suggest rather than a dependency.
 */
final class LaravelMiddleware
{
    /**
     * @throws ConfigurationException if the proxy has no `path`: middleware
     *                                sees every request, so without one it would answer them all
     */
    public function __construct(private readonly Proxy $proxy)
    {
        if (null === $proxy->path()) {
            throw new ConfigurationException('LaravelMiddleware needs a Proxy with the path the dashboard issued');
        }
    }

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        // Match on the path alone first: building the full request copies the
        // body into a temp stream, which every other route would pay for.
        if (!$this->proxy->handles(new ProxyRequest($request->getMethod(), self::path($request)))) {
            return $next($request);
        }

        $answer = $this->proxy->handle($this->toProxyRequest($request));

        return new Response($answer->body, $answer->status, $answer->headers);
    }

    /**
     * The application-relative path, but only when the raw request target is
     * exactly the base path plus it. getPathInfo() also strips a
     * front-controller name the request spelled out, and `/index.php/k7x2qm4a`
     * is not the path the proxy was given.
     */
    private static function path(Request $request): string
    {
        $raw = explode('?', $request->getRequestUri(), 2)[0];

        return $raw === $request->getBasePath().$request->getPathInfo() ? $request->getPathInfo() : $raw;
    }

    private function toProxyRequest(Request $request): ProxyRequest
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_filter($values, 'is_string'));
        }

        // getContent(true) hands back the stream rather than the buffered
        // string, which is what lets the core stop at its own limit.
        $body = $request->getContent(true);

        return new ProxyRequest(
            method: $request->getMethod(),
            path: self::path($request),
            headers: $headers,
            body: is_resource($body) ? $body : (string) $request->getContent(),
            remoteAddress: is_string($remote = $request->server->get('REMOTE_ADDR')) ? $remote : null,
        );
    }
}
