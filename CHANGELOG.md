# Changelog

## Unreleased

- `Event::$ipAddress` is gone; the API no longer returns the address. The new
  `Event::$ipNetwork` (`?string`) holds the network the request came from: an
  IPv4 /24 or an IPv6 /40, such as `203.0.113.0/24`.
- `Event::$deferredSignals` is gone; the single-event read no longer returns
  the deferred report.
- `Event::$document` is now an allow-listed view of the archived submission
  (no raw signals, user agent or address). It stays an opaque array.

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
