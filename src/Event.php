<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * A stored identification event.
 *
 * It mirrors the server schema with two deliberate differences:
 * {@see self::$suspectScore} is `null` when the request was not scored and
 * must never be read as zero, and the archived `document` stays an opaque
 * array rather than becoming a second public contract.
 */
final class Event
{
    /**
     * @param string                    $requestId        the id identification returned, and the key event reads use
     * @param string                    $visitorId        the visitor this identification resolved to
     * @param \DateTimeImmutable        $occurredAt       when the identification happened
     * @param string                    $state            `enriched`, or `unavailable` when the network lookup could not run
     * @param string                    $environment      the deployment the event was recorded in
     * @param string                    $platform         the scoring profile applied; it differs from $sdkPlatform, since
     *                                                    `react-native-ios` reports itself as such and is scored as `ios`
     * @param string                    $sdkPlatform      what the SDK called itself
     * @param string                    $sdkVersion       the SDK build that submitted the identification
     * @param null|int                  $suspectScore     null when the request was not scored; never read null as 0
     * @param null|string               $suspectLevel     `low`, `medium` or `high`; null when not scored
     * @param list<EventTrigger>        $triggers         never null from the API: an event about which nothing fired
     *                                                    carries an empty list
     * @param bool                      $hasDocument      whether the full submission was archived
     * @param null|string               $origin           the page's origin, when the browser sent one
     * @param null|string               $urlHost          the host of the page the identification was made from
     * @param null|string               $urlPath          the path of that page
     * @param null|string               $tag              your own reference, as the client sent it
     * @param null|string               $ipNetwork        the network the request came from: an IPv4 /24 or an IPv6
     *                                                    /40, such as `203.0.113.0/24`; the API never returns the
     *                                                    address itself
     * @param null|string               $countryCode      ISO 3166-1 alpha-2 country the address resolved to
     * @param null|string               $countryName      the country's name, as the geolocation database spells it
     * @param null|string               $asn              the autonomous system announcing the address
     * @param null|string               $asnName          that network's name, such as a carrier or a hosting provider
     * @param null|string               $anonymityNetwork `tor`, `vpn`, `proxy` or `hosting`; null on an ordinary
     *                                                    consumer connection, which is most of them
     * @param null|string               $agent            the browser or app family, such as `chrome`
     * @param null|string               $agentVersion     its major version, as far as the agent admits to one
     * @param null|string               $os               the operating system family
     * @param null|string               $deviceKind       `desktop`, `mobile`, `tablet` or similar
     * @param null|string               $visitorKind      what the visitor appears to be, such as `human` or `bot`
     * @param null|array<string, mixed> $document         an allow-listed view of the archived submission: schema,
     *                                                    request id, time, SDK platform and version, IP network,
     *                                                    the classified IP (network, city-level location,
     *                                                    detections) or its error, risk, attestation and velocity;
     *                                                    no raw signals, user agent or address. Deliberately
     *                                                    opaque, not a public contract
     * @param array<string, mixed>      $raw              the response body exactly as it arrived, so a field the API adds
     *                                                    after this SDK was released is still reachable
     */
    public function __construct(
        public readonly string $requestId,
        public readonly string $visitorId,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly string $state,
        public readonly string $environment,
        public readonly string $platform,
        public readonly string $sdkPlatform,
        public readonly string $sdkVersion,
        public readonly ?int $suspectScore,
        public readonly ?string $suspectLevel,
        public readonly array $triggers,
        public readonly bool $hasDocument,
        public readonly ?string $origin = null,
        public readonly ?string $urlHost = null,
        public readonly ?string $urlPath = null,
        public readonly ?string $tag = null,
        public readonly ?string $ipNetwork = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $countryName = null,
        public readonly ?string $asn = null,
        public readonly ?string $asnName = null,
        public readonly ?string $anonymityNetwork = null,
        public readonly ?string $agent = null,
        public readonly ?string $agentVersion = null,
        public readonly ?string $os = null,
        public readonly ?string $deviceKind = null,
        public readonly ?string $visitorKind = null,
        public readonly ?array $document = null,
        public readonly array $raw = [],
    ) {}

    /**
     * Whether the API assigned this event a suspect score.
     *
     * Prefer it to a null check: it states the question being asked. A visitor
     * about whom nothing fired scores 0, while a submission that was never
     * enriched has no score at all.
     */
    public function isScored(): bool
    {
        return null !== $this->suspectScore;
    }

    /**
     * Builds an event from one decoded API response.
     *
     * @param array<string, mixed> $body
     *
     * @throws ApiException if the body is not an event this SDK can read
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        return new self(
            requestId: Json::string($body, 'request_id'),
            visitorId: Json::string($body, 'visitor_id'),
            occurredAt: Json::timestamp($body, 'occurred_at'),
            state: Json::string($body, 'state'),
            environment: Json::string($body, 'environment'),
            platform: Json::string($body, 'platform'),
            sdkPlatform: Json::string($body, 'sdk_platform'),
            sdkVersion: Json::string($body, 'sdk_version'),
            suspectScore: Json::nullableInt($body, 'suspect_score'),
            suspectLevel: Json::nullableString($body, 'suspect_level'),
            triggers: EventTrigger::listFrom($body['triggers'] ?? []),
            hasDocument: (bool) ($body['has_document'] ?? false),
            origin: Json::nullableString($body, 'origin'),
            urlHost: Json::nullableString($body, 'url_host'),
            urlPath: Json::nullableString($body, 'url_path'),
            tag: Json::nullableString($body, 'tag'),
            ipNetwork: Json::nullableString($body, 'ip_network'),
            countryCode: Json::nullableString($body, 'country_code'),
            countryName: Json::nullableString($body, 'country_name'),
            asn: Json::nullableString($body, 'asn'),
            asnName: Json::nullableString($body, 'asn_name'),
            anonymityNetwork: Json::nullableString($body, 'anonymity_network'),
            agent: Json::nullableString($body, 'agent'),
            agentVersion: Json::nullableString($body, 'agent_version'),
            os: Json::nullableString($body, 'os'),
            deviceKind: Json::nullableString($body, 'device_kind'),
            visitorKind: Json::nullableString($body, 'visitor_kind'),
            document: Json::nullableArray($body, 'document'),
            raw: $body,
        );
    }
}
