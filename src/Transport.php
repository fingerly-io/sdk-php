<?php

declare(strict_types=1);

namespace Fingerly;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * One HTTP exchange, over ext-curl or a caller's PSR-18 client.
 *
 * ext-curl is the default because it is the only HTTP client PHP ships, so
 * the package needs no Composer dependency to work. A PSR-18 client is
 * accepted for hosts that route all outbound traffic through one.
 *
 * @internal
 */
final class Transport
{
    /** The SDK build that made the request, sent on every call. */
    public const USER_AGENT = 'fingerly-php/'.Client::VERSION;

    /**
     * Caps how much of a failed response is parsed for metadata. An error body
     * is small; anything larger is not one worth reading.
     */
    private const MAX_ERROR_BODY = 1048576;

    private readonly ?object $httpClient;
    private readonly ?object $requestFactory;
    private readonly ?object $streamFactory;

    /**
     * One cURL handle for the life of this transport, so its connection cache
     * keeps the upstream connection open between requests. A handle per
     * request opens a new TCP (and TLS) connection every time and leaves each
     * one in TIME_WAIT, which exhausts the host's ephemeral ports at a few
     * hundred requests a second.
     */
    private ?\CurlHandle $handle = null;

    /** The process-wide connection cache on PHP 8.5+, or null before it. */
    private ?object $share = null;

    /**
     * @param float       $timeout   deadline covering the request and reading its body
     * @param null|string $redactKey a credential to strip from anything an error carries
     */
    public function __construct(
        private readonly float $timeout,
        private readonly ?string $redactKey = null,
        ?object $httpClient = null,
        ?object $requestFactory = null,
        ?object $streamFactory = null,
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * Performs one request and returns its status, headers and body.
     *
     * It never retries and never follows redirects, so an authenticated
     * request cannot be replayed to another host.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws ConnectionException
     * @throws TimeoutException
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (null !== $this->httpClient) {
            return $this->sendPsr18($method, $url, $headers, $body);
        }

        return $this->sendCurl($method, $url, $headers, $body);
    }

    /**
     * Maps a failed response to a redacted {@see ApiException}.
     *
     * Only the status and the structured fields the API documents are
     * surfaced. The raw body is deliberately dropped rather than echoed.
     */
    public function apiException(int $status, string $body): ApiException
    {
        $decoded = json_decode(substr($body, 0, self::MAX_ERROR_BODY), true);
        if (!is_array($decoded)) {
            // A non-JSON or truncated body still reports its HTTP status, and
            // its contents are deliberately dropped rather than surfaced.
            return new ApiException($status);
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = is_string($error['code'] ?? null) ? $this->redact($error['code']) : null;
        $requestId = is_string($decoded['request_id'] ?? null) ? $this->redact($decoded['request_id']) : null;

        $details = null;
        if (array_key_exists('details', $error)) {
            // A JSON round-trip redacts nested values without walking the tree.
            $encoded = json_encode($error['details']);
            $details = is_string($encoded) ? json_decode($this->redact($encoded), true) : null;
        }

        return new ApiException($status, $code, $details, $requestId);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function sendCurl(string $method, string $url, array $headers, ?string $body): array
    {
        if ('' === $method || '' === $url) {
            throw new ConnectionException('fingerly: a request needs a method and a URL');
        }

        $handle = $this->handle();
        // Start from a clean handle even if an earlier call left options
        // behind; curl_reset() keeps the connection cache.
        curl_reset($handle);

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $received = [];
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_RETURNTRANSFER => true,
            // The deadline covers the whole transfer, body included, so a
            // server that answers and then stalls still hits it.
            \CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
            \CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->timeout * 1000),
            // Never replay a credential to another host.
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$received): int {
                $length = strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    // A new status line (after a 100 Continue, say) starts a
                    // new header block; only the final response's count.
                    $received = [];

                    return $length;
                }
                $parts = explode(':', $line, 2);
                if (2 === count($parts)) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];
        if (null !== $body) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        if (null !== $this->share) {
            // A CurlSharePersistentHandle, which PHP 8.5 added and the 8.1
            // stubs this is analysed against do not know.
            curl_setopt($handle, \CURLOPT_SHARE, $this->share); // @phpstan-ignore argument.type
        }

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        // Drop the per-request options (headers, body, the header callback and
        // the variables it closes over) so nothing of this request survives
        // into the next; curl_reset() keeps the connection cache.
        curl_reset($handle);

        if (\CURLE_OPERATION_TIMEDOUT === $errno) {
            throw new TimeoutException();
        }
        if (0 !== $errno || !is_string($response)) {
            throw new ConnectionException('fingerly: request failed: '.$this->redact($error));
        }

        /** @var array<string, string> $received */
        return ['status' => $status, 'headers' => $received, 'body' => $response];
    }

    /**
     * Sends through a caller's PSR-18 client.
     *
     * PSR-18 has no way to express "do not follow redirects" or a deadline, so
     * both are the supplied client's responsibility. That is the trade for
     * routing through the host's own HTTP stack, and it is documented.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function sendPsr18(string $method, string $url, array $headers, ?string $body): array
    {
        /** @var ClientInterface $client */
        $client = $this->httpClient;

        /** @var RequestFactoryInterface $factory */
        $factory = $this->requestFactory;

        $request = $factory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (null !== $body) {
            /** @var null|StreamFactoryInterface $streams */
            $streams = $this->streamFactory;
            $request = null !== $streams
                ? $request->withBody($streams->createStream($body))
                : $request;
        }

        try {
            $response = $client->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new ConnectionException('fingerly: request failed: '.$this->redact($e->getMessage()), 0, $e);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException('fingerly: request failed: '.$this->redact($e->getMessage()), 0, $e);
        }

        $received = [];
        foreach ($response->getHeaders() as $name => $values) {
            $received[strtolower((string) $name)] = implode(', ', $values);
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => $received,
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * The reused handle, created on first use.
     *
     * On PHP 8.5 and later it also joins a persistent share handle, so the
     * connection, DNS and TLS session caches outlive a PHP-FPM request too.
     * Cookies are deliberately not shared: the transport keeps no cookie jar.
     * DNS entries still expire after libcurl's default 60 seconds.
     */
    private function handle(): \CurlHandle
    {
        if (null !== $this->handle) {
            return $this->handle;
        }
        $handle = curl_init();
        if (false === $handle) {
            throw new ConnectionException('fingerly: could not initialise an HTTP handle');
        }
        if (\function_exists('curl_share_init_persistent')) {
            $this->share = curl_share_init_persistent([
                \CURL_LOCK_DATA_CONNECT,
                \CURL_LOCK_DATA_DNS,
                \CURL_LOCK_DATA_SSL_SESSION,
            ]);
        }

        return $this->handle = $handle;
    }

    /** Strips the credential from anything an error may carry to logs. */
    private function redact(string $value): string
    {
        if (null === $this->redactKey || '' === $this->redactKey) {
            return $value;
        }

        return str_replace($this->redactKey, '[redacted]', $value);
    }
}
