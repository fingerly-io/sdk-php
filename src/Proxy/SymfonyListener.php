<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

use Fingerly\Proxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mounts the proxy as a Symfony kernel listener.
 *
 * ```yaml
 * # config/services.yaml
 * Fingerly\Proxy\SymfonyListener:
 *     arguments: ['@fingerly.proxy']
 * ```
 *
 * It answers during `kernel.request`, before routing, so the proxy's paths
 * need no route of their own. It matches on `$request->getPathInfo()`, which
 * is the path relative to the application, never the whole URI.
 *
 *
 * Symfony's `ResponseHeaderBag` appends `private` to any `Cache-Control`
 * without a visibility directive, so the relayed `no-store` arrives as
 * `no-store, private`. That is a narrowing rather than a weakening, and it is
 * left alone rather than fought.
 * Requires `symfony/http-kernel`, which is a suggest rather than a dependency.
 */
final class SymfonyListener implements EventSubscriberInterface
{
    public function __construct(private readonly Proxy $proxy) {}

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

        $proxied = $this->toProxyRequest($event->getRequest());
        if (!$this->proxy->handles($proxied)) {
            return;
        }

        $answer = $this->proxy->handle($proxied);
        $event->setResponse(new Response($answer->body, $answer->status, $answer->headers));
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
            path: $request->getPathInfo(),
            headers: $headers,
            body: is_resource($body) ? $body : (string) $request->getContent(),
        );
    }
}
