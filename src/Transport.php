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

        $handle = curl_init();
        if (false === $handle) {
            throw new ConnectionException('fingerly: could not initialise an HTTP handle');
        }

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

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        // No curl_close(): since PHP 8.0 the handle is an object the garbage
        // collector frees, and calling it is deprecated from 8.5.

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

    /** Strips the credential from anything an error may carry to logs. */
    private function redact(string $value): string
    {
        if (null === $this->redactKey || '' === $this->redactKey) {
            return $value;
        }

        return str_replace($this->redactKey, '[redacted]', $value);
    }
}
