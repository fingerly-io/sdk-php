# Changelog

## 0.5.1

- Names the Android `bootloader_unlocked` signal and its group.

## 0.5.0

- Added `\Fingerly\AgentSightings`, which reports the requests your server
  served so AI crawlers and fetchers that never run JavaScript are seen, and
  answers `lookup()` from what Fingerly said about each one, never waiting on
  the network and never throwing. Mount `AgentSightings\Psr15Middleware`,
  `AgentSightings\LaravelMiddleware` or `AgentSightings\SymfonyListener`.
  Under PHP-FPM the answers and pending reports live in APCu, shared by the
  pool, and are sent after `fastcgi_finish_request()`; on Octane, RoadRunner,
  Swoole or FrankenPHP worker mode pass `'long_running' => true`. The
  low-level call is `$fingerly->agents->reportSightings()`, with
  `AgentSightingReport`, `AgentSightingsAnswer` and `AgentSighting`.
- `Event::$ai` names the AI agent behind an `ai_bot` event as an `AiAgent`
  (name, provider, purpose, runtime, whether it declared itself, and an
  `AiVerification` of how far its claim was checked), with the `AiPurpose`,
  `AiRuntime`, `AiVerificationMethod` and `AiVerificationResult` constants.
- `events->list()` filters on `kind`, `ai_provider` and `ai_purpose`.
- `Signal::AI_SPOOFED` and `Signal::IDENTITY_OBFUSCATED` were added;
  `Signal::VPN_TIMEZONE`, which the server no longer sends, was removed.
- The proxy forwards the Web Bot Auth `Signature`, `Signature-Input` and
  `Signature-Agent` headers, each at most 4096 bytes.
- `ApiException::getRateLimitScope()` names the limit behind a `429`
  (`ApiException::RATE_LIMIT_SCOPE_DAILY` for the daily allowance).
  `TimeoutException::$requestSent` and `ConnectionException::$requestWritten`
  say whether the API may have acted on the request.
- Reporting never holds a response open: the Laravel and Symfony adapters
  send from their terminate hooks only on a long-running server, so the
  built-in server and Apache's module never wait for Fingerly, and the send
  after a PHP-FPM response runs after your own shutdown functions.
- A slow or silent API holds a worker for one 5 second deadline at most: a
  due send makes one call, `flush()` starts none after 10 seconds, and a
  report that timed out after it was sent backs off as other failures do.
- A `429` pauses reporting for its `Retry-After`, held between a second and
  a day, so a far-future value no longer stops reporting for the life of the
  process or of the PHP-FPM pool.
- Reporting under PHP-FPM recovers on its own when an APCu entry is lost,
  for example after a day without traffic, and APCu entries are kept apart
  per SDK version, so an upgrade never reads entries an older version wrote.
- A `long_running` instance is closed when the process exits normally, so a
  worker that stops gracefully sends the reports it still holds.
- A sightings header is measured as the API reads it: one that is not UTF-8
  travels as U+FFFD, three bytes a byte, so a user agent under 4096 bytes
  here but over them there no longer makes the API refuse the whole batch.
- A sightings `Host` over 4096 bytes as the API reads it is left out of the
  item, and a method over 4096 bytes is not reported, so no item can make the
  API refuse its batch.
- An answer item the SDK cannot read leaves only that request unknown; the
  other answers of the batch are kept in their positions.
- `VisitorKind::UNKNOWN` names the answer for a reported request the API
  could not use. It comes with a `ttl` of 0, so it is never remembered and
  that request stays unknown; the rest of the batch is answered as usual. A
  `422` now means no item in the batch was usable.
- The send after a PHP-FPM response writes an open PHP session first, so the
  visitor's next request no longer waits on its lock for the send.
- `AgentSightings\Psr15Middleware` sends `Host` as the client sent it, port
  included, as the Laravel and Symfony adapters do; slim/psr7 rebuilt it
  from the URI without the port.
- Options that were misread or ignored are refused: a non-string
  `api_version`, `max_body_bytes` of `PHP_INT_MAX` (which made the proxy
  throw), an `allowed_origins` entry that is not an origin (a trailing slash
  and capitals are normalised), `http_client`, `request_factory` or
  `stream_factory` that is not its PSR type, and an `events->list()` filter
  that is not a string. A key passed in the wrong option stays out of
  exception traces.
- A `POST` without a body (`consume`, the DeviceCheck query) carries
  `Content-Length: 0`.
- The README and `DeviceCheck` describe one read and one write token per
  identification: a check-then-mark flow reads and writes on the same
  request id.
- The README's verify sample checks `$result->event` for null, and the
  sightings setup shows the Laravel binding, Octane's `warm` list and the
  Symfony service.

## 0.4.1

- Each `AttestSkippedReason` constant carries a description of when the SDK
  reports it, on iOS and on Android.
- The vendored OpenAPI contract snapshot (`contract/openapi.json`) declares
  the closed string sets as enums, matching the server.

## 0.4.0

- Added named constants for the closed string sets the API sends, each a
  `final class` of `public const` wire strings with an `ALL` list:
  `Signal` (every signal group and detector), `SuspectLevel`, `Confidence`,
  `EventState`, `Environment`, `Platform`, `AnonymityNetwork`, `DeviceKind`,
  `VisitorKind`, `AttestationState`, `AttestationStatus`,
  `AttestSkippedReason`, `VerifyReason`, `WebhookEventType` and
  `WebhookEnvironment`. Fields stay strings, so
  `SuspectLevel::HIGH === $event->suspectLevel` is a plain comparison and a
  value added later still arrives. The `EventTrigger::SIGNAL_*` and
  `EventVerification::REASON_*` constants now refer to the same strings.
- `Event::$anonymityNetwork` documents `relay`, and `Event::$visitorKind`
  no longer names a `bot` value the server never sends.

## 0.3.0

- Synced the v3 single-call ingest contract snapshot (`openapi.json` and
  `fixtures.json`), removing the retired supplement endpoint and
  `deferred_token`.
- Added `Event::$reason`, v3 `EventTrigger` anti-spoofing signal constants
  (`attestation_failed`, `attestation_downgrade`, `device_inconsistent`,
  `report_incomplete`, `probe_errors`, `fetch_metadata_missing`,
  `web_ticket_missing`, `web_ticket_invalid`, `tampering`, `custom_root_ca`,
  `secondary_user_profile`), optional
  `EventTrigger::$group`, and empty `Event::$visitorId` (`""`) support for
  unidentifiable events.
- Enforced the v3 first-party proxy relay contract: sanitize-and-restore of
  validated `x-fingerly-sdk-platform`, `400 missing_origin` when both `Origin`
  and a validated native platform header are absent (never synthesizing
  `x-fingerly-origin`), forwarding `x-fingerly-host`, `sec-fetch-*` and
  `sec-ch-ua-*`, and optional `allowed_origins` CORS preflight handling.
- `Proxy` authenticates with `proxy_key` only and checks the browser's public
  key on every request; a secret key is never sent upstream.
- `$fingerly->deviceCheck->query($requestId)` and
  `$fingerly->deviceCheck->write($requestId, ['bit0' => true])` read and write
  the Apple DeviceCheck bits of an iOS event, retrying only a `503` or a
  transport failure with the identical request. `ApiException` gains a
  predicate per DeviceCheck error code and `getAppleStatus()`.
- Added `$fingerly->events->verify($requestId, $options)`, which reads an event
  and checks it exists, is recent (`max_age_seconds`, 300 by default), carries
  the expected `tag`, is identifiable and scored, then consumes it once, and
  `$fingerly->events->consume($requestId)` with a typed
  `EventAlreadyConsumedException` for `event_already_consumed`.

## 0.2.0

- `Event::$projectId` exposes the Fingerly project an event belongs to, on
  single reads and listing rows. It is `null` when the server does not send
  it. Webhooks carry it as `$data['project_id']`.
- `Event::$ipAddress` is gone; the API no longer returns the address. The new
  `Event::$ipNetwork` (`?string`) holds the network the request came from: an
  IPv4 /24 or an IPv6 /40, such as `203.0.113.0/24`.
- `Event::$deferredSignals` is gone; the single-event read no longer returns
  the deferred report.
- `Event::$document` is now an allow-listed view of the archived submission
  (no raw signals, user agent or address). It stays an opaque array.
- The vendored contract snapshot is refreshed.

## 0.1.1

### Security

- `Events::get()` refuses an empty request id, `.` and `..` with
  `ConfigurationException` before sending anything. Escaping leaves dots
  alone and cURL resolves dot segments, so `..` reached a route above
  `/events` with the secret key attached, and the empty id or `.` read the
  event list in place of one event. The request id usually comes from the
  browser, so upgrade if you pass it through unchecked. A dot inside an id,
  such as `a.b`, is still read as before.

## 0.1.0

The first release. Changes made before it, after the pre-release field test:

### Proxy

- Reuses one upstream connection per process instead of opening one per
  request, which exhausted the host's ephemeral ports under load. On PHP 8.5
  and later the connection is shared across PHP-FPM requests too.
- Answers an upstream timeout with `504 Gateway timeout`, and an unreachable
  upstream, a dropped connection, a success that is not JSON or a response
  body over 4 MiB with `502 Bad gateway`. Never throws on upstream trouble.
- New `on_error` option: one line per upstream failure, for your logger.
- `client_ip` must return exactly one IPv4 or IPv6 address. Anything else
  forwards nothing and answers `500`, reported once per process.
- `ProxyRequest` gains `remoteAddress`, filled by every adapter and by
  `fromGlobals()`, for `client_ip` to fall back on.
- The path is matched byte for byte: no trailing or doubled slash, dot
  segment, percent-encoding or other case. The configured path must be one a
  request can carry unchanged.
- The PSR-15 middleware reads the whole body (it stopped at 8 KiB) and no
  longer consumes the body of requests for other routes.
- The adapters match the path before building a request, so other routes pay
  nothing for them.
- Laravel and Symfony no longer answer `/index.php/<path>`: `getPathInfo()`
  strips a spelled-out front controller, which is not the configured path.
- A control character in a visitor's `User-Agent`, `Origin`, `Idempotency-Key`
  or `Content-Type` drops that value instead of being copied upstream, so it
  cannot add a header to the upstream request.
- An upstream status outside 200 to 599 answers `502` instead of making the
  framework throw while building the response.
- A `HEAD` refusal is empty, so servers that write what they are handed
  (Swoole) cannot desynchronise a kept-alive connection.
- A supplied PSR-18 client now needs both `request_factory` and
  `stream_factory`.

### Event reads

- `ApiException::isPending()` for `404 event_pending`: the event was accepted
  and is still being recorded, so read again after `getRetryAfter()` seconds.
  `isEventNotFound()` still matches only `event_not_found` (never issued, or
  past retention).
- `ApiException::getRetryAfter()` and `isRateLimited()`; the request id falls
  back on `X-Request-Id`.
- Response bodies over 4 MiB are refused with `ConnectionException`.
- Every failure of a supplied PSR-18 client becomes a `ConnectionException`.

### Webhooks

- At most 32 signatures in the header are considered.
- `Webhook::parse()` throws `WebhookException` for a payload it cannot read,
  instead of an `ApiException`.

### Everything

- Keys never appear in `var_dump()`, `print_r()`, `var_export()`,
  `json_encode()`, Symfony's `dump()`, Laravel's `dd()`, serialisation or
  captured stack-frame arguments.
- The package ships only `src/`, the licence, the readme and this changelog.
- The adapters are tested against Laravel 10 to 13 and Symfony 6.4 to 8.
