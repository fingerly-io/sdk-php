<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\AgentSighting;
use Fingerly\AgentSightings;
use Fingerly\Proxy\ProxyRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reports the responses your application served as a Symfony kernel
 * listener.
 *
 * ```yaml
 * # config/services.yaml
 * Fingerly\AgentSightings\SymfonyListener:
 *     arguments: ['@fingerly.agent_sightings']
 * ```
 *
 * It listens on `kernel.terminate`, which runs after the response was sent
 * (under PHP-FPM, after it was finished), and reports the response there.
 * Under RoadRunner and Swoole runtimes (`long_running`) a due batch is sent
 * from there; under PHP-FPM it is sent once the response is finished; and
 * where the server cannot finish a response before the script ends (the
 * built-in server, Apache's module), it is never sent on a request. Paths are
 * matched against `$request->getPathInfo()`, the path relative to the
 * application.
 *
 * Requires `symfony/http-kernel`, which is a suggest rather than a dependency.
 */
final class SymfonyListener implements EventSubscriberInterface
{
    public function __construct(private readonly AgentSightings $sightings) {}

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onKernelTerminate', 0]];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->sightings->report(
            self::toProxyRequest($event->getRequest()),
            (string) $event->getResponse()->headers->get('content-type', ''),
        );
        $this->sightings->responseSent();
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
