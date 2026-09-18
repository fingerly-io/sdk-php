<?php

declare(strict_types=1);

namespace Fingerly;

use Fingerly\Proxy\ProxyRequest;
use Fingerly\Proxy\ProxyResponse;

/**
 * Same-origin handler for the browser SDK's identify and supplement calls.
 *
 * It exists so the browser SDK can post to your own domain instead of
 * Fingerly, because content blockers refuse third-party requests and a refused
 * identification is a visitor never identified. Only those two routes are
 * forwarded, never event reads: reading events needs a secret key and must
 * stay unreachable from a browser. It sends only vetted visitor metadata, and
 * never passes on customer cookies or authorization headers.
 *
 * {@see self::handle()} is the whole interface, so a framework without one of
 * the bundled adapters needs about ten lines of glue:
 *
 * ```php
 * $proxy = new \Fingerly\Proxy([
 *     'proxy_key' => getenv('FINGERLY_PROXY_KEY'),
 *     'prefix' => '/metrics',
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
    private const DEFAULT_PREFIX = '/api/fingerly';
    private const DEFAULT_MAX_BODY_BYTES = 1048576;
    private const DEFAULT_TIMEOUT = 5.0;

    private const OPTIONS = [
        'proxy_key',
        'secret_key',
        'client_ip',
        'prefix',
        'api_version',
        'upstream',
        'max_body_bytes',
        'timeout',
        'http_client',
        'request_factory',
        'stream_factory',
    ];

    /**
     * Upstream headers that describe that connection rather than our response.
     * The body has already been read and framed by the time it is relayed, so
     * replaying them would misdescribe the bytes we hand back.
     */
    private const CONNECTION_HEADERS = [
        'connection',
        'content-encoding',
        'content-length',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
    ];

    /** Statuses that must not carry a body. */
    private const BODILESS_STATUSES = [204, 205, 304];

    /** Server-held proxy key, or deprecated secret key, sent only to Fingerly. */
    private readonly string $key;

    /** Proxy keys authenticate the browser's public key; secret keys do not. */
    private readonly bool $usesProxyKey;

    /** @var array{region: string, environment: string} matched against the browser's key */
    private readonly array $scope;

    private readonly string $upstream;
    private readonly string $prefix;
    private readonly string $apiVersion;
    private readonly int $maxBodyBytes;
    private readonly Transport $transport;

    /** @var callable(ProxyRequest): string */
    private $clientIp;

    /**
     * @param array<string, mixed> $options `proxy_key` and `client_ip` are required; `client_ip`
     *                                      is a `callable(ProxyRequest): string`. The rest are
     *                                      `prefix`, `api_version`, `upstream`, `max_body_bytes`,
     *                                      `timeout`, and a PSR-18 `http_client` with its
     *                                      `request_factory` and `stream_factory`. It is
     *                                      deliberately not a shape: these come from environment
     *                                      variables and config files, where every value is
     *                                      `mixed`, and validating them here is the point. An
     *                                      option this build does not know is refused rather
     *                                      than ignored.
     *
     * @throws ConfigurationException if a credential, route, limit or callback is invalid
     */
    public function __construct(array $options)
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
        $this->key = (string) ($proxyKey ?? $secretKey);
        $this->scope = Config::parseKey(
            $this->key,
            $this->usesProxyKey ? Config::KEY_PROXY : Config::KEY_SECRET,
        );

        $upstream = $options['upstream'] ?? null;
        $this->upstream = Config::normalizeOrigin(
            is_string($upstream) ? $upstream : Config::regionalOrigin($this->scope),
        );
        $apiVersion = $options['api_version'] ?? null;
        $this->apiVersion = Config::normalizeApiVersion(is_string($apiVersion) ? $apiVersion : null);
        $prefix = $options['prefix'] ?? null;
        $this->prefix = Config::normalizePrefix(is_string($prefix) ? $prefix : self::DEFAULT_PREFIX);
        $this->maxBodyBytes = Config::positiveInt(
            $options['max_body_bytes'] ?? self::DEFAULT_MAX_BODY_BYTES,
            'max_body_bytes',
        );
        $timeout = Config::positiveNumber($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout');
        $this->clientIp = $clientIp;

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
     * Whether this request is one the proxy answers.
     *
     * An adapter asks first, so a request for the rest of the application is
     * passed straight through rather than refused with a 404 of ours.
     */
    public function handles(ProxyRequest $request): bool
    {
        return null !== $this->routeFor($request->path);
    }

    /**
     * Handles one browser request.
     *
     * Every refusal is produced here, before anything reaches Fingerly.
     */
    public function handle(ProxyRequest $request): ProxyResponse
    {
        if ('POST' !== strtoupper($request->method)) {
            return self::refuse(405, 'Method Not Allowed', ['allow' => 'POST']);
        }

        $upstreamPath = $this->routeFor($request->path);
        if (null === $upstreamPath) {
            return self::refuse(404, 'Not Found');
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

        try {
            $response = $this->transport->send('POST', $this->upstream.$upstreamPath, $headers, $body);
        } catch (ConnectionException|TimeoutException) {
            // Give Fingerly its deadline and then answer, rather than holding
            // the browser's connection open.
            return self::refuse(502, 'Bad Gateway');
        }

        return $this->relay($response);
    }

    /**
     * Maps a local path to its upstream path.
     *
     * Event reads need a secret key and must stay unreachable from a browser,
     * so only identify and supplement are proxyable. The prefix is tolerated
     * as either present or already stripped, because a framework that mounts
     * the middleware under a path removes it before the adapter sees it and
     * gives no way to detect that it did.
     */
    private function routeFor(string $path): ?string
    {
        $versioned = '/api/'.$this->apiVersion;
        $mount = $this->prefix.$versioned;

        if (str_starts_with($path, $mount)) {
            $resource = substr($path, strlen($mount));
        } elseif (str_starts_with($path, $versioned)) {
            $resource = substr($path, strlen($versioned));
        } else {
            return null;
        }

        if ('/identify' !== $resource && !self::isSupplementPath($resource)) {
            return null;
        }

        return $versioned.$resource;
    }

    /**
     * Whether a resource is `/events/{request_id}/supplement` with exactly one
     * id segment.
     */
    private static function isSupplementPath(string $resource): bool
    {
        if (!str_starts_with($resource, '/events/') || !str_ends_with($resource, '/supplement')) {
            return false;
        }
        $requestId = substr($resource, strlen('/events/'), -strlen('/supplement'));

        return '' !== $requestId && !str_contains($requestId, '/');
    }

    /**
     * Decides which key to present upstream.
     *
     * With a proxy key the browser must supply a public key for the same
     * region and environment, which the proxy forwards alongside its own. The
     * deprecated secret-key mode authenticates on its own and accepts anything.
     */
    private function upstreamKeyFor(string $presented): ?string
    {
        if (!$this->usesProxyKey) {
            return $this->key;
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
    private function upstreamHeaders(ProxyRequest $request, string $apiKey): array
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
            'user-agent' => Transport::USER_AGENT,
        ];
        if ($this->usesProxyKey) {
            $headers['x-fingerly-proxy-key'] = $this->key;
        }
        $idempotency = $request->header('idempotency-key');
        if ('' !== $idempotency) {
            $headers['idempotency-key'] = $idempotency;
        }

        return $headers;
    }

    /**
     * Returns the upstream response to the browser, uncacheable and without
     * the headers that described the upstream connection.
     *
     * @param array{status: int, headers: array<string, string>, body: string} $response
     */
    private function relay(array $response): ProxyResponse
    {
        $headers = $response['headers'];
        foreach (self::CONNECTION_HEADERS as $name) {
            unset($headers[$name]);
        }
        $headers['cache-control'] = 'no-store';

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
