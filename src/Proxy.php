<?php

declare(strict_types=1);

namespace Fingerly;

use Fingerly\Proxy\ProxyRequest;
use Fingerly\Proxy\ProxyResponse;

/**
 * Same-origin handler for the browser SDK.
 *
 * It exists so the browser SDK can post to a path on your own domain instead
 * of Fingerly, because content blockers refuse third-party requests and a
 * refused identification is a visitor never identified. The SDK posts both of
 * its calls to that one path, and this forwards each to Fingerly's one ingest
 * URL, never anywhere else and never to event reads: reading events needs a
 * secret key and must stay unreachable from a browser. It sends only vetted
 * visitor metadata, never passes on customer cookies or authorization headers,
 * and relays nothing back that names Fingerly.
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
        'secret_key',
        'client_ip',
        'path',
        'api_version',
        'upstream',
        'max_body_bytes',
        'timeout',
        'http_client',
        'request_factory',
        'stream_factory',
        'on_error',
    ];

    /** What this proxy reports itself as upstream; shown per proxy in the dashboard. */
    private const PROXY_VERSION = 'php/'.Client::VERSION;

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

    /** Statuses that must not carry a body. */
    private const BODILESS_STATUSES = [204, 205, 304];

    /** Server-held proxy key, or deprecated secret key, sent only to Fingerly. */
    private readonly Secret $key;

    /** Proxy keys authenticate the browser's public key; secret keys do not. */
    private readonly bool $usesProxyKey;

    /** @var array{environment: string} matched against the browser's key */
    private readonly array $scope;

    /** The one URL requests are forwarded to. */
    private readonly string $upstreamUrl;

    /** The one path answered, or null to answer wherever the framework routes. */
    private readonly ?string $path;

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
     *                                      `path`, `api_version`, `upstream`, `max_body_bytes`,
     *                                      `timeout`, `on_error` (a `callable(string): void`
     *                                      given one line per upstream failure), and a PSR-18
     *                                      `http_client` with its `request_factory` and
     *                                      `stream_factory`. It is
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
        Config::rejectUnknown($options, self::OPTIONS);

        $proxyKey = $options['proxy_key'] ?? null;
        $secretKey = $options['secret_key'] ?? null;
        if (null === $proxyKey && null === $secretKey) {
            throw new ConfigurationException('A Fingerly proxy key is required');
        }
        if (null !== $proxyKey && null !== $secretKey) {
            throw new ConfigurationException('Pass proxy_key or the deprecated secret_key, not both');
        }
        $clientIp = $options['client_ip'] ?? null;
        if (!is_callable($clientIp)) {
            throw new ConfigurationException('client_ip is required and must be callable');
        }

        $this->usesProxyKey = null !== $proxyKey;
        $key = (string) ($proxyKey ?? $secretKey);
        $this->scope = Config::parseKey(
            $key,
            $this->usesProxyKey ? Config::KEY_PROXY : Config::KEY_SECRET,
        );
        $this->key = new Secret($key);

        $upstream = $options['upstream'] ?? null;
        $apiVersion = $options['api_version'] ?? null;
        $this->upstreamUrl = Config::normalizeOrigin(
            is_string($upstream) ? $upstream : Config::DEFAULT_ENDPOINT,
        ).'/api/'.Config::normalizeApiVersion(is_string($apiVersion) ? $apiVersion : null).'/ingest';
        $path = $options['path'] ?? null;
        $this->path = null === $path ? null : Config::normalizePath($path);
        $this->maxBodyBytes = Config::positiveInt(
            $options['max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES,
            'max_body_bytes',
        );
        $timeout = Config::positiveNumber($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout');
        $this->timeout = $timeout;
        $this->clientIp = $clientIp;
        $onError = $options['on_error'] ?? null;
        if (null !== $onError && !is_callable($onError)) {
            throw new ConfigurationException('on_error must be callable');
        }
        $this->onError = $onError;

        $this->transport = new Transport(
            timeout: $timeout,
            redactKey: $this->key,
            httpClient: is_object($options['http_client'] ?? null) ? $options['http_client'] : null,
            requestFactory: is_object($options['request_factory'] ?? null) ? $options['request_factory'] : null,
            streamFactory: is_object($options['stream_factory'] ?? null) ? $options['stream_factory'] : null,
        );
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
     * Handles one browser request.
     *
     * Every refusal is produced here, before anything reaches Fingerly.
     */
    public function handle(ProxyRequest $request): ProxyResponse
    {
        if (!$this->handles($request)) {
            return self::refuse(404, 'Not Found');
        }
        if ('POST' !== strtoupper($request->method)) {
            return self::refuse(405, 'Method Not Allowed', ['allow' => 'POST']);
        }

        $apiKey = $this->upstreamKeyFor($request->header('x-api-key'));
        if (null === $apiKey) {
            return self::refuse(401, 'Unauthorized');
        }

        // Refuse on the declared size first; reading enforces the real one.
        $declared = $request->header('content-length');
        if ('' !== $declared && ctype_digit($declared) && (int) $declared > $this->maxBodyBytes) {
            return self::refuse(413, 'Payload Too Large');
        }

        $body = $this->readBody($request);
        if (null === $body) {
            return self::refuse(413, 'Payload Too Large');
        }

        try {
            $headers = $this->upstreamHeaders($request, $apiKey);
        } catch (ConfigurationException) {
            // A client_ip that cannot be a header value is a bug in the
            // integration, not something the visitor did.
            return self::refuse(500, 'Internal Server Error');
        }

        // Give Fingerly its deadline and then answer, rather than holding the
        // browser's connection open, and never let upstream trouble surface
        // as an exception the framework would turn into its own 500 page.
        try {
            $response = $this->transport->send('POST', $this->upstreamUrl, $headers, $body);
        } catch (TimeoutException) {
            $this->report(sprintf('upstream timed out after %ss', $this->timeout));

            return self::refuse(504, 'Gateway timeout');
        } catch (ConnectionException $e) {
            $this->report('upstream request failed: '.preg_replace('/\Afingerly: (request failed: )?/', '', $e->getMessage()));

            return self::refuse(502, 'Bad gateway');
        }

        // A success the browser cannot read would be shown as a wrong result
        // rather than retried, so a 2xx must carry the JSON it promises.
        $status = $response['status'];
        if ($status >= 200 && $status < 300 && '' !== $response['body']
            && !in_array($status, self::BODILESS_STATUSES, true)
            && null === json_decode($response['body'], true)) {
            $this->report(sprintf('upstream answered %d with a body that is not JSON', $status));

            return self::refuse(502, 'Bad gateway');
        }

        return $this->relay($response);
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
     * Decides which key to present upstream.
     *
     * With a proxy key the browser must supply a public key for the same
     * environment, which the proxy forwards alongside its own. The deprecated
     * secret-key mode authenticates on its own and accepts anything.
     */
    private function upstreamKeyFor(#[\SensitiveParameter] string $presented): ?string
    {
        if (!$this->usesProxyKey) {
            return $this->key->reveal();
        }

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
     * Builds the upstream headers.
     *
     * Nothing is copied wholesale: only the body's content type, the visitor
     * metadata Fingerly needs, and an idempotency key when the SDK sent one.
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException if client_ip returns a value unusable as a header
     */
    private function upstreamHeaders(ProxyRequest $request, #[\SensitiveParameter] string $apiKey): array
    {
        $clientIp = ($this->clientIp)($request);
        if (!is_string($clientIp) || 1 !== preg_match('/\A[\x20-\x7e]*\z/', $clientIp)) {
            throw new ConfigurationException('client_ip must resolve to a plain address');
        }

        $contentType = $request->header('content-type');
        $headers = [
            'content-type' => '' !== $contentType ? $contentType : 'application/json',
            'x-api-key' => $apiKey,
            'x-fingerly-client-ip' => $clientIp,
            'x-fingerly-origin' => $request->header('origin'),
            'x-fingerly-user-agent' => $request->header('user-agent'),
            'x-fingerly-proxy-version' => self::PROXY_VERSION,
            'user-agent' => Transport::USER_AGENT,
        ];
        if ($this->usesProxyKey) {
            $headers['x-fingerly-proxy-key'] = $this->key->reveal();
        }
        $idempotency = $request->header('idempotency-key');
        if ('' !== $idempotency) {
            $headers['idempotency-key'] = $idempotency;
        }

        return $headers;
    }

    /**
     * Returns the upstream response to the browser, uncacheable and with only
     * the allowlisted headers.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    private function relay(array $response): ProxyResponse
    {
        $headers = ['cache-control' => 'no-store'];
        foreach (self::RELAYED_HEADERS as $name) {
            if (isset($response['headers'][$name])) {
                $headers[$name] = $response['headers'][$name];
            }
        }

        $bodiless = in_array($response['status'], self::BODILESS_STATUSES, true);

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
}
