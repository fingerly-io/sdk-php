<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Reads events stored for the organisation and environment of the secret key.
 *
 * Reached as `$fingerly->events`; it is never constructed directly.
 */
final class Events
{
    private const LIST_OPTIONS = ['from', 'to', 'page', 'limit', 'visitor', 'level'];

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
     * Fetches one event by request id.
     *
     * @throws ApiException        `404 event_not_found` when there is no such event. One is
     *                             readable as soon as identification answers, so this means a
     *                             request id that was never issued
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function get(string $requestId): Event
    {
        return Event::fromArray($this->read('/events/'.rawurlencode($requestId)));
    }

    /**
     * Lists events within the retention window, newest first.
     *
     * An omitted filter is not sent, so the server applies its own default.
     *
     * @param array<string, mixed> $query `from` and `to` bound the window and take a
     *                                    `DateTimeInterface` or an RFC 3339 string; `page` and
     *                                    `limit` are positive integers; `visitor` is one exact
     *                                    visitor id and `level` one of `low`, `medium`, `high`.
     *                                    It is deliberately not a shape: filters routinely come
     *                                    from a query string or a config file, where every value
     *                                    is `mixed`, and validating them here is the point.
     *
     * @throws ConfigurationException if a date, page or limit is not usable
     * @throws ApiException
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function list(array $query = []): EventPage
    {
        Config::rejectUnknown($query, self::LIST_OPTIONS);

        $params = [];
        if (isset($query['from'])) {
            $params['from'] = self::rfc3339($query['from'], 'from');
        }
        if (isset($query['to'])) {
            $params['to'] = self::rfc3339($query['to'], 'to');
        }
        if (isset($query['page'])) {
            $params['page'] = (string) Config::positiveInt($query['page'], 'page');
        }
        if (isset($query['limit'])) {
            $params['limit'] = (string) Config::positiveInt($query['limit'], 'limit');
        }
        if (isset($query['visitor'])) {
            $params['visitor'] = (string) $query['visitor'];
        }
        if (isset($query['level'])) {
            $params['level'] = (string) $query['level'];
        }

        $path = '/events';
        if ([] !== $params) {
            $path .= '?'.http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
        }

        return EventPage::fromArray($this->read($path));
    }

    /**
     * Performs one authenticated GET and decodes its JSON body.
     *
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        $response = $this->transport->send('GET', $this->baseUrl.$path, [
            'x-api-key' => $this->secretKey->reveal(),
            'accept' => 'application/json',
            'user-agent' => Transport::USER_AGENT,
        ]);

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw $this->transport->apiException($response['status'], $response['body']);
        }

        return Json::decode($response['body'], $response['status']);
    }

    /**
     * Renders a window bound in the RFC 3339 form the API expects.
     *
     * A string passes through, so a caller can send a server-supplied cursor
     * unchanged.
     *
     * @throws ConfigurationException
     */
    private static function rfc3339(mixed $value, string $name): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }

        throw new ConfigurationException(sprintf('%s must be a DateTimeInterface or an RFC 3339 string', $name));
    }
}
