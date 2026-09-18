<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\Proxy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mounts the proxy as Laravel middleware.
 *
 * Resolve the {@see Proxy} in a service provider and append the middleware:
 *
 * ```php
 * // bootstrap/app.php (Laravel 11) or app/Http/Kernel.php (Laravel 10)
 * $middleware->append(\Fingerly\Proxy\LaravelMiddleware::class);
 * ```
 *
 * It matches on `$request->getPathInfo()`, which is the path relative to the
 * application, never the whole URI: a deployment under a sub-URI carries a
 * prefix the configuration knows nothing about.
 *
 *
 * Symfony's `ResponseHeaderBag` appends `private` to any `Cache-Control`
 * without a visibility directive, so the relayed `no-store` arrives as
 * `no-store, private`. That is a narrowing rather than a weakening, and it is
 * left alone rather than fought.
 * Requires `illuminate/http`, which is a suggest rather than a dependency.
 */
final class LaravelMiddleware
{
    public function __construct(private readonly Proxy $proxy) {}

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $proxied = $this->toProxyRequest($request);
        if (!$this->proxy->handles($proxied)) {
            return $next($request);
        }

        $answer = $this->proxy->handle($proxied);

        return new Response($answer->body, $answer->status, $answer->headers);
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
            path: $request->getPathInfo(),
            headers: $headers,
            body: is_resource($body) ? $body : (string) $request->getContent(),
        );
    }
}
