<p align="center">
  <a href="https://fingerly.io">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/fingerly-io/.github/main/assets/logo-dark.svg">
      <img src="https://raw.githubusercontent.com/fingerly-io/.github/main/assets/logo-light.svg" alt="Fingerly" height="44">
    </picture>
  </a>
</p>

<p align="center"><b>Fingerly for PHP</b><br>Read device intelligence events, verify webhooks and serve the browser SDK from your own domain.</p>

<p align="center">
  <a href="https://packagist.org/packages/fingerly/fingerly-php"><img src="https://img.shields.io/packagist/v/fingerly/fingerly-php?style=flat-square" alt="Packagist version"></a>
  <a href="https://github.com/fingerly-io/sdk-php/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/fingerly-io/sdk-php/ci.yml?style=flat-square&label=ci" alt="CI status"></a>
  <a href="https://github.com/fingerly-io/sdk-php/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-Apache--2.0-blue?style=flat-square" alt="License: Apache-2.0"></a>
  <img src="https://img.shields.io/badge/php-%E2%89%A5%208.2-777BB4?style=flat-square" alt="PHP 8.2 or newer">
  <a href="https://docs.fingerly.io/docs/sdks/php"><img src="https://img.shields.io/badge/docs-docs.fingerly.io-111827?style=flat-square" alt="Documentation"></a>
</p>

<p align="center"><img src="https://raw.githubusercontent.com/fingerly-io/.github/main/assets/banner.webp" alt="Fingerly" width="100%"></p>

## What is this

`fingerly/fingerly-php` is the server-side SDK for PHP. It reads Fingerly events
with a secret key, verifies webhook signatures and relays the browser SDK's
traffic through your own domain with a same-origin proxy, with adapters for
Laravel, Symfony and PSR-15. It also reports the pages your server served, so
AI crawlers that never run JavaScript are seen too. It needs nothing outside `ext-curl` and `ext-json`
and is for backends that protect signups, logins and payments from fraud and
abuse. [Fingerly](https://fingerly.io) identifies the device behind each session
and scores how suspicious it is.

## Getting started

1. **Prerequisites.** A Fingerly account and a secret key (`fly_sk_...`). See
   [API keys and environments](https://docs.fingerly.io/docs/api-keys). PHP 8.2
   or newer, and nothing outside `ext-curl` and `ext-json`. Run a PHP release
   with the October 2026 security fixes (see [Requirements](#requirements)).
2. **Install.**

   ```sh
   composer require fingerly/fingerly-php:^0.5.0
   ```

3. **Read an event.**

   ```php
   $fingerly = new \Fingerly\Client(getenv('FINGERLY_SECRET_KEY'));

   $event = $fingerly->events->get($requestId);
   $page = $fingerly->events->list(['level' => \Fingerly\SuspectLevel::HIGH, 'limit' => 50]);
   ```

4. **Verify a webhook.**

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

Full guide: [docs.fingerly.io/docs/sdks/php](https://docs.fingerly.io/docs/sdks/php).

## Choose what you need

| I want to | Use | Read |
| --- | --- | --- |
| Read an event after the browser identified a visitor | `\Fingerly\Client` and `events->get` / `events->list` | [Read events](#read-events) |
| Verify a request id before a backend action, once | `events->verify` / `events->consume` | [Verify a backend action](#verify-a-backend-action) |
| Read or write an iOS device's Apple DeviceCheck bits | `deviceCheck->query` / `deviceCheck->write` | [Apple DeviceCheck bits](#apple-devicecheck-bits) |
| Check a webhook delivery is genuine | `\Fingerly\Webhook::verify` and `::parse` | [Verify webhooks](#verify-webhooks) |
| Keep the browser SDK first-party, away from content blockers | `\Fingerly\Proxy` with a Laravel, Symfony or PSR-15 adapter | [First-party proxy](#first-party-proxy-same-origin) |
| See AI crawlers that never run JavaScript, and know which requests they sent | `\Fingerly\AgentSightings` with a Laravel, Symfony or PSR-15 adapter | [AI crawlers on your server](#ai-crawlers-on-your-server) |

On this page: [Dependencies](#no-dependencies) · [Read events](#read-events) · [DeviceCheck bits](#apple-devicecheck-bits) · [Verify webhooks](#verify-webhooks) · [First-party proxy](#first-party-proxy-same-origin) · [AI crawlers](#ai-crawlers-on-your-server) · [Requirements](#requirements) · [Development](#development-and-release-preparation) · [Documentation](#documentation) · [Help](#help) · [Licence](#licence)

## No dependencies

`ext-curl` is the only HTTP client PHP ships, so it is what the package uses
and there is nothing to install. CI asserts the package installs with zero
Composer packages and that the client, the proxy and webhook verification all
work in that state.

Everything else is a `suggest`, pulled in only if you use it:

| Package | Use it to |
| --- | --- |
| `psr/http-client` | route event reads through your own HTTP stack |
| `psr/http-server-middleware` | mount the proxy or agent sightings as PSR-15 middleware |
| `illuminate/http` | mount the proxy or agent sightings as Laravel middleware |
| `symfony/http-kernel` | mount the proxy or agent sightings as a Symfony kernel listener |
| `ext-apcu` | share the agent sightings cache and buffer between PHP-FPM workers |

A supplied client takes a PSR-17 `request_factory`, plus a `stream_factory`
wherever a body is sent. With Guzzle (`composer require guzzlehttp/guzzle`),
one object is both factories:

```php
// Redirects off and a deadline set: a supplied client owns both (see below).
$http = new \GuzzleHttp\Client(['allow_redirects' => false, 'timeout' => 5]);
$factory = new \GuzzleHttp\Psr7\HttpFactory();

$fingerly = new \Fingerly\Client(getenv('FINGERLY_SECRET_KEY'), [
    'http_client' => $http,
    'request_factory' => $factory,
    'stream_factory' => $factory,
]);
```

> PSR-18 has no way to express "do not follow redirects" or a deadline, so a
> supplied client owns both. The ext-curl path enforces them itself. If you
> supply a client, disable redirects on it: an authenticated request that
> follows one replays your secret key to another host.
>
> The `Proxy` takes no HTTP client. It always sends over ext-curl with
> redirects off, because a client that followed a redirect would replay the
> proxy key before any answer came back.

## Read events

Event reads require `fly_sk_{production|staging|development}_…` keys. Requests
go to `https://api.fingerly.io`; the `endpoint` option overrides that. The
secret key travels with every read, so `endpoint` must be a bare HTTPS origin,
`https://host` or `https://host:port`, with no credentials, path, query,
fragment, space or control character. Plain HTTP is accepted only for
`127.0.0.1`, `localhost` or `[::1]`, and only with
`'allow_insecure_loopback' => true`, for a local API or test harness. Public,
proxy, management, malformed and unsupported keys are rejected before network
access. Requests do not follow redirects. `get` and `list` each perform one
request and never retry.

Construct the client once and reuse it. Every option is validated in the
constructor, so an invalid key or endpoint throws before anything is sent. An
option this build does not know is refused rather than ignored, because a
silently dropped `timeout` or `endpoint` is exactly the kind of thing nobody
notices until it matters.

### Unscored events

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

### Read an event you just identified

An event is usually readable as soon as identification answers. If it is
still being recorded, the read throws an `ApiException` whose `isPending()` is
true and whose `getRetryAfter()` says when to read again:

```php
try {
    $event = $fingerly->events->get($requestId);
} catch (\Fingerly\ApiException $e) {
    if (!$e->isPending()) {
        throw $e;
    }
    // Not recorded yet, so nothing is decided: read again after
    // $e->getRetryAfter() seconds (2 when null), or verify asynchronously.
    $event = null;
}
```

Retry a pending read a bounded number of times, for example from a queued job.
`isEventNotFound()` means a request id that was never issued, or one past
retention, and is never pending.

The archived `document` arrives moments after the event itself, so check
`hasDocument` rather than assuming it is there, and read `$event->document`
(`?array`, holding allow-listed `risk`, `attestation`, `ip_network` and
`velocity` fields without raw signals, user agent or address). When a submission
is unidentifiable (`identifiable == false`, such as insufficient entropy, a
genuine embedded WebView, or an unmatched high-risk Web payload),
`$event->visitorId` is `''`; there are no `deferred_token`, `deferred_signals`
or `supplements` fields in `v: 3`.

Compare fields against the named constants rather than raw strings. Each is a
`final class` of `public const` wire strings, so
`\Fingerly\SuspectLevel::HIGH === $event->suspectLevel` is an ordinary string
comparison, and every field stays a `string`: a value the server adds later
arrives unchanged, and is only missing from the class's `ALL` list. They are
deliberately not backed enums: an enum case is an object, so comparing it with
`===` to a string field is always false, and `from()` throws on a value this
release has not heard of.

| Class | Field | Values |
| --- | --- | --- |
| `Signal` | `EventTrigger::$signal`, `$group` | every signal group and detector, such as `VPN`, `VPN_PUBLIC` and `FINGERPRINT_SUPPRESSED` |
| `SuspectLevel` | `Event::$suspectLevel`, webhook `$data['level']` | `LOW`, `MEDIUM`, `HIGH` |
| `Confidence` | `EventTrigger::$confidence` | `LOW`, `MEDIUM`, `HIGH` |
| `EventState` | `Event::$state` | `ENRICHED`, `UNAVAILABLE`, and `PENDING`, which a read throws as `isPending()` |
| `Environment` | `Event::$environment` | `PRODUCTION`, `STAGING`, `DEVELOPMENT` |
| `Platform` | `Event::$platform` | `WEB`, `ANDROID`, `IOS` |
| `AnonymityNetwork` | `Event::$anonymityNetwork` | `TOR`, `RELAY`, `VPN`, `PROXY`, `HOSTING` |
| `DeviceKind` | `Event::$deviceKind` | `DESKTOP`, `MOBILE`, `TABLET`, `SERVER` |
| `VisitorKind` | `Event::$visitorKind`, the `kind` filter, `AgentSighting::$visitorKind` | `HUMAN`, `AI_BOT`, `AUTOMATION`, `SEARCH_CRAWLER`, and `UNKNOWN` (only in an agent sightings answer: a request the server could not use) |
| `AiPurpose` | `AiAgent::$purpose`, the `ai_purpose` filter | `TRAINING`, `SEARCH`, `USER_FETCH`, `AGENT` |
| `AiRuntime` | `AiAgent::$runtime` | `PROVIDER_CLOUD`, `HOSTED_BROWSER`, `USER_DEVICE` |
| `AiVerificationMethod` | `AiVerification::$method` | `SIGNATURE`, `IP_LIST`, `REVERSE_DNS`, `NONE` |
| `AiVerificationResult` | `AiVerification::$result` | `PASS`, `FAIL`, `NOT_ATTEMPTED` |
| `AttestationState` | `$event->document['attestation']['state']` | `ATTESTED`, `NOT_ATTESTED`, `FAILED` |
| `AttestationStatus` | `$event->document['attestation']['status']` | `VERIFIED`, `INDETERMINATE`, `INVALID`, `ABSENT`, `UNCONFIGURED` |
| `AttestSkippedReason` | `$event->document['attestation']['attest_skipped']` | `NOT_OFFERED`, `UNSUPPORTED`, `CHALLENGE_UNAVAILABLE`, `KEY_ERROR`, `TIMEOUT`, `BACKOFF`, `INSTALL_UNAVAILABLE` |
| `VerifyReason` | `EventVerification::$reason` | `OK` (`''`) and the failures in [Verify a backend action](#verify-a-backend-action) |
| `WebhookEventType` | `WebhookEvent::$type` | see [Verify webhooks](#verify-webhooks) |
| `WebhookEnvironment` | `WebhookEvent::$environment` | `LIVE`, `TEST` |

All live in the `Fingerly` namespace. A constant's name is its value
upper-cased, with `.` as `_` (`VerifyReason::OK` is `''`). Event reads report
each trigger under its signal group (`Signal::VPN`), while `visitor.suspect`
webhooks and the archived document's `risk.triggers` carry the detector
(`Signal::VPN_PUBLIC`) with its group beside it. The `SIGNAL_*` constants on
`EventTrigger`, its `V3_SIGNALS` list and the `REASON_*` constants on
`EventVerification` are the same strings.

`Event::$projectId` names the Fingerly project the event belongs to, as a UUID
string. It is `null` when the server is older than projects.

`Event::$ai` is set only when `Event::$visitorKind` is `VisitorKind::AI_BOT`,
and `null` otherwise: an `AiAgent` with the agent's `name`, `provider`,
`purpose`, `runtime`, `declared` and `verification` (`method`, `result`). The
agent is named by a Web Bot Auth signature when the request carried one,
otherwise by its user agent. `verification` says how far that was checked:
`AiVerificationMethod::SIGNATURE` (the operator's published key signed the
request) or `AiVerificationMethod::IP_LIST` (the address is inside the
operator's published ranges), with result `AiVerificationResult::PASS`,
`AiVerificationResult::FAIL` (the name was borrowed), or
`AiVerificationResult::NOT_ATTEMPTED` with method `AiVerificationMethod::NONE`
(the operator publishes nothing to check against, or it could not be checked;
the name is then only the client's claim). Only clients that run the Fingerly
SDK produce events, so crawlers that do not run JavaScript never appear. `events->list()`
filters on `kind`, `ai_provider` (exact and case-sensitive, such as `OpenAI`)
and `ai_purpose`.

```php
$fetches = $fingerly->events->list([
    'kind' => \Fingerly\VisitorKind::AI_BOT,
    'ai_purpose' => \Fingerly\AiPurpose::USER_FETCH,
]);
```

### Verify a backend action

Send the request id from the browser or app to your backend, and verify it
there with a server-only key. Do not authorize an action using scores or
visitor identifiers supplied by the browser.

`$fingerly->events->verify()` reads the event and runs these checks in order,
stopping at the first that fails. `$result->reason` names it, as one of the
`\Fingerly\VerifyReason` constants:

1. `not_found`: there is no such event. `$result->event` is null only here.
2. `too_old`: it happened more than `max_age_seconds` ago (300 by default), or
   its time cannot be read.
3. `tag_mismatch`: you passed `tag` and the event's tag is not exactly it. An
   untagged event never matches.
4. `unidentifiable`: the event's `visitorId` is `''`.
5. `unscored`: the event has no suspect score.
6. `already_consumed`: another call already consumed the event.

When every other check passes, `verify` consumes the event last, so one event
backs one decision: a replayed request id answers `already_consumed`. Pass
`'consume' => false` to check without consuming. Then `$result->ok` is true
and `$result->reason` is `''`.

`verify` never judges the score, the level or the triggers. Your policy
decides what they mean for the action, after `ok`:

```php
$result = $fingerly->events->verify($requestId, [
    'tag' => 'checkout',
    'max_age_seconds' => 300,
]);
$event = $result->event;
if (!$result->ok || null === $event) {
    return 'refuse'; // $result->reason says why
}
if (\Fingerly\SuspectLevel::HIGH === $event->suspectLevel) {
    return 'review';
}
foreach ($event->triggers as $trigger) {
    if (\Fingerly\Signal::ATTESTATION_FAILED === $trigger->signal) {
        return 'refuse';
    }
}
```

A pending event (`isPending()`) is not a verdict, so `verify` throws it as an
`ApiException`, along with every other error: retry after `getRetryAfter()`
seconds or verify asynchronously, rather than refusing the action. An unknown
option, a `max_age_seconds` that is not a positive integer, an empty `tag` or a
`consume` that is not a boolean throws `ConfigurationException` before anything
is sent.

`$fingerly->events->consume($requestId)` consumes an event on its own. The
first call returns an `EventConsumption` whose `first` is true; any later call
throws `\Fingerly\EventAlreadyConsumedException`, an `ApiException`
(`409 event_already_consumed`, `isAlreadyConsumed()`) whose `getConsumedAt()`
says when. It is never retried.

Tags correlate events; they do not replace your application's authentication
or replay protection.
Never put a personal identifier (user ID, email, install ID, advertising ID) in
a tag: the server keeps tags in long-lived rollups.

### Errors

Everything thrown implements `\Fingerly\FingerlyException`, so one `catch`
handles the lot, and the concrete classes tell them apart:

| Class | When |
| --- | --- |
| `ConfigurationException` | An option was rejected. Nothing was sent. It is an `InvalidArgumentException`, because a key that was never valid will not become valid on a retry. |
| `ApiException` | The API answered with a non-2xx status, a redirect included. |
| `EventAlreadyConsumedException` | An `ApiException` for `409 event_already_consumed`: the event was already consumed, at `getConsumedAt()`. |
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

A read answers `404` with one of two codes, each with its own predicate:

| Predicate | Code | Meaning |
| --- | --- | --- |
| `$e->isPending()` | `event_pending` | The request id was accepted and the event is still being recorded. Nothing is decided yet: read again after `getRetryAfter()` seconds (the API sends `Retry-After: 2`), or verify asynchronously. Do not treat it as fraud. |
| `$e->isEventNotFound()` | `event_not_found` | The request id was never issued, or the event is past retention. |

```php
try {
    $event = $fingerly->events->get($requestId);
} catch (\Fingerly\ApiException $e) {
    if ($e->isPending()) {
        // Not decided yet: read again after $e->getRetryAfter() seconds.
    } elseif ($e->isEventNotFound()) {
        // Never issued, or past retention.
    } else {
        throw $e;
    }
}
```

### Deadlines and connections

`timeout` bounds the whole exchange, including receiving the body, so a server
that answers and then stalls, or sends a byte at a time, times out like one
that never answered. It defaults to 5 seconds, for reads and for the proxy.
cURL's `CURLOPT_TIMEOUT_MS` covers the transfer end to end. A response body
over 4 MiB is refused rather than buffered.

A client or proxy keeps one cURL handle and reuses its connection, so a
long-running worker (Octane, FrankenPHP, RoadRunner) opens one connection to
Fingerly and keeps it. On PHP 8.5 and later the connection, DNS and TLS session
caches are also shared across the requests a PHP-FPM worker serves, so a client
built per request reuses them too. On PHP 8.2 to 8.4 under PHP-FPM each request
that builds a new client opens a new connection; at a few hundred proxied
requests a second per host, run PHP 8.5 or a worker runtime. No cookies are
kept, and redirects are never followed.

## Apple DeviceCheck bits

For an iOS identification whose App Attest evidence carried a DeviceCheck
token, Fingerly holds two tokens for one hour, one to read and one to write
the device's two Apple DeviceCheck bits, and your backend spends each once.
The bits mean what you decide; Fingerly never reads them.

Apple treats each token as single-use, so one identification supports one
read and one write. A "check, then mark" flow such as a once-per-device free
trial does both on the identification that asks for the trial:

1. Query the bits. If they say the device already had a trial, refuse it.
2. Otherwise record the grant in your own data, then write the bit on the same
   identification. Until the write lands, your own record is what stops a
   second trial.

```php
try {
    $bits = $fingerly->deviceCheck->query($requestId);
    $usedOnDevice = $bits->found && $bits->bit0;
} catch (\Fingerly\ApiException $e) {
    if (!$e->isDeviceCheckNotHeld() && !$e->isDeviceCheckSpent()) {
        throw $e;
    }
    $usedOnDevice = false; // no answer, not a verdict
}

if (!$usedOnDevice) {
    // Grant the trial in your own data, then mark the device.
    $fingerly->deviceCheck->write($requestId, ['bit0' => true]);
}
```

A bit left out of a write keeps its value; a write must name `bit0`, `bit1` or
both, or it throws `ConfigurationException` before anything is sent.

A `503 device_check_unavailable`, a timeout or a connection failure keeps the
token, and the SDK sends the identical request again, twice at most. Nothing
else is retried. Apple allows a token to be retried only for the same request,
so if you retry yourself, repeat the same call with the same bits: a second
read, or a write with other bits, on that event answers `409 device_check_spent`.

`ApiException` tells the answers apart: `isDeviceCheckNotHeld()` (`404`),
`isDeviceCheckSpent()`, `isDeviceCheckBusy()` and `isCredentialUnavailable()`
(`409`), `isBitsRequired()` and `isAppleRejected()` (`422`, with Apple's status
in `getAppleStatus()`), and `isDeviceCheckUnavailable()` (`503`). Apple asks
that DeviceCheck is never the only fraud check, and that users have a way to
have the bits reset.

## Verify webhooks

The call is in [Getting started](#getting-started). Verify the exact raw bytes
before decoding them: re-serialising them changes the digest.

| Behaviour | Detail |
| --- | --- |
| What a signature covers | the timestamp and the body |
| Replay tolerance | five minutes by default, changed with the `tolerance` argument |
| Secret rotation | any matching signature in the comma-separated header is accepted |
| Malformed or missing input | a `false` result, never an exception |
| Deduplication | verification proves authenticity and freshness only; persist processed `WebhookEvent::$id` values as well |
| Bad payload | `Webhook::parse()` throws `WebhookException`, which names the field at fault and never its value |

`Webhook::parse()` decodes the envelope every delivery shares and leaves `$data`
an array, so a new event type needs no upgrade. Dispatch on `$event->type` with
`\Fingerly\WebhookEventType`:

```php
use Fingerly\WebhookEventType;

match ($event->type) {
    WebhookEventType::VISITOR_SUSPECT => ReviewVisitor::dispatch($event->data['request_id']), // high level only
    WebhookEventType::IDENTIFICATION_COMPLETED,
    WebhookEventType::IDENTIFICATION_REFUSED => RecordIdentification::dispatch($event->id),
    WebhookEventType::WEBHOOK_TEST => null, // sent to one endpoint on request; nobody subscribes to it
    default => null, // BILLING_STATUS_CHANGED, USAGE_DAILY_SETTLED, or a type added later
};
```

The other types are `BILLING_STATUS_CHANGED` (`billing.status_changed`) and
`USAGE_DAILY_SETTLED` (`usage.daily_settled`). `identification.completed` and
`visitor.suspect` carry `$data['project_id']`; `identification.refused` carries
it only when the refused request named a known key.

Webhook verification touches neither the network nor ext-curl, so a receiver
that does nothing else pulls in nothing else.

## First-party proxy (same-origin)

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

| Framework | Adapter | Install |
| --- | --- | --- |
| Slim, Mezzio, anything PSR-15 | `\Fingerly\Proxy\Psr15Middleware` | `psr/http-server-middleware` |
| Laravel | `\Fingerly\Proxy\LaravelMiddleware` | `illuminate/http` |
| Symfony | `\Fingerly\Proxy\SymfonyListener` | `symfony/http-kernel` |
| Anything else | `$proxy->handle()` | nothing |

Every adapter shares the same `Proxy` and the same `client_ip` rule below. Pick
yours:

<details>
<summary><b>Slim, Mezzio, anything PSR-15</b></summary>

```php
$app = \Slim\Factory\AppFactory::create();
// ... your routes and other middleware ...
// Added last, so it runs first.
$app->add(new \Fingerly\Proxy\Psr15Middleware($proxy, $app->getResponseFactory()));
```

</details>

<details>
<summary><b>Laravel</b></summary>

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

Under Octane, also add both classes to `warm` in `config/octane.php`. Octane
gives every request a fresh copy of the container, so a singleton that is not
warmed is built again on each request, with a new cURL handle, and on PHP 8.2
to 8.4 a new connection to Fingerly per proxied request:

```php
// config/octane.php
'warm' => [
    ...Octane::defaultServicesToWarm(),
    \Fingerly\Proxy::class,
    \Fingerly\Client::class,
],
```

Laravel reads and decodes a JSON body when it builds the request, before any
middleware runs, so under Laravel the 1 MiB limit refuses an oversized body but
cannot stop it being read into memory first. Cap the body size in the web
server as well: `client_max_body_size` in nginx, `request_body { max_size }` in
Caddy and FrankenPHP.

</details>

<details>
<summary><b>Symfony</b></summary>

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

</details>

<details>
<summary><b>Any other framework</b></summary>

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

</details>

Then point the browser SDK at the same path:

```ts
const fingerly = await load({ apiKey: 'fly_pk_…', endpoints: '/k7x2qm4a' })
```

Client SDKs post a single identification envelope to exactly that path, with
nothing appended.

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

### Event loops and worker sizing

The proxy and the client are synchronous: each call holds the calling thread
for up to `timeout` while Fingerly answers. That is right for PHP-FPM, Octane,
FrankenPHP and RoadRunner, where a worker serves one request at a time. Never
call them from an event loop (ReactPHP, AMPHP, Revolt, or Swoole and
OpenSwoole coroutines): one slow answer would stall every request that loop is
serving. Hand the call to a worker process or a thread pool instead.

Size the worker pool for the slow case, not the average one: while Fingerly is
slow, every proxied request holds its worker for the whole `timeout`. At 100
proxied requests a second with 1% of them running into a 5 second timeout, that
is 5 workers busy on average, before any fast request is served. Run more
workers than that, or lower `timeout` for the proxy.

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
Under Laravel and Symfony the path is matched against the application-relative
path, `getPathInfo()`, never the whole request URI, because a deployment under
a sub-URI carries a prefix the configuration knows nothing about, and a
spelled-out front controller such as `/index.php/k7x2qm4a` is not the path.
Under PSR-15 it is matched against the request path exactly as the client sent
it (`REQUEST_URI` without the query string, else the PSR-7 URI path), because
some PSR-7 implementations normalise the URI they build. Add the PSR-15
middleware to the whole application or to that one route, not under a path
your router strips.

Use the path the dashboard issued. Blockers look for words such as
`fingerprint`, `tracking`, `analytics`, `collect`, `identify` and `fingerly`.

### What the proxy does

| Rule | Behaviour |
| --- | --- |
| Methods and paths | It forwards only `POST` (plus `OPTIONS` when `allowed_origins` is configured), matched exactly: any other method, `post` included, gets `405`, and a path that is not the proxy's (including `/supplement` subpaths) gets `404` first. |
| Upstream | Every accepted request goes verbatim as raw bytes to one URL, `https://api.fingerly.io/api/v1/ingest`. `api_version` changes the version in that URL and nothing else, and `upstream` its origin. |
| Upstream origin | `upstream` must be a bare HTTPS origin, `https://host` or `https://host:port`, with no credentials, path, query, fragment, space or control character. Plain HTTP is accepted only for `127.0.0.1`, `localhost` or `[::1]`, and only with `'allow_insecure_loopback' => true`, for a local test harness. The proxy key travels with every forwarded request, so anything else is refused when the `Proxy` is constructed. |
| Event reads | Need a secret key and stay unreachable from a browser by construction. |
| Public key | The caller must present a public key from the same environment as the proxy key, or the request gets `401`. |
| Origin or platform | Requests without either `Origin` or a validated native `X-Fingerly-SDK-Platform` header (`ios`, `android`, `react-native`, `react-native-ios`, `react-native-android`, `flutter`, `flutter-ios`, `flutter-android`, `lynx-ios`, `lynx-android`, `capacitor`, `kotlin-multiplatform`) are rejected locally with `400` (`missing_origin`), and `x-fingerly-origin` is never synthesized from `Host`, `Referer` or the request URL. |
| Body size | Bodies over 1 MiB get `413`. |
| Deadline | One 5 second deadline covers the whole exchange, so an upstream that never answers, or answers a byte at a time, gets `504 Gateway timeout` rather than holding the browser's connection open. |
| Upstream failures | An upstream that cannot be reached, drops the connection, answers a redirect or a status outside 200 to 599, or answers a success that is not JSON gets `502 Bad gateway`; a redirect is never followed and its `Location` never relayed. |
| Error answers | `504` and `502` are short `text/plain` answers marked `no-store`, and the proxy never throws, so the framework never renders its own error page in their place. |
| Forwarded upstream | All incoming `x-fingerly-*`, `cookie` and `authorization` headers are stripped. `x-fingerly-host`, `idempotency-key`, `sec-fetch-*`, `sec-ch-ua-*` and the Web Bot Auth `signature`, `signature-input` and `signature-agent` (each dropped when over 4096 bytes) are forwarded, and one channel header: `x-fingerly-origin` when the request has an `Origin`, otherwise the validated `x-fingerly-sdk-platform`. A request with both is a browser request, as it is when sent to the API directly. |
| Proxy identity | The proxy reports itself upstream as `php/0.5.0`, which the dashboard shows per proxy. |
| Relayed back | Only `Content-Type`, `X-Request-Id`, `RateLimit-Limit`, `RateLimit-Remaining` and `Retry-After`, plus `Cache-Control: no-store` set by the proxy. It is an allowlist, so nothing on the response names Fingerly, whatever the API adds in future. |
| Laravel and Symfony | The relayed `Cache-Control: no-store` arrives as `no-store, private`, because `ResponseHeaderBag` appends a visibility directive to any `Cache-Control` without one. That is a narrowing rather than a weakening, so it is left alone. |

Pass `on_error` to hear about `504` and `502` answers, one line each. A line
names the kind of failure (`connect`, `dns`, `tls`, ...), never the upstream
host, the URL or a key:

```php
'on_error' => fn (string $line) => \Illuminate\Support\Facades\Log::warning($line),
```

## AI crawlers on your server

Most AI crawlers and fetchers (GPTBot, ClaudeBot and the like) read your pages
without running any JavaScript, so the browser SDK never sees them.
`\Fingerly\AgentSightings` reports the requests your server served to
Fingerly, which answers what sent each one, and remembers those answers so
your code can ask about a request without waiting on the network. The console
counts the traffic either way.

The SDK decides nothing about the visitor. It never classifies a user agent,
never checks an address against a list and never filters by what a visitor
claims to be: Fingerly decides, and the SDK forwards raw input and remembers
the answer. What it does choose is which of your own responses are worth
reporting.

```php
$sightings = new \Fingerly\AgentSightings([
    'secret_key' => getenv('FINGERLY_SECRET_KEY'),
    // The same trusted source as the proxy's client_ip, never X-Forwarded-For.
    'client_ip' => fn (\Fingerly\Proxy\ProxyRequest $r) => $r->header('cf-connecting-ip') ?: $r->remoteAddress,
]);
```

Build it once per process and share it, then mount the adapter for your
framework. Each one reports after your application answered and hands the
response back untouched:

```php
// Laravel: one instance per process, bound in a service provider's register()
$this->app->singleton(\Fingerly\AgentSightings::class, fn () => new \Fingerly\AgentSightings([
    'secret_key' => config('services.fingerly.secret_key'),
    'client_ip' => fn (\Fingerly\Proxy\ProxyRequest $r) => $r->header('cf-connecting-ip') ?: $r->remoteAddress,
]));

// Laravel, in bootstrap/app.php
$middleware->append(\Fingerly\AgentSightings\LaravelMiddleware::class);

// Slim, Mezzio and other PSR-15 stacks
$app->add(new \Fingerly\AgentSightings\Psr15Middleware($sightings));
```

Under Octane, pass `'long_running' => true` and add
`\Fingerly\AgentSightings::class` to `warm` in `config/octane.php`, next to the
proxy and the client. A singleton that is not warmed is built again for every
request, so it would remember nothing and report nothing.

```yaml
# Symfony, config/services.yaml, with the ClientIp class from the proxy section
    fingerly.agent_sightings:
        class: Fingerly\AgentSightings
        arguments:
            - secret_key: '%env(FINGERLY_SECRET_KEY)%'
              client_ip: ['@App\Fingerly\ClientIp', '__invoke']
              on_error: ['@logger', 'warning']

    Fingerly\AgentSightings\SymfonyListener:
        arguments: ['@fingerly.agent_sightings']
```

Laravel reports from its `terminate()` hook and Symfony on `kernel.terminate`,
both after the response was sent. A framework with none of these calls
`$sightings->report($request, $contentType)` once per response, with a
`ProxyRequest` such as `ProxyRequest::fromGlobals()`.

### Ask about a request

`lookup()` answers from memory only. It never waits on the network and never
throws. Until Fingerly has answered for the same user agent, address and
`Signature-Agent`, and again once that answer's `ttl` has passed, it returns
the one shared `AgentSighting::unknown()` answer (no agent, no `ai`, an empty
`visitorKind`): serve such a request as usual.

```php
use Fingerly\AgentSighting;
use Fingerly\AiPurpose;
use Fingerly\VisitorKind;

$answer = $sightings->lookup(\Fingerly\AgentSightings\LaravelMiddleware::toProxyRequest($request));

if (AgentSighting::unknown() === $answer) {
    // Not answered yet: serve as usual.
} elseif (VisitorKind::AI_BOT === $answer->visitorKind && AiPurpose::TRAINING === $answer->ai?->purpose) {
    // Fingerly said an AI training crawler sent this request ($answer->ai->provider, $answer->agent).
}
```

Each adapter also has `lookup($request)` for its own request type. The `ai`
object is the one events carry, with the same `AiPurpose`, `AiRuntime`,
`AiVerificationMethod` and `AiVerificationResult` constants. An agent's name
comes from what it sent, and `ai->verification` says how far that claim was
checked: `PASS` means the operator's key or published ranges confirmed it,
`FAIL` means the name was borrowed, and `NOT_ATTEMPTED` leaves it the
client's claim. A signed request (Web Bot Auth) is
checked every time and never remembered, so `lookup()` answers unknown for it.

### What is reported

By default, every response with a `Content-Type` of `text/html` or
`application/xhtml+xml`, and every request for `/robots.txt`, `/llms.txt` and
`/llms-full.txt`, whatever their status. Static assets, API routes and health
checks are not. Paths are compared without the query string: the
application-relative `getPathInfo()` under Laravel and Symfony, the PSR-7 URI
path under PSR-15.

| Option | Effect |
| --- | --- |
| `content_types` | The response media types reported, replacing the default list; parameters such as `charset` are ignored. |
| `include_path_prefixes` | Paths reported whatever their content type. |
| `exclude_path_prefixes` | Paths never reported, `/robots.txt` and the other two included; an exclusion wins. |

For each reported request, exactly these are sent: the `User-Agent` as
received, the client address from your `client_ip` callback, `Host`, the
method, and the Web Bot Auth `Signature`, `Signature-Input` and
`Signature-Agent` headers when present, plus a count. Never the path, the
query string, cookies, `Authorization`, any other header, the body or the
response, and the SDK logs none of them. A request whose `User-Agent` or
method is over 4096 bytes, or whose `client_ip` callback gives no single
address, is not reported; a `Host` or Web Bot Auth header over 4096 bytes is
left out. Lengths are counted as Fingerly reads them, where each byte that is
not UTF-8 arrives as a three-byte U+FFFD. An answer item the SDK cannot read
leaves that one request unknown. So does a request Fingerly could not use: it
is answered `VisitorKind::UNKNOWN` with a `ttl` of 0, so it is never
remembered, and the rest of the batch is answered as usual.

### Memory and sending

| | |
| --- | --- |
| Answers remembered | 4096 by default (`capacity`; a larger value is held to 65536), least recently used dropped first, each for the `ttl` Fingerly gave, a day at most. About 1.5 MB at the default, about 25 MB at the most. When Fingerly's rules change, every remembered answer is dropped. |
| Pending reports | At most 1000, the batch being sent included. Repeats of the same request are one report with a count, cache hits included, so the console counts them; signed requests are never folded. When the buffer is full, a new request is not reported and `droppedCount()` goes up. |
| Sending | Up to 200 reports per call, when 200 or 64 KiB are pending or 2 seconds after the oldest, at most one call at a time, each with a 5 second deadline. A due send after a response makes one call, so a slow API holds a worker for one deadline at most; `flush()` starts no call after 10 seconds. Never on the request being served. |
| Failures | Requests are served as usual and `lookup()` keeps answering. A rate limit keeps the reports until `Retry-After` has passed, a second at least and a day at most; a wrong or revoked key drops them and pauses sending for a minute; a `503` or a network error before any answer keeps them and retries with backoff from 1 to 60 seconds; a call that timed out, or whose connection failed, after it was sent is dropped rather than counted twice, and sending backs off the same way; a `422` (no item in the batch was usable) and any other answer drop them. A failure is logged at most once a minute, and no log line holds a reported field. |

Under **PHP-FPM** the cache and the pending reports live in APCu, shared by
the pool's workers and held to the same bounds. The request that makes a send
due registers it to run after `fastcgi_finish_request()` (or LiteSpeed's
equivalent), after every shutdown function of your own, and one worker at a
time sends, under an `apcu_add` lock. Without APCu, and without
`long_running`, nothing is reported, every `lookup()` answers unknown, and that
is logged once. APCu is approximate about recency: an answer in use is kept by
storing it again, rather than by an exact LRU. The pending reports live as long
as the pool: a restart of PHP-FPM loses those not sent yet.

The built-in server (`php -S`, `php artisan serve`) and Apache's module keep
the response open until the script ends, so nothing is sent after a response
there, and that is logged once; reports are sent only by an explicit
`flush()`. Run PHP-FPM, LiteSpeed or a long-running server to report.

On a **long-running server** (Octane, RoadRunner, Swoole, FrankenPHP worker
mode, a queue worker) pass `'long_running' => true`: both live in the process,
and the Laravel and Symfony adapters send due reports from their terminate
hooks. Elsewhere, call `$sightings->flushIfDue()` after each response is
written, for example after RoadRunner's `respond()`. A PHP process has no
timer, so the 2 second rule is checked when the next response is reported.
A `long_running` instance is closed when the process exits normally, which is
how these servers stop a worker, so what it still holds is sent then.

`$sightings->flush()` sends everything pending now, for a script or a worker
that is about to exit, and `$sightings->close()` flushes once more and stops
reporting. Neither throws. Log lines go to `on_error` when given, otherwise
to `error_log()`.

The low-level call is `$fingerly->agents->reportSightings([...])` on the
`Client`, taking `AgentSightingReport` items and returning the answers in the
same order, for a setup that cannot use `AgentSightings`.

## Requirements

- PHP 8.2 or newer, and nothing outside `ext-curl` and `ext-json`.
- Run a PHP release that has the October 2026 security fixes for CVE-2026-109618
  and CVE-2026-109619: 8.5.12, 8.4.27, 8.3.36, 8.2.35 or later. Those flaws are
  in PHP itself and let an unauthenticated request run code on the server, so an
  unpatched runtime puts everything on it at risk, this SDK's secret key
  included.
- CI runs PHP 8.2, 8.3, 8.4 and 8.5, and tests the adapters against Laravel 10
  to 13 and Symfony 6.4 to 8.

## Development and release preparation

<details>
<summary>Commands, tests, source structure</summary>

```sh
composer install
composer test           # phpunit
composer stan           # phpstan, level 8, checked against the 8.2 floor
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

### Source structure

`Client.php` holds the client and its options. `Transport.php` performs one
HTTP exchange over a reused cURL handle or a supplied PSR-18 client, caps the
response body, and maps failures to `ApiException`. `Secret.php` holds a key
so that no dump or serialisation can show it. `Events.php` owns the event reads, the list filters, verification and
consumption, decoding into `EventVerification.php` and `EventConsumption.php`
and throwing `EventAlreadyConsumedException.php`, and `DeviceCheck.php`
the DeviceCheck reads and writes, decoding into `DeviceCheckBits.php` and
`DeviceCheckWrite.php`;
`Event.php`, `EventPage.php`, `EventTrigger.php`, `AiAgent.php`,
`AiVerification.php` and `WebhookEvent.php` are what they decode into, and `Signal.php`,
`SuspectLevel.php` and the other constant classes name the closed string sets
their fields hold. `Json.php` reads those
bodies without trusting their shape. `Webhook.php` verifies signatures and
needs nothing else in the package; `WebhookException.php` is what `parse()`
throws for a payload it cannot read. `Proxy.php` is the same-origin core, with
`Proxy/` holding its request and response types and the three adapters.
`Agents.php` is the low-level sightings call, decoding into
`AgentSightingsAnswer.php` and `AgentSighting.php`; `AgentSightings.php`
batches, remembers and flushes, with `AgentSightings/` holding the cache key
(`Sighting.php`), the in-memory and APCu stores and its three adapters.
`Config.php` validates every caller-supplied option: keys, origins, API
versions, the proxy path, durations and sizes, all before any network access.

</details>

## Documentation

- [PHP SDK guide](https://docs.fingerly.io/docs/sdks/php)
- [Webhooks](https://docs.fingerly.io/docs/webhooks)
- [Proxy integrations](https://docs.fingerly.io/docs/proxy-integrations)
- [API reference](https://docs.fingerly.io/reference/overview)

## Help

Open an issue on [this repository](https://github.com/fingerly-io/sdk-php/issues), or write to
[support@fingerly.io](mailto:support@fingerly.io). Report security issues to
[security@fingerly.io](mailto:security@fingerly.io).

## Licence

Apache-2.0. See [LICENSE](https://github.com/fingerly-io/sdk-php/blob/main/LICENSE).
