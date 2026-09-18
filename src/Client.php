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
    public const VERSION = '0.1.0';

    private const DEFAULT_TIMEOUT = 5.0;

    private const OPTIONS = [
        'endpoint',
        'api_version',
        'timeout',
        'http_client',
        'request_factory',
        'stream_factory',
    ];

    /** Event reads under this client's secret key. */
    public readonly Events $events;

    /**
     * @param string               $secretKey a server-only key, `fly_sk_{eu|us}_{environment}_…`, whose prefix
     *                                        selects the regional API and the environment read
     * @param array<string, mixed> $options   endpoint, api_version, timeout, http_client, request_factory,
     *                                        stream_factory
     *
     * @throws ConfigurationException if any option is invalid; nothing is sent until then
     */
    public function __construct(string $secretKey, array $options = [])
    {
        Config::rejectUnknown($options, self::OPTIONS);

        // Validate the key even when an endpoint is given, so a public, proxy
        // or management key can never reach an API that would accept it.
        $scope = Config::parseKey($secretKey, Config::KEY_SECRET);
        $endpoint = $options['endpoint'] ?? null;
        $origin = Config::normalizeOrigin(
            is_string($endpoint) ? $endpoint : Config::regionalOrigin($scope),
        );
        $apiVersion = $options['api_version'] ?? null;
        $apiVersion = Config::normalizeApiVersion(is_string($apiVersion) ? $apiVersion : null);
        $timeout = Config::positiveNumber($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout');

        $httpClient = $options['http_client'] ?? null;
        $requestFactory = $options['request_factory'] ?? null;
        if (null !== $httpClient && null === $requestFactory) {
            throw new ConfigurationException('request_factory is required alongside http_client');
        }

        $this->events = new Events(
            new Transport(
                timeout: $timeout,
                redactKey: $secretKey,
                httpClient: is_object($httpClient) ? $httpClient : null,
                requestFactory: is_object($requestFactory) ? $requestFactory : null,
                streamFactory: is_object($options['stream_factory'] ?? null) ? $options['stream_factory'] : null,
            ),
            $origin.'/api/'.$apiVersion,
            $secretKey,
        );
    }
}
