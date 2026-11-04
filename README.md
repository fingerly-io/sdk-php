# fingerly/fingerly-php

Server-side event reads, webhook verification and a same-origin proxy for PHP.
PHP 8.1 or newer, and nothing outside `ext-curl` and `ext-json`. Version 0.1.0
is prepared for release; the repository is private and the package has not been
published to Packagist.

```sh
composer require fingerly/fingerly-php
```

```php
$fingerly = new \Fingerly\Client(getenv('FINGERLY_SECRET_KEY'));

$event = $fingerly->events->get($requestId);
$page = $fingerly->events->list(['level' => 'high', 'limit' => 50]);
```

Event reads require `fly_sk_{production|staging|development}_…` keys. Requests
go to `https://api.fingerly.io`; the `endpoint` option overrides that for a
local API. Public, proxy, management, malformed and unsupported keys are
rejected before network access. Requests do not follow redirects. `get` and
`list` each perform one request and never retry.

Construct the client once and reuse it. Every option is validated in the
constructor, so an invalid key or endpoint throws before anything is sent.

## No dependencies

`ext-curl` is the only HTTP client PHP ships, so it is what the package uses
and there is nothing to install. CI asserts the package installs with zero
Composer packages and that the client, the proxy and webhook verification all
work in that state.

Everything else is a `suggest`, pulled in only if you use it: `psr/http-client`
to route API calls through your own HTTP stack, and
`psr/http-server-middleware`, `illuminate/http` or `symfony/http-kernel` for
whichever proxy adapter you mount.

A supplied client takes a PSR-17 `request_factory`, plus a `stream_factory`
wherever a body is sent: the `Proxy` refuses an `http_client` without both
factories. With Guzzle (`composer require guzzlehttp/guzzle`), one object is
both factories:

```php
// Redirects off and a deadline set: a supplied client owns both (see below).
$http = new \GuzzleHttp\Client(['allow_redirects' => false, 'timeout' => 5]);
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$fingerly = new \Fingerly\Client(getenv('FINGERLY_SECRET_KEY'), [
    'http_client' => $http,
    'request_factory' => $factory,
    'stream_factory' => $factory,
]);

$proxy = new \Fingerly\Proxy([
    'proxy_key' => getenv('FINGERLY_PROXY_KEY'),
    'path' => '/k7x2qm4a',
    // Your CDN's visitor header when present, otherwise the TCP peer.
    'client_ip' => fn (\Fingerly\Proxy\ProxyRequest $r) => $r->header('cf-connecting-ip') ?: (string) $r->remoteAddress,
    'http_client' => $http,
    'request_factory' => $factory,
    'stream_factory' => $factory,
]);
```

> PSR-18 has no way to express "do not follow redirects" or a deadline, so a
> supplied client owns both. The ext-curl path enforces them itself. If you
> supply a client, disable redirects on it: an authenticated request that
> follows one replays your secret key to another host.

## Deadlines

`timeout` bounds the whole exchange, including receiving the body, so a server
that answers and then stalls, or sends a byte at a time, times out like one
that never answered. It defaults to 5 seconds, for reads and for the proxy.
cURL's `CURLOPT_TIMEOUT_MS` covers the transfer end to end. A response body
over 4 MiB is refused rather than buffered.

## Connections

A client or proxy keeps one cURL handle and reuses its connection, so a
long-running worker (Octane, FrankenPHP, RoadRunner) opens one connection to
Fingerly and keeps it. On PHP 8.5 and later the connection, DNS and TLS session
caches are also shared across the requests a PHP-FPM worker serves, so a client
built per request reuses them too. On PHP 8.1 to 8.4 under PHP-FPM each request
that builds a new client opens a new connection; at a few hundred proxied
requests a second per host, run PHP 8.5 or a worker runtime. No cookies are
kept, and redirects are never followed.

## Errors

Everything thrown implements `\Fingerly\FingerlyException`, so one `catch`
handles the lot, and the concrete classes tell them apart:

| Class | When |
| --- | --- |
| `ConfigurationException` | An option was rejected. Nothing was sent. It is an `InvalidArgumentException`, because a key that was never valid will not become valid on a retry. |
| `ApiException` | The API answered with a non-2xx status, a redirect included. |
| `TimeoutException` | The request deadline elapsed. |
| `ConnectionException` | The API could not be reached, the connection failed mid-response, or the response body was over 4 MiB. |

`ApiException` carries `getStatus()`, and optional `getErrorCode()`,
`getDetails()`, `getRequestId()` and `getRetryAfter()`, the seconds the API's
`Retry-After` asked you to wait (null when it sent none). The secret key is
stripped from all of them, and the response body is never echoed into a
message. `getCode()` is the HTTP status too, so a handler that only logs the
code still logs something useful. Reads never retry on their own; after a
`429` (`$e->isRateLimited()`), wait `getRetryAfter()` seconds before your own
retry.

```php
try {
    $event = $fingerly->events->get($requestId);
} catch (\Fingerly\ApiException $e) {
    if ($e->isEventNotFound()) {
        // Not archived yet.
    }
}
```

The one retryable read failure has a predicate, so it needs no status check:
`$e->isEventNotFound()`.

An option this build does not know is refused rather than ignored, because a
silently dropped `timeout` or `endpoint` is exactly the kind of thing nobody
notices until it matters.

## Unscored events

`$event->suspectScore` is `null` when the request was not scored. Never read
null as zero: a visitor about whom nothing fired scores 0, while a submission
that was never enriched has no score at all. `$event->isScored()` states the
distinction at the call site.

```php
if (!$event->isScored()) {
    // Unscored. Decide on something else; do not treat it as clean.
}
```

`$event->raw` and `$page->raw` keep the response body exactly as it arrived, so
a field the API adds later is reachable without an upgrade.

## Read an event you just identified

An event is readable as soon as identification answers, so this needs nothing
special:

```php
$event = $fingerly->events->get($requestId);
```

Earlier versions had a `waitFor` that polled through the gap between
identification and the event becoming queryable. There is no gap, so
`isEventNotFound()` now means a request id that was never issued.

The archived `document` arrives moments after the event itself, so check
`hasDocument` rather than assuming it is there.

## Verify a backend action

Read the event with a server-only key. Check that its tag matches your action,
that it is recent, that its environment is correct, and that it was scored.
Apply your own policy to the server's verdict. Do not authorize an action using
scores or visitor identifiers supplied by the browser. Tags correlate events;
they do not replace your application's authentication or replay protection.

## Webhooks

```php
$valid = \Fingerly\Webhook::verify(
    secret: getenv('FINGERLY_WEBHOOK_SECRET'),
    payload: $request->getContent(),   // the exact bytes, before any parsing
    timestamp: $request->header('x-fingerly-timestamp'),
    signature: $request->header('x-fingerly-signature'),
);
abort_unless($valid, 400);

$event = \Fingerly\Webhook::parse($request->getContent());
ProcessFingerlyEvent::dispatch($event->id);   // deduplicate on the ID
```

Verify the exact raw bytes before decoding them: re-serialising them changes
the digest. Signatures cover the timestamp and body. The default replay
tolerance is five minutes, changed with the `tolerance` argument. During a
signing-secret rotation, any matching signature in the comma-separated header
is accepted. Malformed or missing input is a `false` result, never an
exception.

Verification proves authenticity and freshness. It does not deduplicate, so
persist processed `WebhookEvent::$id` values as well. `Webhook::parse()`
decodes the envelope every delivery shares and leaves `$data` an array, so a
new event type needs no upgrade. A payload it cannot read throws
`WebhookException`, which names the field at fault and never its value.

Webhook verification touches neither the network nor ext-curl, so a receiver
that does nothing else pulls in nothing else.

## Same-origin proxy

Content blockers refuse browser requests to third-party APIs, and a refused
identification is a visitor never identified. Serving the browser SDK from a
path on your own site makes those requests first-party. Create a proxy in the
dashboard (**Integration > Proxy keys**) and mount the proxy at the path it
issued, here `/k7x2qm4a`. The proxy key is what lets that hop declare the
visitor's real address, origin and user agent; a public key alone never can, so
nobody can spoof them.

```php
$proxy = new \Fingerly\Proxy([
    'proxy_key' => getenv('FINGERLY_PROXY_KEY'),
    'path' => '/k7x2qm4a',
    // Your CDN's visitor header when present, otherwise the TCP peer.
    'client_ip' => fn (\Fingerly\Proxy\ProxyRequest $r) => $r->header('cf-connecting-ip') ?: (string) $r->remoteAddress,
]);
```

PHP has three live server request shapes, so there are three adapters over one
core. Build the `Proxy` once per process and share it: it holds no per-request
state, and sharing it is what lets its connection be reused.

### Slim, Mezzio, anything PSR-15

```php
$app = \Slim\Factory\AppFactory::create();
// ... your routes and other middleware ...
// Added last, so it runs first.
$app->add(new \Fingerly\Proxy\Psr15Middleware($proxy, $app->getResponseFactory()));
```

### Laravel

The middleware receives the `Proxy` from the container, which cannot build it
on its own (`client_ip` is a callable), so bind it once. Without the binding
every request to the application fails with a `BindingResolutionException`.

```php
// config/services.php
'fingerly' => [
    'proxy_key' => env('FINGERLY_PROXY_KEY'),
    'proxy_path' => env('FINGERLY_PROXY_PATH'),
    'secret_key' => env('FINGERLY_SECRET_KEY'),
],
```

```php
// app/Providers/AppServiceProvider.php, in register()
$this->app->singleton(\Fingerly\Proxy::class, fn () => new \Fingerly\Proxy([
    'proxy_key' => config('services.fingerly.proxy_key'),
    'path' => config('services.fingerly.proxy_path'),
    'client_ip' => fn (\Fingerly\Proxy\ProxyRequest $r) => $r->header('cf-connecting-ip') ?: (string) $r->remoteAddress,
    'on_error' => fn (string $line) => \Illuminate\Support\Facades\Log::warning($line),
]));
$this->app->singleton(\Fingerly\Client::class, fn () => new \Fingerly\Client(
    config('services.fingerly.secret_key'),
));
```

```php
// bootstrap/app.php (Laravel 11 and later)
->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware): void {
    $middleware->append(\Fingerly\Proxy\LaravelMiddleware::class);
})

// app/Http/Kernel.php (Laravel 10): add it to $middleware
\Fingerly\Proxy\LaravelMiddleware::class,
```

Laravel reads and decodes a JSON body when it builds the request, before any
middleware runs, so under Laravel the 1 MiB limit refuses an oversized body but
cannot stop it being read into memory first. Cap the body size in the web
server as well: `client_max_body_size` in nginx, `request_body { max_size }` in
Caddy and FrankenPHP.

### Symfony

The listener runs before the router. Define the `Proxy` as a service, with an
invokable class for `client_ip`, because a closure cannot be written in YAML.
With the skeleton's `autoconfigure: true`, the listener is registered as an
event subscriber on its own.

```yaml
# config/services.yaml, below the skeleton's _defaults and App\ entries
    fingerly.proxy:
        class: Fingerly\Proxy
        arguments:
            - proxy_key: '%env(FINGERLY_PROXY_KEY)%'
              path: '%env(FINGERLY_PROXY_PATH)%'
              client_ip: ['@App\Fingerly\ClientIp', '__invoke']
              on_error: ['@logger', 'warning']

    Fingerly\Proxy\SymfonyListener:
        arguments: ['@fingerly.proxy']

    Fingerly\Client:
        arguments: ['%env(FINGERLY_SECRET_KEY)%']
```

```php
// src/Fingerly/ClientIp.php
namespace App\Fingerly;

use Fingerly\Proxy\ProxyRequest;

final class ClientIp
{
    public function __invoke(ProxyRequest $request): string
    {
        return $request->header('cf-connecting-ip') ?: (string) $request->remoteAddress;
    }
}
```

Then point the browser SDK at the same path:

```ts
const fingerly = await load({ apiKey: 'fly_pk_…', endpoints: '/k7x2qm4a' })
```

The SDK posts both of its calls to exactly that path, with nothing appended.

### Any other framework

The adapters are conveniences; `handle()` is the interface. Phalcon,
CodeIgniter, CakePHP, Yii, a queue worker or a bare `index.php` all need about
ten lines:

```php
// Phalcon
$response = $proxy->handle(new \Fingerly\Proxy\ProxyRequest(
    method: $this->request->getMethod(),
    path: $this->request->getURI(true),
    headers: $this->request->getHeaders(),
    body: $this->request->getRawBody(),
    remoteAddress: $this->request->getClientAddress(),
));

// or, with no framework at all
$proxy->handle(\Fingerly\Proxy\ProxyRequest::fromGlobals())->send();
```

`fromGlobals()` reads `$_SERVER` and `php://input`, so it is for PHP-FPM, CGI
and the built-in server only. In a long-running worker those globals belong to
the worker, not the request: build the `ProxyRequest` from the runtime's own
request object instead, and let `client_ip` read `remoteAddress` rather than
`$_SERVER['REMOTE_ADDR']`.

### Event loops

The proxy and the client are synchronous: each call holds the calling thread
for up to `timeout` while Fingerly answers. That is right for PHP-FPM, Octane,
FrankenPHP and RoadRunner, where a worker serves one request at a time. Never
call them from an event loop (ReactPHP, AMPHP, Revolt, or Swoole and
OpenSwoole coroutines): one slow answer would stall every request that loop is
serving. Hand the call to a worker process or a thread pool instead.

`$proxy->handles($request)` answers whether a request is the one the proxy
owns, so everything else goes to your application untouched rather than getting
a 404 of ours. `$proxy->maxBodyBytes()` is there so glue code can read no
further than the limit.

### The path

`path` is optional in the core. Given one, the proxy answers that path and
nothing else, matched byte for byte: `/k7x2qm4a/admin`, `/k7x2qm4a/`,
`//k7x2qm4a`, `/k7x2qm4a/.`, `/k7x2qm4a%2F` and `/K7X2QM4A` are not the
proxy's. A query string is ignored. Left out, it answers whatever your
framework routed to it, which suits a route that already names the path. It
must be slash-separated segments of letters, digits and `-._~`, with no
trailing slash and no `.` or `..` segment; anything else is a
`ConfigurationException`.

The three adapters see every request, so each one refuses a `Proxy` without a
`path` rather than answer them all, and there is no default to fall back on.
The path is matched against the application-relative path: `getPathInfo()`
under Laravel and Symfony, and the PSR-7 URI path under PSR-15. It is never the
whole request URI, because a deployment under a sub-URI carries a prefix the
configuration knows nothing about. Add the PSR-15 middleware to the whole
application or to that one route, not under a path your router strips.

Use the path the dashboard issued. Blockers look for words such as
`fingerprint`, `tracking`, `analytics`, `collect`, `identify` and `fingerly`.

### What the proxy does

It forwards only `POST`; any other method gets `405`, and a path that is not the
proxy's gets `404` first. Every request goes to one URL,
`https://api.fingerly.io/api/v1/ingest`, which tells the identification and the
deferred report apart by their bodies. `api_version` changes the version in
that URL and nothing else, and `upstream` its origin. Event reads need a secret
key and stay unreachable from a browser by construction.

The browser must present a public key from the same environment as the proxy
key, or the request gets `401`. Bodies over 1 MiB get `413`. One 5 second
deadline covers the whole exchange, so an upstream that never answers, or
answers a byte at a time, gets `504 Gateway timeout` rather than holding the
browser's connection open. An upstream that cannot be reached, drops the
connection, or answers a success that is not JSON gets `502 Bad gateway`. Both
are short `text/plain` answers marked `no-store`, and the proxy never throws,
so the framework never renders its own error page in their place. Pass
`on_error` to hear about them, one line each:

```php
'on_error' => fn (string $line) => \Illuminate\Support\Facades\Log::warning($line),
```

Only the content type, the visitor
metadata Fingerly needs, and an `Idempotency-Key` are forwarded; customer
cookies and authorization headers never are. The proxy reports itself upstream
as `php/0.1.0`, which the dashboard shows per proxy.

Only `Content-Type`, `X-Request-Id`, `RateLimit-Limit`, `RateLimit-Remaining`
and `Retry-After` are relayed back, plus `Cache-Control: no-store` set by the
proxy. It is an allowlist, so nothing on the response names Fingerly, whatever
the API adds in future.

> **`client_ip` is the one thing you must get right.** Resolve it from a header
> your own infrastructure sets, such as your load balancer's or CDN's, and fall
> back on `$r->remoteAddress`, the TCP peer the adapter passes in. Only trust
> that header when traffic can reach your server through that CDN or load
> balancer alone; otherwise a visitor can send it themselves. Never read the
> left-most `X-Forwarded-For` value: the visitor writes it.
>
> The callback must return exactly one IPv4 or IPv6 address. Anything else
> (null, an empty string, a list, a port, padding) is a configuration error:
> nothing is forwarded, the browser gets `500` with "The client IP callback
> returned no valid address", and the first occurrence in the process is
> reported through `on_error` (or `error_log()` without one).

Under Laravel and Symfony the relayed `Cache-Control: no-store` arrives as
`no-store, private`, because `ResponseHeaderBag` appends a visibility
directive to any `Cache-Control` without one. That is a narrowing rather than a
weakening, so it is left alone.

## Development and release preparation

```sh
composer install
composer test           # phpunit
composer stan           # phpstan, level 8, checked against the 8.1 floor
composer fmt:check      # php-cs-fixer
composer contract:sync  # refresh the vendored contract snapshot
```

The tests drive PHP's own built-in server on a real socket rather than a stub
of the transport, and the three adapters are exercised against the real
Laravel, Symfony and PSR-7 types they exist for. The vendored synthetic
contract snapshot in `contract/` makes them independent of sibling
repositories; to update it from the integration workspace, set
`FINGERLY_CONTRACT_DIR` to its `contract` directory. The snapshot's hashes are
checked by `composer test`, so there is no separate check step. Never put
production secrets in fixtures.

## Source structure

`Client.php` holds the client and its options. `Transport.php` performs one
HTTP exchange over a reused cURL handle or a supplied PSR-18 client, caps the
response body, and maps failures to `ApiException`. `Secret.php` holds a key
so that no dump or serialisation can show it. `Events.php` owns the event reads and the list filters;
`Event.php`, `EventPage.php`, `EventTrigger.php` and
`WebhookEvent.php` are what they decode into, and `Json.php` reads those
bodies without trusting their shape. `Webhook.php` verifies signatures and
needs nothing else in the package; `WebhookException.php` is what `parse()`
throws for a payload it cannot read. `Proxy.php` is the same-origin core, with
`Proxy/` holding its request and response types and the three adapters.
`Config.php` validates every caller-supplied option: keys, origins, API
versions, the proxy path, durations and sizes, all before any network access.
