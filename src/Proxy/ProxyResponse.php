<?php

declare(strict_types=1);

namespace Fingerly\Proxy;

/**
 * What the proxy answers with, in a shape any framework can return.
 *
 * The adapters convert it; {@see self::send()} writes it directly for a
 * framework that has none.
 */
final class ProxyResponse
{
    /**
     * @param int                   $status  the HTTP status to answer with
     * @param array<string, string> $headers header names lowercased
     * @param string                $body    the body, empty on a status that may not carry one
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * Writes the response with PHP's own output functions.
     *
     * For a bare script or a framework with no adapter. It is a no-op on
     * headers when they have already been sent, which is the one case where
     * writing them would be a warning rather than a response.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name.': '.$value, true);
            }
        }
        echo $this->body;
    }
}
