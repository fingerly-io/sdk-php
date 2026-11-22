<?php

declare(strict_types=1);

namespace Fingerly;

use Fingerly\Proxy\ProxyRequest;
use Fingerly\Proxy\ProxyResponse;

/**
 * First-party ingest handler for Fingerly client SDKs.
 *
 * It exists so client SDKs can post to a path on your own domain instead of
 * Fingerly, because content blockers refuse third-party requests and a refused
 * identification is a visitor never identified. Client SDKs post a single
 * identification envelope to that one path, and this forwards its raw bytes
 * verbatim to Fingerly's one ingest URL, never anywhere else and never to event
 * reads: reading events needs a secret key and must stay unreachable from a
 * browser. It strips all caller-supplied `x-fingerly-*`, `cookie` and
 * `authorization` headers (restoring only a validated
 * `x-fingerly-sdk-platform`), never synthesizes `x-fingerly-origin`, and relays
 * nothing back that names Fingerly.
 *
 * {@see self::handle()} is the whole interface, so a framework without one of
 * the bundled adapters needs about ten lines of glue:
 *
 * ```php
 * $proxy = new \Fingerly\Proxy([
 *     'proxy_key' => getenv('FINGERLY_PROXY_KEY'),
 *     'path' => '/k7x2qm4a',
 *     'client_ip' => fn (ProxyRequest $r) => $r->header('cf-connecting-ip'),
 * ]);
 * $proxy->handle(ProxyRequest::fromGlobals())->send();
 * ```
 *
 * @see Proxy\Psr15Middleware   for Slim, Mezzio and anything else PSR-15
 * @see Proxy\LaravelMiddleware for Laravel
 * @see Proxy\SymfonyListener   for Symfony
 */
final class Proxy
{
    private const DEFAULT_MAX_BODY_BYTES = 1048576;
    private const DEFAULT_TIMEOUT = 5.0;

    private const OPTIONS = [
        'proxy_key',
        'client_ip',
        'path',
        'allowed_origins',
        'api_version',
        'upstream',
        'allow_insecure_loopback',
        'max_body_bytes',
        'timeout',
        'on_error',
    ];

    /** What this proxy reports itself as upstream; shown per proxy in the dashboard. */
    private const PROXY_VERSION = 'php/'.Client::VERSION;

    /** JSON refusal when both Origin and a validated native platform header are absent. */
    private const MISSING_ORIGIN_JSON = '{"error":{"code":"missing_origin","message":"Missing origin","status":400}}';

    /** Exact lowercase native and bridge identifiers allowed in x-fingerly-sdk-platform. */
    private const ALLOWED_PLATFORMS = '/\A(ios|android|react-native|react-native-ios|react-native-android|flutter|flutter-ios|flutter-android|lynx-ios|lynx-android|capacitor|kotlin-multiplatform)\z/';

    /** Allowlisted client headers forwarded verbatim when present and header-safe. */
    private const FORWARDED_CLIENT_HEADERS = [
        'idempotency-key',
        'sec-fetch-site',
        'sec-fetch-mode',
        'sec-fetch-dest',
        'sec-ch-ua',
        'sec-ch-ua-mobile',
        'sec-ch-ua-platform',
    ];

    /**
     * Web Bot Auth headers an AI agent's browser signs the request with,
     * forwarded when present, header-safe and at most
     * {@see self::MAX_SIGNATURE_HEADER_BYTES}. The API ignores a longer one,
     * so it is dropped here rather than refused.
     */
    private const SIGNATURE_HEADERS = [
        'signature',
        'signature-input',
        'signature-agent',
    ];

    /** The longest Web Bot Auth header value forwarded, in bytes. */
    private const MAX_SIGNATURE_HEADER_BYTES = 4096;

    /**
     * The response headers relayed to the browser, and nothing else. A content
     * blocker can match a response header by name, so an allowlist is what
     * keeps anything naming Fingerly off the page, whatever the API adds in
     * future. It also leaves out the headers that described the upstream
     * connection, which would misdescribe the bytes we hand back.
     */
    private const RELAYED_HEADERS = [
        'content-type',
        'x-request-id',
        'ratelimit-limit',
        'ratelimit-remaining',
        'retry-after',
    ];

    /** The one answer to a client_ip callback that gave no usable address. */
    private const NO_CLIENT_IP = 'The client IP callback returned no valid address';

    /** Statuses that must not carry a body. */
    private const BODILESS_STATUSES = [204, 205, 304];

    /** Whether this process has already reported a broken client_ip callback. */
    private static bool $reportedClientIp = false;

    /** Server-held proxy key, sent only to Fingerly as `x-fingerly-proxy-key`. */
    private readonly Secret $key;

    /** @var array{environment: string} matched against the browser's public key */
    private readonly array $scope;

    /** The one URL requests are forwarded to. */
    private readonly string $upstreamUrl;

    /** The one path answered, or null to answer wherever the framework routes. */
    private readonly ?string $path;

    /** @var list<string> configured cross-origin CORS origins */
    private readonly array $allowedOrigins;

    private readonly int $maxBodyBytes;
    private readonly Transport $transport;

    /** @var callable(ProxyRequest): string */
    private $clientIp;

    /** @var null|callable(string): void */
    private $onError;

    private readonly float $timeout;

    /**
     * @param array<string, mixed> $options `proxy_key` and `client_ip` are required; `client_ip`
     *                                      is a `callable(ProxyRequest): string`. The rest are
     *                                      `path`, `api_version`, `upstream` (a bare HTTPS origin),
     *                                      `allow_insecure_loopback` (true lets `upstream` be
     *                                      plain HTTP on 127.0.0.1, localhost or [::1] for a
     *                                      local test harness), `max_body_bytes`,
     *                                      `timeout`, `on_error` (a `callable(string): void`
     *                                      given one line per upstream failure). It is
     *                                      deliberately not a shape: these come from environment
     *                                      variables and config files, where every value is
     *                                      `mixed`, and validating them here is the point. An
     *                                      option this build does not know is refused rather
     *                                      than ignored.
     *
     * @throws ConfigurationException if a credential, path, limit or callback is invalid
     */
    public function __construct(#[\SensitiveParameter] array $options)
    {
        // Names only: an exception thrown from a helper that was handed the
        // whole array would carry the key in its trace arguments.
        Config::rejectUnknownKeys(array_keys($options), self::OPTIONS);

        $proxyKey = $options['proxy_key'] ?? null;
        if (null === $proxyKey) {
            throw new ConfigurationException('A Fingerly proxy key is required');
        }
        if (!is_string($proxyKey)) {
            throw new ConfigurationException('A valid Fingerly proxy key is required');
        }
        $clientIp = $options['client_ip'] ?? null;
        if (!is_callable($clientIp)) {
            throw new ConfigurationException('client_ip is required and must be callable');
        }

        $this->scope = Config::parseKey($proxyKey, Config::KEY_PROXY);
        $this->key = new Secret($proxyKey);

        $upstream = $options['upstream'] ?? null;
        $apiVersion = $options['api_version'] ?? null;
        $allowInsecureLoopback = $options['allow_insecure_loopback'] ?? false;
        if (!is_bool($allowInsecureLoopback)) {
            throw new ConfigurationException('allow_insecure_loopback must be a boolean');
        }
        if (null !== $upstream && !is_string($upstream)) {
            throw new ConfigurationException('upstream must be a string');
        }
        $this->upstreamUrl = Config::bareOrigin(
            $upstream ?? Config::DEFAULT_ENDPOINT,
            $allowInsecureLoopback,
            'upstream',
        ).'/api/'.Config::normalizeApiVersion($apiVersion).'/ingest';
        $path = $options['path'] ?? null;
        $this->path = null === $path ? null : Config::normalizePath($path);
        $this->allowedOrigins = self::normalizeAllowedOrigins($options['allowed_origins'] ?? null);
        $maxBodyBytes = Config::positiveInt($options['max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES, 'max_body_bytes');
        if (\PHP_INT_MAX === $maxBodyBytes) {
            // Reading one byte past the cap is how an oversized body is told
            // apart, and PHP_INT_MAX + 1 is a float no stream read takes.
            throw new ConfigurationException('max_body_bytes must be below PHP_INT_MAX');
        }
        $this->maxBodyBytes = $maxBodyBytes;
        $timeout = Config::positiveNumber($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout');
        $this->timeout = $timeout;
        $this->clientIp = $clientIp;
        $onError = $options['on_error'] ?? null;
        if (null !== $onError && !is_callable($onError)) {
            throw new ConfigurationException('on_error must be callable');
        }
        $this->onError = $onError;

        // Always the bundled ext-curl transport, which never follows a
        // redirect. A supplied HTTP client could, and would replay the proxy
        // key to wherever the redirect pointed before any answer came back.
        $this->transport = new Transport(timeout: $timeout, redactKey: $this->key);
    }

    /** The request body limit, so an adapter can read no more than this. */
    public function maxBodyBytes(): int
    {
        return $this->maxBodyBytes;
    }

    /**
     * The path the proxy answers, or null when it answers wherever the
     * framework routes it, so an adapter that sees every request can insist on
     * one.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Whether this request is one the proxy answers.
     *
     * An adapter asks first, so a request for the rest of the application is
     * passed straight through rather than refused with a 404 of ours. With a
     * `path` that is the path, byte for byte: no trailing or doubled slash, no
     * dot segment, no percent-encoding and no other case is the proxy's.
     * Without one it is every request, because the framework's route already
     * chose it.
     */
    public function handles(ProxyRequest $request): bool
    {
        return null === $this->path || $request->path === $this->path;
    }

    /**
     * Handles one client SDK request.
     *
     * Every refusal is produced here, before anything reaches Fingerly.
     */
    public function handle(ProxyRequest $request): ProxyResponse
    {
        // A body on a HEAD answer would be read as the start of the next
        // response on a kept-alive connection, and some servers (Swoole among
        // them) write whatever they are handed.
        // The method is matched exactly, as the contract and HTTP spell it:
        // `post` is not `POST`. The HEAD test is the one place case is
        // ignored, because it only decides whether to leave the body out.
        $method = $request->method;
        $head = 0 === strcasecmp($method, 'HEAD');
        if (!$this->handles($request)) {
            return self::refuse(404, $head ? '' : 'Not Found');
        }

        // Step 1: Capture validated x-fingerly-sdk-platform before stripping x-fingerly-*.
        $rawPlatform = $request->header('x-fingerly-sdk-platform');
        $sdkPlatform = 1 === preg_match(self::ALLOWED_PLATFORMS, $rawPlatform) ? $rawPlatform : '';
        $origin = trim(self::copyable($request->header('origin')));
        $corsOrigin = ('' !== $origin && in_array($origin, $this->allowedOrigins, true)) ? $origin : null;
        $cors = self::corsHeaders($corsOrigin);

        if ([] !== $this->allowedOrigins && 'OPTIONS' === $method) {
            if ('' !== $origin && null === $corsOrigin) {
                return self::refuse(403, 'Forbidden');
            }

            return new ProxyResponse(
                204,
                ['cache-control' => 'no-store', 'allow' => 'POST, OPTIONS'] + $cors,
                '',
            );
        }

        if ('POST' !== $method) {
            $allow = [] === $this->allowedOrigins ? 'POST' : 'POST, OPTIONS';

            return self::refuse(405, $head ? '' : 'Method Not Allowed', ['allow' => $allow] + $cors);
        }

        $apiKey = $this->upstreamKeyFor($request->header('x-api-key'));
        if (null === $apiKey) {
            return self::refuse(401, 'Unauthorized', $cors);
        }

        // Step 4: Enforce Origin / native-platform gate (never synthesize X-Fingerly-Origin).
        if ('' === $origin && '' === $sdkPlatform) {
            return self::jsonRefuse(400, self::MISSING_ORIGIN_JSON, $cors);
        }

        // Refuse on the declared size first; reading enforces the real one.
        $declared = $request->header('content-length');
        if ('' !== $declared && ctype_digit($declared) && (int) $declared > $this->maxBodyBytes) {
            return self::refuse(413, 'Payload Too Large', $cors);
        }

        $body = $this->readBody($request);
        if (null === $body) {
            return self::refuse(413, 'Payload Too Large', $cors);
        }

        $clientIp = $this->clientIp($request);
        if (null === $clientIp) {
            // A bug in the integration, not something the visitor did, and
            // forwarding anyway would record the wrong visitor or none.
            self::reportClientIpOnce($this->onError);

            return self::refuse(500, self::NO_CLIENT_IP, $cors);
        }
        $headers = $this->upstreamHeaders($request, $apiKey, $clientIp, $origin, $sdkPlatform);

        // Give Fingerly its deadline and then answer, rather than holding the
        // browser's connection open, and never let upstream trouble surface
        // as an exception the framework would turn into its own 500 page.
        try {
            $response = $this->transport->send('POST', $this->upstreamUrl, $headers, $body);
        } catch (TimeoutException) {
            $this->report(sprintf('upstream timed out after %ss', $this->timeout));

            return self::refuse(504, 'Gateway timeout', $cors);
        } catch (ConnectionException $e) {
            // The kind of failure only: the underlying message names the
            // upstream host, port and URL, which do not belong in a log line.
            $this->report('upstream request failed: '.(
                'too_large' === $e->kind ? sprintf('the response body is over %d MiB', Transport::MAX_RESPONSE_BODY >> 20) : $e->kind
            ));

            return self::refuse(502, 'Bad gateway', $cors);
        }

        // The frameworks and PSR-7 libraries throw on a status outside
        // 200 to 599 when the response is built, which would turn it into
        // their own error page; the upstream is the one answering, so it is
        // its failure. The Fingerly API never redirects an ingest, so a 3xx
        // is a wrong or intercepted upstream, and neither it nor its
        // Location is relayed.
        $status = $response['status'];
        if ($status < 200 || $status > 599) {
            $this->report(sprintf('upstream answered an invalid status %d', $status));

            return self::refuse(502, 'Bad gateway', $cors);
        }
        if ($status >= 300 && $status < 400) {
            $this->report(sprintf('upstream answered a redirect (%d)', $status));

            return self::refuse(502, 'Bad gateway', $cors);
        }

        // A success the browser cannot read would be shown as a wrong result
        // rather than retried, so a 2xx must carry the JSON it promises.
        if ($status >= 200 && $status < 300 && '' !== $response['body']
            && !in_array($status, self::BODILESS_STATUSES, true)
            && null === json_decode($response['body'], true)) {
            $this->report(sprintf('upstream answered %d with a body that is not JSON', $status));

            return self::refuse(502, 'Bad gateway', $cors);
        }

        return $this->relay($response, $cors);
    }

    /**
     * Hands one concise line about an upstream failure to the integration's
     * `on_error` hook, if it set one. A hook that throws is ignored: logging
     * must never change the answer the browser gets.
     */
    private function report(string $line): void
    {
        if (null === $this->onError) {
            return;
        }

        try {
            ($this->onError)('fingerly proxy: '.str_replace(["\r", "\n"], ' ', $line));
        } catch (\Throwable) {
            // Deliberately ignored.
        }
    }

    /**
     * The browser's public key to forward upstream, or null to refuse.
     *
     * The browser must supply a public key for the proxy key's environment,
     * which is forwarded alongside the proxy key. Anything else, a secret key
     * included, is refused before a byte leaves this host.
     */
    private function upstreamKeyFor(#[\SensitiveParameter] string $presented): ?string
    {
        try {
            $browser = Config::parseKey($presented, Config::KEY_PUBLIC);
        } catch (ConfigurationException) {
            return null;
        }

        return $browser === $this->scope ? $presented : null;
    }

    /**
     * Reads the body without buffering more than the limit.
     *
     * A stream is read one chunk past the limit and no further, so an
     * oversized body is cut off rather than held in memory first. A body that
     * arrives as a string has already been buffered by the framework, so only
     * its length can be checked.
     *
     * @return null|string the bytes, or null when the limit was exceeded
     */
    private function readBody(ProxyRequest $request): ?string
    {
        $body = $request->body;
        if (is_string($body)) {
            return strlen($body) > $this->maxBodyBytes ? null : $body;
        }
        if (!is_resource($body)) {
            return '';
        }

        $read = stream_get_contents($body, $this->maxBodyBytes + 1);
        if (false === $read) {
            return '';
        }

        return strlen($read) > $this->maxBodyBytes ? null : $read;
    }

    /**
     * Runs the client_ip callback and accepts exactly one IPv4 or IPv6
     * literal: not null, not empty, not a list, not padded, not a zone-scoped
     * address. A callback that throws gave no address either.
     */
    private function clientIp(ProxyRequest $request): ?string
    {
        try {
            $value = ($this->clientIp)($request);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && false !== filter_var($value, \FILTER_VALIDATE_IP) ? $value : null;
    }

    /**
     * Reports a broken client_ip callback once per process, not once per
     * request: every request fails the same way until the code is fixed.
     *
     * @param null|callable(string): void $onError
     */
    private static function reportClientIpOnce(?callable $onError): void
    {
        if (self::$reportedClientIp) {
            return;
        }
        self::$reportedClientIp = true;
        $line = 'fingerly proxy: the client_ip callback returned no valid IP address; nothing was forwarded';

        try {
            null === $onError ? error_log($line) : $onError($line);
        } catch (\Throwable) {
            // Deliberately ignored.
        }
    }

    /**
     * Builds the upstream headers.
     *
     * Nothing is copied wholesale: all incoming `x-fingerly-*`, `cookie` and
     * `authorization` headers are stripped, `x-fingerly-origin` is sent only
     * when incoming `origin` is non-empty (never synthesized from `host` or
     * the request URL), and a validated `x-fingerly-sdk-platform` is restored
     * only when there is no `origin`.
     *
     * @return array<string, string>
     */
    private function upstreamHeaders(
        ProxyRequest $request,
        #[\SensitiveParameter]
        string $apiKey,
        string $clientIp,
        string $origin,
        string $sdkPlatform,
    ): array {
        $contentType = trim(self::copyable($request->header('content-type')));
        $headers = [
            'content-type' => '' !== $contentType ? $contentType : 'application/json',
            'x-api-key' => $apiKey,
            'x-fingerly-client-ip' => $clientIp,
            'x-fingerly-user-agent' => self::copyable($request->header('user-agent')),
            'x-fingerly-proxy-version' => self::PROXY_VERSION,
            'user-agent' => Transport::USER_AGENT,
        ];
        // One channel only. A request with an Origin is a browser request, as
        // it is when sent to the API directly, so the platform header is
        // dropped then; the API refuses a proxied request that carries both.
        if ('' !== $origin) {
            $headers['x-fingerly-origin'] = $origin;
        } elseif ('' !== $sdkPlatform) {
            $headers['x-fingerly-sdk-platform'] = $sdkPlatform;
        }
        $host = trim(self::copyable($request->header('host')));
        if ('' !== $host) {
            $headers['x-fingerly-host'] = $host;
        }
        $headers['x-fingerly-proxy-key'] = $this->key->reveal();
        foreach (self::FORWARDED_CLIENT_HEADERS as $name) {
            $value = trim(self::copyable($request->header($name)));
            if ('' !== $value) {
                $headers[$name] = $value;
            }
        }
        foreach (self::SIGNATURE_HEADERS as $name) {
            $value = trim(self::copyable($request->header($name)));
            if ('' !== $value && \strlen($value) <= self::MAX_SIGNATURE_HEADER_BYTES) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * A visitor-supplied header value that is safe to copy upstream, or `''`.
     *
     * cURL writes a header line as given, so a value holding a line break
     * would add headers of the visitor's choosing to the upstream request,
     * `x-fingerly-client-ip` among them. No browser sends one; drop the value
     * rather than fail the identification over it.
     */
    private static function copyable(string $value): string
    {
        return 1 === preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) ? '' : $value;
    }

    /**
     * @return list<string>
     */
    private static function normalizeAllowedOrigins(#[\SensitiveParameter] mixed $value): array
    {
        if (null === $value) {
            return [];
        }
        if (!is_array($value)) {
            throw new ConfigurationException('allowed_origins must be a list of origin strings');
        }

        $origins = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new ConfigurationException('allowed_origins must contain only strings');
            }
            // A browser's Origin is scheme://host[:port], lowercase, with no
            // path, so an entry spelled any other way could never match it.
            $trimmed = strtolower(rtrim(trim($item), '/'));
            if ('' === self::copyable($trimmed) || 1 !== preg_match('#\A[a-z][a-z0-9+.-]*://[^/?\#@\s]+\z#', $trimmed)) {
                throw new ConfigurationException('allowed_origins must contain only origins such as https://shop.example');
            }
            if (!in_array($trimmed, $origins, true)) {
                $origins[] = $trimmed;
            }
        }

        return $origins;
    }

    /**
     * @return array<string, string>
     */
    private static function corsHeaders(?string $matchedOrigin): array
    {
        if (null === $matchedOrigin) {
            return [];
        }

        return [
            'access-control-allow-origin' => $matchedOrigin,
            'vary' => 'Origin',
            'access-control-allow-methods' => 'POST, OPTIONS',
            'access-control-allow-headers' => 'content-type, x-api-key, idempotency-key, x-fingerly-sdk-platform',
            'access-control-expose-headers' => 'x-request-id, ratelimit-limit, ratelimit-remaining, retry-after',
        ];
    }

    /**
     * Returns the upstream response to the browser, uncacheable and with only
     * the allowlisted headers.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $response
     * @param array<string, string>                                            $cors
     */
    private function relay(array $response, array $cors = []): ProxyResponse
    {
        $headers = ['cache-control' => 'no-store'];
        foreach (self::RELAYED_HEADERS as $name) {
            if (isset($response['headers'][$name]) && false === stripos($response['headers'][$name], 'fingerly')) {
                $headers[$name] = $response['headers'][$name];
            }
        }
        $headers += $cors;

        $bodiless = in_array($response['status'], self::BODILESS_STATUSES, true);
        if ($bodiless) {
            unset($headers['content-type']);
        }

        return new ProxyResponse($response['status'], $headers, $bodiless ? '' : $response['body']);
    }

    /**
     * Builds a plain-text refusal that never reaches Fingerly.
     *
     * @param array<string, string> $headers
     */
    private static function refuse(int $status, string $message, array $headers = []): ProxyResponse
    {
        return new ProxyResponse(
            $status,
            $headers + ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'no-store'],
            $message,
        );
    }

    /**
     * Builds a JSON refusal that never reaches Fingerly.
     *
     * @param array<string, string> $headers
     */
    private static function jsonRefuse(int $status, string $json, array $headers = []): ProxyResponse
    {
        return new ProxyResponse(
            $status,
            $headers + ['content-type' => 'application/json', 'cache-control' => 'no-store'],
            $json,
        );
    }
}
