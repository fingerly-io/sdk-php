<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Reads, verifies and consumes events stored for the organisation and
 * environment of the secret key.
 *
 * Reached as `$fingerly->events`; it is never constructed directly.
 */
final class Events
{
    private const LIST_OPTIONS = ['from', 'to', 'page', 'limit', 'visitor', 'level'];

    private const VERIFY_OPTIONS = ['max_age_seconds', 'tag', 'consume'];

    /** How old an event {@see self::verify()} accepts by default, in seconds. */
    private const DEFAULT_MAX_AGE = 300;

    /** Date, time, optional fraction and offset, as RFC 3339 writes them. */
    private const RFC3339 = '/\A(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(\.\d+)?([Zz]|[+-]\d{2}:\d{2})\z/';

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
     * @throws ApiException           `404 event_pending` ({@see ApiException::isPending()}) while
     *                                the event is still being recorded: retry after
     *                                {@see ApiException::getRetryAfter()} seconds.
     *                                `404 event_not_found` for a request id that was never issued,
     *                                or one past retention
     * @throws ConfigurationException if the request id is empty, `.` or `..`
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function get(string $requestId): Event
    {
        return Event::fromArray($this->read(self::path($requestId)));
    }

    /**
     * Marks an event as used, once, so it backs one decision.
     *
     * The first call answers with `first` true; every later call throws
     * {@see EventAlreadyConsumedException}. It is never retried: if the
     * answer is lost, a second call says the event was already consumed.
     *
     * @throws EventAlreadyConsumedException `409 event_already_consumed`, with
     *                                       {@see EventAlreadyConsumedException::getConsumedAt()}
     * @throws ApiException                  `404 event_not_found` for a request id that was never
     *                                       issued, or one past retention
     * @throws ConfigurationException        if the request id is empty, `.` or `..`
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function consume(string $requestId): EventConsumption
    {
        $path = self::path($requestId).'/consume';
        $response = $this->transport->send('POST', $this->baseUrl.$path, $this->headers());

        if ($response['status'] < 200 || $response['status'] > 299) {
            $error = $this->transport->apiException($response['status'], $response['body'], $response['headers']);
            if (409 === $response['status']) {
                $consumed = self::alreadyConsumed($error, $response['body']);
                if (null !== $consumed) {
                    throw $consumed;
                }
            }

            throw $error;
        }

        return EventConsumption::fromArray(Json::decode($response['body'], $response['status']));
    }

    /**
     * Reads an event and checks it can back one backend action, then
     * consumes it.
     *
     * The checks run in this order and stop at the first that fails, whose
     * reason the result carries:
     *
     * 1. `not_found`: no such event.
     * 2. `too_old`: it happened more than `max_age_seconds` ago, or its time
     *    cannot be read. A time in the future passes.
     * 3. `tag_mismatch`: `tag` was given and the event's tag (`''` when it has
     *    none) is not exactly it.
     * 4. `unidentifiable`: its visitor id is empty.
     * 5. `unscored`: it has no suspect score.
     * 6. `already_consumed`: consuming it found another call had already done
     *    so. Consumption happens only when `consume` is true and every check
     *    above passed; `not_found` here means the event went in between.
     *
     * It never looks at the score's value, the level or the triggers: what
     * they mean for the action is the caller's decision.
     *
     * @param array<string, mixed> $options `max_age_seconds`, a positive integer, 300 when left out;
     *                                      `tag`, a non-empty string, or left out for no tag check;
     *                                      `consume`, a boolean, true when left out. Like
     *                                      {@see self::list()}, it is deliberately not a shape.
     *
     * @throws ApiException           unchanged for anything but `event_not_found` and
     *                                `event_already_consumed`, including `404 event_pending`
     *                                ({@see ApiException::isPending()}), which is not a verdict
     * @throws ConfigurationException if an option is not usable, or the request id is empty, `.` or `..`
     * @throws TimeoutException
     * @throws ConnectionException
     */
    public function verify(string $requestId, array $options = []): EventVerification
    {
        Config::rejectUnknownKeys(array_keys($options), self::VERIFY_OPTIONS);
        $maxAge = isset($options['max_age_seconds'])
            ? Config::positiveInt($options['max_age_seconds'], 'max_age_seconds')
            : self::DEFAULT_MAX_AGE;
        $tag = $options['tag'] ?? null;
        if (null !== $tag && (!is_string($tag) || '' === $tag)) {
            // An empty tag would match every untagged event.
            throw new ConfigurationException('tag must be a non-empty string');
        }
        $consume = $options['consume'] ?? true;
        if (!is_bool($consume)) {
            throw new ConfigurationException('consume must be a boolean');
        }
        $path = self::path($requestId);

        try {
            $body = $this->read($path);
        } catch (ApiException $e) {
            if ($e->isEventNotFound()) {
                return new EventVerification(false, EventVerification::REASON_NOT_FOUND, null);
            }

            throw $e;
        }
        $event = Event::fromArray($body, true);

        $occurredAt = self::parseRfc3339($body['occurred_at'] ?? null);
        if (null === $occurredAt || microtime(true) - $occurredAt > $maxAge) {
            return new EventVerification(false, EventVerification::REASON_TOO_OLD, $event);
        }
        if (null !== $tag && $tag !== ($event->tag ?? '')) {
            return new EventVerification(false, EventVerification::REASON_TAG_MISMATCH, $event);
        }
        if ('' === $event->visitorId) {
            return new EventVerification(false, EventVerification::REASON_UNIDENTIFIABLE, $event);
        }
        if (!$event->isScored()) {
            return new EventVerification(false, EventVerification::REASON_UNSCORED, $event);
        }

        if ($consume) {
            try {
                $this->consume($requestId);
            } catch (EventAlreadyConsumedException) {
                return new EventVerification(false, EventVerification::REASON_ALREADY_CONSUMED, $event);
            } catch (ApiException $e) {
                if ($e->isEventNotFound()) {
                    return new EventVerification(false, EventVerification::REASON_NOT_FOUND, null);
                }

                throw $e;
            }
        }

        return new EventVerification(true, '', $event);
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
        Config::rejectUnknownKeys(array_keys($query), self::LIST_OPTIONS);

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
        $response = $this->transport->send('GET', $this->baseUrl.$path, $this->headers());

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw $this->transport->apiException($response['status'], $response['body'], $response['headers']);
        }

        return Json::decode($response['body'], $response['status']);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'x-api-key' => $this->secretKey->reveal(),
            'accept' => 'application/json',
            'user-agent' => Transport::USER_AGENT,
        ];
    }

    /**
     * The path of one event.
     *
     * @throws ConfigurationException
     */
    private static function path(string $requestId): string
    {
        // URL resolvers treat a dot segment as one even when it is
        // percent-encoded, so escaping cannot keep these in the path: `.` and
        // the empty id would name the list route, and `..` a route above it,
        // secret key attached.
        if ('' === $requestId || '.' === $requestId || '..' === $requestId) {
            throw new ConfigurationException('requestId is not a Fingerly request id');
        }

        return '/events/'.rawurlencode($requestId);
    }

    /**
     * Recognises a `409 event_already_consumed`, whose body may carry the
     * code in the error envelope or at the top level, and the time it was
     * consumed at the top level, in the envelope, or as the error detail
     * whose `field` is `consumed_at`.
     *
     * Only a value shaped like an RFC 3339 time is kept, so nothing else from
     * the body reaches the exception.
     */
    private static function alreadyConsumed(ApiException $error, string $body): ?EventAlreadyConsumedException
    {
        $decoded = json_decode(substr($body, 0, 1048576), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $envelope = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        $code = $error->getErrorCode() ?? (is_string($decoded['code'] ?? null) ? $decoded['code'] : null);
        if ('event_already_consumed' !== $code) {
            return null;
        }

        $candidates = [$decoded['consumed_at'] ?? null, $envelope['consumed_at'] ?? null];
        $details = $error->getDetails();
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && 'consumed_at' === ($detail['field'] ?? null)) {
                    $candidates[] = $detail['issue'] ?? null;
                }
            }
        }
        $consumedAt = null;
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && 1 === preg_match(self::RFC3339, $candidate)) {
                $consumedAt = $candidate;

                break;
            }
        }

        return new EventAlreadyConsumedException($consumedAt, $details, $error->getRequestId(), $error->getRetryAfter());
    }

    /**
     * Reads an RFC 3339 time as seconds since the epoch, or null when it is
     * missing or not one. Stricter than `DateTimeImmutable`, which also takes
     * words such as `tomorrow` and rolls an impossible date over.
     */
    private static function parseRfc3339(mixed $value): ?float
    {
        if (!is_string($value) || 1 !== preg_match(self::RFC3339, $value, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }
        $fraction = '' === $m[7] ? '' : '.'.substr($m[7], 1, 6);
        $zone = 'Z' === strtoupper($m[8]) ? '+00:00' : $m[8];
        if ('Z' !== strtoupper($m[8]) && ((int) substr($zone, 1, 2) > 23 || (int) substr($zone, 4, 2) > 59)) {
            return null;
        }

        try {
            $at = new \DateTimeImmutable(sprintf('%s-%s-%sT%s:%s:%s%s%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $fraction, $zone));
        } catch (\Exception) {
            return null;
        }

        return (float) $at->format('U.u');
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
