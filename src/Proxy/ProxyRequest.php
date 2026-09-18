<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

/**
 * One incoming browser request, in the shape the proxy core reads.
 *
 * It exists so the core is framework-neutral: the three adapters build one of
 * these, and so can a framework that has no adapter. Ten lines of glue is all
 * Phalcon, CodeIgniter, Yii or a bare `index.php` needs.
 *
 * ```php
 * $response = $proxy->handle(new ProxyRequest(
 *     method: $_SERVER['REQUEST_METHOD'],
 *     path: parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
 *     headers: getallheaders(),
 *     body: fopen('php://input', 'rb'),
 * ));
 * ```
 */
final class ProxyRequest
{
    /** @var array<string, string> header names lowercased */
    public readonly array $headers;

    /** @var resource|string */
    public readonly mixed $body;

    /**
     * @param string                $method  the HTTP method, such as `POST`
     * @param string                $path    the request path, with no query string
     * @param array<string, string> $headers header names in any case; they are lowercased here
     * @param resource|string       $body    the raw body, or a stream the proxy reads under its own
     *                                       limit, which is what keeps an oversized body out of memory
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers = [],
        mixed $body = '',
    ) {
        $lowered = [];
        foreach ($headers as $name => $value) {
            $lowered[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $lowered;
        $this->body = $body;
    }

    /** One header, or `''` when it was not sent. */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /**
     * Builds a request from PHP's superglobals, for a bare script or a
     * framework with no adapter.
     */
    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($name, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $header) {
            if (isset($_SERVER[$key])) {
                $headers[$header] = (string) $_SERVER[$key];
            }
        }

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, \PHP_URL_PATH);
        $stream = fopen('php://input', 'rb');

        return new self(
            method: is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            path: is_string($path) ? $path : '/',
            headers: $headers,
            body: false === $stream ? '' : $stream,
        );
    }
}
