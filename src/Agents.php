<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Reports the requests your server served, so AI crawlers and fetchers that
 * never run a page's JavaScript are seen too.
 *
 * Reached as `$fingerly->agents`; it is never constructed directly. This is
 * the one call underneath {@see AgentSightings}, which batches, counts and
 * remembers the answers for you; call it directly only to report from
 * somewhere {@see AgentSightings} cannot run.
 */
final class Agents
{
    /** The most items one call may carry. */
    public const MAX_ITEMS = 200;

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
     * Sends up to 200 served requests and answers what sent each one, in the
     * same order.
     *
     * @param list<AgentSightingReport> $items
     *
     * @throws ConfigurationException if there are no items or more than 200
     * @throws ApiException           `429` with {@see ApiException::getRetryAfter()} and
     *                                {@see ApiException::getRateLimitScope()} when a limit or the daily
     *                                allowance refused the call; `422 invalid_request` for an item the
     *                                API cannot read; `401` / `403` for a wrong or revoked key
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function reportSightings(array $items): AgentSightingsAnswer
    {
        if ([] === $items || count($items) > self::MAX_ITEMS) {
            throw new ConfigurationException('reportSightings takes between 1 and 200 items');
        }
        $body = json_encode(
            ['items' => array_map(static fn (AgentSightingReport $item): array => $item->toArray(), array_values($items))],
            // A header that is not UTF-8 cannot travel in JSON as it is; the
            // API would read it with the same replacement characters.
            \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
        );
        $response = $this->transport->send('POST', $this->baseUrl.'/agents/sightings', [
            'x-api-key' => $this->secretKey->reveal(),
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'user-agent' => Transport::USER_AGENT,
        ], $body);

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw $this->transport->apiException($response['status'], $response['body'], $response['headers']);
        }

        return AgentSightingsAnswer::fromArray(Json::decode($response['body'], $response['status']));
    }
}
