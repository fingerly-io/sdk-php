<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Spends the DeviceCheck tokens held for an iOS event, each once, to read and
 * to write the device's two Apple DeviceCheck bits.
 *
 * Reached as `$fingerly->deviceCheck`; it is never constructed directly.
 *
 * Apple treats each token as single-use, so an event holds one token for a
 * read and another for a write: to read and then mark a device, read and
 * write on the same identification. Tokens are held for one hour.
 *
 * A `503 device_check_unavailable`, a timeout or a connection failure keeps
 * the token, and the SDK retries the identical request on its own. Apple
 * allows a token to be retried only for the same request, so a second read,
 * or a write with other bits, on the same event answers
 * `409 device_check_spent`.
 *
 * ```php
 * $bits = $fingerly->deviceCheck->query($requestId);
 * $fingerly->deviceCheck->write($requestId, ['bit0' => true]);
 * ```
 */
final class DeviceCheck
{
    /** Tries after the first, for an answer that kept the token. */
    private const RETRIES = 2;

    /** Seconds before the first retry; each later one waits twice as long. */
    private const BACKOFF = 0.25;

    private const WRITE_OPTIONS = ['bit0', 'bit1'];

    /**
     * @param string $baseUrl versioned base URL, e.g. `https://api.fingerly.io/api/v1`
     *
     * @internal
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $baseUrl,
        private readonly Secret $secretKey,
    ) {}

    /**
     * Reads the device's two bits from Apple, spending the event's token.
     *
     * @throws ApiException           `404 device_check_not_held` when no token is held for the event;
     *                                `409 device_check_spent`, `device_check_busy` or
     *                                `credential_unavailable`; `422 apple_rejected` with
     *                                {@see ApiException::getAppleStatus()}; `503 device_check_unavailable`
     *                                once the retries are used up
     * @throws ConfigurationException if the request id is empty, `.` or `..`
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function query(string $requestId): DeviceCheckBits
    {
        $body = $this->spend('POST', self::path($requestId).'/query', null);

        return DeviceCheckBits::fromArray($body);
    }

    /**
     * Sets bit0, bit1 or both with Apple, spending the event's token. A bit
     * left out keeps its value.
     *
     * @param array<string, mixed> $bits `bit0` and `bit1`, each a boolean, or null or left out to keep
     *                                   it. It is not a shape, so values from a request or a config
     *                                   file are checked here rather than trusted.
     *
     * @throws ConfigurationException if neither bit is named, a value is not a boolean, another key is
     *                                given, or the request id is empty, `.` or `..`
     * @throws ApiException           `404 device_check_not_held`; `409 device_check_spent`,
     *                                `device_check_busy` or `credential_unavailable`;
     *                                `422 apple_rejected` with {@see ApiException::getAppleStatus()};
     *                                `503 device_check_unavailable` once the retries are used up
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function write(string $requestId, array $bits): DeviceCheckWrite
    {
        Config::rejectUnknownKeys(array_keys($bits), self::WRITE_OPTIONS);
        $send = [];
        foreach (self::WRITE_OPTIONS as $name) {
            if (!array_key_exists($name, $bits) || null === $bits[$name]) {
                continue;
            }
            if (!is_bool($bits[$name])) {
                throw new ConfigurationException(sprintf('%s must be a boolean', $name));
            }
            $send[$name] = $bits[$name];
        }
        if ([] === $send) {
            throw new ConfigurationException('name bit0, bit1 or both');
        }

        $body = $this->spend('PUT', self::path($requestId), json_encode($send, \JSON_THROW_ON_ERROR));

        return DeviceCheckWrite::fromArray($body);
    }

    private static function pause(int $attempt): void
    {
        usleep((int) (self::BACKOFF * (2 ** $attempt) * 1_000_000));
    }

    /**
     * @throws ConfigurationException
     */
    private static function path(string $requestId): string
    {
        // See Events::get(): a dot segment would address another route.
        if ('' === $requestId || '.' === $requestId || '..' === $requestId) {
            throw new ConfigurationException('requestId is not a Fingerly request id');
        }

        return '/events/'.rawurlencode($requestId).'/device-check';
    }

    /**
     * Sends one spend, and the identical request again after an answer that
     * kept the token: a 503, a timeout or a connection failure. Nothing else
     * is retried.
     *
     * @return array<string, mixed>
     */
    private function spend(string $method, string $path, ?string $body): array
    {
        $headers = [
            'x-api-key' => $this->secretKey->reveal(),
            'accept' => 'application/json',
            'user-agent' => Transport::USER_AGENT,
        ];
        if (null !== $body) {
            $headers['content-type'] = 'application/json';
        }

        for ($attempt = 0;; ++$attempt) {
            $last = $attempt >= self::RETRIES;

            try {
                $response = $this->transport->send($method, $this->baseUrl.$path, $headers, $body);
            } catch (ConnectionException|TimeoutException $e) {
                if ($last) {
                    throw $e;
                }
                self::pause($attempt);

                continue;
            }

            if (503 === $response['status'] && !$last) {
                self::pause($attempt);

                continue;
            }
            if ($response['status'] < 200 || $response['status'] > 299) {
                throw $this->transport->apiException($response['status'], $response['body'], $response['headers']);
            }

            return Json::decode($response['body'], $response['status']);
        }
    }
}
