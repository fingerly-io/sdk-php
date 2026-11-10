<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\ConfigurationException;
use Fingerly\Proxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mounts the proxy as a Symfony kernel listener, at the `path` the dashboard
 * issued, which the {@see Proxy} must be given.
 *
 * ```yaml
 * # config/services.yaml
 * Fingerly\Proxy\SymfonyListener:
 *     arguments: ['@fingerly.proxy']
 * ```
 *
 * It answers during `kernel.request`, before routing, so the proxy's path
 * needs no route of its own. It matches that one path against
 * `$request->getPathInfo()`, which is the path relative to the application,
 * never the whole URI. Every other request goes on to the router.
 *
 * Symfony's `ResponseHeaderBag` appends `private` to any `Cache-Control`
 * without a visibility directive, so the relayed `no-store` arrives as
 * `no-store, private`. That is a narrowing rather than a weakening, and it is
 * left alone rather than fought.
 * Requires `symfony/http-kernel`, which is a suggest rather than a dependency.
 */
final class SymfonyListener implements EventSubscriberInterface
{
    /**
     * @throws ConfigurationException if the proxy has no `path`: the listener
     *                                sees every request, so without one it would answer them all
     */
    public function __construct(private readonly Proxy $proxy)
    {
        if (null === $proxy->path()) {
            throw new ConfigurationException('SymfonyListener needs a Proxy with the path the dashboard issued');
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        // A high priority, so the proxy answers before the router decides
        // there is nothing at this path.
        return [KernelEvents::REQUEST => ['onKernelRequest', 512]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // Match on the path alone first, so other routes never pay for the
        // header copy or a body stream.
        if (!$this->proxy->handles(new ProxyRequest($request->getMethod(), self::path($request)))) {
            return;
        }

        $answer = $this->proxy->handle($this->toProxyRequest($request));
        $event->setResponse(new Response($answer->body, $answer->status, $answer->headers));
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
