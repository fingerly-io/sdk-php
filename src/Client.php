<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The Fingerly server SDK.
 *
 * Construct it once and reuse it. Secret keys and webhook secrets belong in
 * server-side code only; a browser must never see either.
 *
 * ```php
 * $fingerly = new \Fingerly\Client(getenv('FINGERLY_SECRET_KEY'));
 * $event = $fingerly->events->get($requestId);
 * $page = $fingerly->events->list(['level' => 'high', 'limit' => 50]);
 * ```
 */
final class Client
{
    /** The SDK version, reported to the API on every request. */
    public const VERSION = '0.5.1';

    private const DEFAULT_TIMEOUT = 5.0;

    private const OPTIONS = [
        'endpoint',
        'allow_insecure_loopback',
        'api_version',
        'timeout',
        'http_client',
        'request_factory',
        'stream_factory',
    ];

    /** Event reads under this client's secret key. */
    public readonly Events $events;

    /** Reads and writes the Apple DeviceCheck bits behind an iOS event. */
    public readonly DeviceCheck $deviceCheck;

    /** Reports the requests your server served; {@see AgentSightings} builds on it. */
    public readonly Agents $agents;

    /**
     * @param string               $secretKey a server-only key, `fly_sk_{environment}_…`, whose prefix
     *                                        names the environment read
     * @param array<string, mixed> $options   endpoint (a bare HTTPS origin), allow_insecure_loopback (true
     *                                        lets endpoint be plain HTTP on 127.0.0.1, localhost or [::1]
     *                                        for a local test harness), api_version, timeout, http_client,
     *                                        request_factory, stream_factory
     *
     * @throws ConfigurationException if any option is invalid; nothing is sent until then
     */
    public function __construct(#[\SensitiveParameter] string $secretKey, #[\SensitiveParameter] array $options = [])
    {
        Config::rejectUnknownKeys(array_keys($options), self::OPTIONS);

        // Validate the key even when an endpoint is given, so a public, proxy
        // or management key can never reach an API that would accept it.
        Config::parseKey($secretKey, Config::KEY_SECRET);
        $endpoint = $options['endpoint'] ?? null;
        if (null !== $endpoint && !is_string($endpoint)) {
            throw new ConfigurationException('endpoint must be a string');
        }
        $allowInsecureLoopback = $options['allow_insecure_loopback'] ?? false;
        if (!is_bool($allowInsecureLoopback)) {
            throw new ConfigurationException('allow_insecure_loopback must be a boolean');
        }
        $origin = Config::bareOrigin($endpoint ?? Config::DEFAULT_ENDPOINT, $allowInsecureLoopback, 'endpoint');
        $apiVersion = Config::normalizeApiVersion($options['api_version'] ?? null);
        $timeout = Config::positiveNumber($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout');

        $httpClient = $options['http_client'] ?? null;
        $requestFactory = $options['request_factory'] ?? null;
        $streamFactory = $options['stream_factory'] ?? null;
        // Checked by name, so neither PSR package has to be installed for a
        // build that never passes a client.
        if (null !== $httpClient && !is_a($httpClient, 'Psr\Http\Client\ClientInterface')) {
            throw new ConfigurationException('http_client must be a PSR-18 Psr\Http\Client\ClientInterface');
        }
        if (null !== $httpClient && null === $requestFactory) {
            throw new ConfigurationException('request_factory is required alongside http_client');
        }
        if (null !== $requestFactory && !is_a($requestFactory, 'Psr\Http\Message\RequestFactoryInterface')) {
            throw new ConfigurationException('request_factory must be a PSR-17 Psr\Http\Message\RequestFactoryInterface');
        }
        if (null !== $streamFactory && !is_a($streamFactory, 'Psr\Http\Message\StreamFactoryInterface')) {
            throw new ConfigurationException('stream_factory must be a PSR-17 Psr\Http\Message\StreamFactoryInterface');
        }
        if (null === $httpClient && (null !== $requestFactory || null !== $streamFactory)) {
            throw new ConfigurationException('request_factory and stream_factory are used only alongside http_client');
        }

        $secret = new Secret($secretKey);
        $transport = new Transport(
            timeout: $timeout,
            redactKey: $secret,
            httpClient: is_object($httpClient) ? $httpClient : null,
            requestFactory: is_object($requestFactory) ? $requestFactory : null,
            streamFactory: is_object($streamFactory) ? $streamFactory : null,
        );
        $this->events = new Events($transport, $origin.'/api/'.$apiVersion, $secret);
        $this->deviceCheck = new DeviceCheck($transport, $origin.'/api/'.$apiVersion, $secret);
        $this->agents = new Agents($transport, $origin.'/api/'.$apiVersion, $secret);
    }
}
