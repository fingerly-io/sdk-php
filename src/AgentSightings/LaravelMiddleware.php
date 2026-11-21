<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\AgentSighting;
use Fingerly\AgentSightings;
use Fingerly\Proxy\ProxyRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports the responses your application served as Laravel terminable
 * middleware.
 *
 * Bind {@see AgentSightings} as a singleton in a service provider and append
 * the middleware:
 *
 * ```php
 * // bootstrap/app.php (Laravel 11) or app/Http/Kernel.php (Laravel 10)
 * $middleware->append(\Fingerly\AgentSightings\LaravelMiddleware::class);
 * ```
 *
 * It does nothing while the request is handled. The report is made in
 * `terminate()`, which Laravel calls after the response was sent (under
 * PHP-FPM, after it was finished), and a due batch is sent from there, under
 * Octane too. Paths are matched against `$request->getPathInfo()`, the path
 * relative to the application.
 *
 * Requires `illuminate/http`, which is a suggest rather than a dependency.
 */
final class LaravelMiddleware
{
    public function __construct(private readonly AgentSightings $sightings) {}

    /**
     * @param \Closure(Request): Response $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->sightings->report(self::toProxyRequest($request), (string) $response->headers->get('content-type', ''));
        $this->sightings->flushIfDue();
    }

    /** What Fingerly said sent this request; see {@see AgentSightings::lookup()}. */
    public function lookup(Request $request): AgentSighting
    {
        return $this->sightings->lookup(self::toProxyRequest($request));
    }

    /**
     * The request's method, application-relative path, headers and peer,
     * never its body, in the shape the `client_ip` callback reads.
     */
    public static function toProxyRequest(Request $request): ProxyRequest
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_filter($values, 'is_string'));
        }
        $method = $request->server->get('REQUEST_METHOD');

        return new ProxyRequest(
            method: is_string($method) ? $method : $request->getMethod(),
            path: $request->getPathInfo(),
            headers: $headers,
            remoteAddress: is_string($remote = $request->server->get('REMOTE_ADDR')) ? $remote : null,
        );
    }
}
