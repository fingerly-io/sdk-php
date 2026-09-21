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

```php
$fingerly = new \Fingerly\Client($secretKey, [
    'http_client' => $psr18Client,
    'request_factory' => $psr17Factory,
]);
```

> PSR-18 has no way to express "do not follow redirects" or a deadline, so a
> supplied client owns both. The ext-curl path enforces them itself. If you
> supply a client, disable redirects on it: an authenticated request that
> follows one replays your secret key to another host.

## Deadlines

`timeout` bounds the whole exchange, including receiving the body, so a server
that answers and then stalls times out like one that never answered. It
defaults to 5 seconds. cURL's `CURLOPT_TIMEOUT_MS` covers the transfer end to
end, which is what makes that true here without the bookkeeping the Ruby and
Java SDKs needed.

## Errors

Everything thrown implements `\Fingerly\FingerlyException`, so one `catch`
handles the lot, and the concrete classes tell them apart:

| Class | When |
| --- | --- |
| `ConfigurationException` | An option was rejected. Nothing was sent. It is an `InvalidArgumentException`, because a key that was never valid will not become valid on a retry. |
| `ApiException` | The API answered with a non-2xx status. |
| `TimeoutException` | The request deadline elapsed. |
| `ConnectionException` | The API could not be reached, or the connection failed mid-response. |

`ApiException` carries `getStatus()`, and optional `getErrorCode()`,
`getDetails()` and `getRequestId()`. The secret key is stripped from all three,
and the response body is never echoed into a message. `getCode()` is the HTTP
status too, so a handler that only logs the code still logs something useful.

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
new event type needs no upgrade.

Webhook verification touches neither the network nor ext-curl, so a receiver
that does nothing else pulls in nothing else.

## Same-origin proxy

Content blockers refuse browser requests to third-party APIs, and a refused
identification is a visitor never identified. Mount the proxy on a path of your
own site and those requests become first-party. The proxy key is what lets that
hop declare the visitor's real address, origin and user agent; a public key
alone never can, so nobody can spoof them.

```php
$proxy = new \Fingerly\Proxy([
    'proxy_key' => getenv('FINGERLY_PROXY_KEY'),
    'prefix' => '/metrics',
    'client_ip' => fn (\Fingerly\Proxy\ProxyRequest $r) => $r->header('cf-connecting-ip'),
]);
```

PHP has three live server request shapes, so there are three adapters over one
core:

```php
// Slim, Mezzio, anything PSR-15
$app->add(new \Fingerly\Proxy\Psr15Middleware($proxy, $responseFactory));

// Laravel: bootstrap/app.php (11) or app/Http/Kernel.php (10)
$middleware->append(\Fingerly\Proxy\LaravelMiddleware::class);

// Symfony: config/services.yaml
// Fingerly\Proxy\SymfonyListener: { arguments: ['@fingerly.proxy'] }
```

Then point the browser SDK at the same path:

```ts
const fingerly = await load({ apiKey: 'fly_pk_…', endpoints: '/metrics' })
```

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
));

// or, with no framework at all
$proxy->handle(\Fingerly\Proxy\ProxyRequest::fromGlobals())->send();
```

`$proxy->handles($request)` answers whether a request is one of the two the
proxy owns, so everything else goes to your application untouched rather than
getting a 404 of ours. `$proxy->maxBodyBytes()` is there so glue code can read
no further than the limit.

### What the proxy does

It forwards only `POST` to `{prefix}/api/v1/identify` and
`{prefix}/api/v1/events/{request_id}/supplement`. Anything else gets `404`, and
any other method gets `405`. Event reads need a secret key and stay unreachable
from a browser by construction.

The browser must present a public key from the same environment as the proxy
key, or the request gets `401`. Bodies over 1 MiB get `413`. A
Fingerly that stalls or cannot be reached gets `502` after 5 seconds rather
than holding the browser's connection open. Responses are marked `no-store`.
Only the content type, the visitor metadata Fingerly needs, and an
`Idempotency-Key` are forwarded; customer cookies and authorization headers
never are.

`prefix` must match where you mount the adapter, and it is matched against the
application-relative path — `getPathInfo()` under Laravel and Symfony, the
PSR-7 URI path under PSR-15 — never the whole request URI, because a
deployment under a sub-URI carries a prefix the configuration knows nothing
about. Mounting under a router that strips the prefix also works: it is
accepted as either present or already stripped. Choose a neutral path. Blockers
look for words such as `fingerprint`, `tracking` and `fingerly`.

> **`client_ip` is the one thing you must get right.** Resolve it from a header
> your own infrastructure sets, such as your load balancer's or CDN's. Never
> read the left-most `X-Forwarded-For` value: the visitor writes it.

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
HTTP exchange, over ext-curl or a supplied PSR-18 client, and maps failures to
`ApiException`. `Events.php` owns the event reads and the list filters;
`Event.php`, `EventPage.php`, `EventTrigger.php` and
`WebhookEvent.php` are what they decode into, and `Json.php` reads those
bodies without trusting their shape. `Webhook.php` verifies signatures and
needs nothing else in the package. `Proxy.php` is the same-origin core, with
`Proxy/` holding its request and response types and the three adapters.
`Config.php` validates every caller-supplied option: keys, origins, API
versions, mount paths, durations and sizes, all before any network access.
