<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * What the Fingerly API said sent one request your server served: one item of
 * the answer to `POST /api/v1/agents/sightings`.
 *
 * {@see AgentSightings::lookup()} answers with one of these, or with
 * {@see self::unknown()} when it has no answer for the request. The unknown
 * answer's `visitorKind` is {@see self::UNKNOWN}, so a check such as
 * `VisitorKind::AI_BOT === $answer->visitorKind` is false for it, and the
 * request is served as usual.
 *
 * `visitorKind` and the `ai` values are plain strings, so a value the API adds
 * later still reads.
 */
final class AgentSighting
{
    /**
     * The `visitorKind` of {@see self::unknown()}: there is no answer for the
     * request yet, so serve it as usual. The API never sends it.
     */
    public const UNKNOWN = 'unknown';

    private static ?self $unknown = null;

    /**
     * @param int          $ttl         seconds the answer may be reused for the same user agent, address
     *                                  and Signature-Agent; 0 for a signed request, which is checked every time
     * @param string       $visitorKind `human`, `ai_bot`, `automation` or `search_crawler`; see {@see VisitorKind}.
     *                                  {@see self::UNKNOWN} when there is no answer
     * @param null|string  $agent       the agent's name where the API knows it, such as `GPTBot` or `Googlebot`
     * @param null|AiAgent $ai          present when $visitorKind is {@see VisitorKind::AI_BOT}
     */
    public function __construct(
        public readonly int $ttl,
        public readonly string $visitorKind,
        public readonly ?string $agent = null,
        public readonly ?AiAgent $ai = null,
    ) {}

    /**
     * The one answer for a request the API has not answered: not reported yet,
     * not in scope, past its `ttl`, or reporting is off. Serve it as usual.
     */
    public static function unknown(): self
    {
        return self::$unknown ??= new self(0, self::UNKNOWN);
    }

    /** True for {@see self::unknown()}. */
    public function isUnknown(): bool
    {
        return self::UNKNOWN === $this->visitorKind;
    }

    /**
     * @param array<string, mixed> $item one item of the answer
     *
     * @throws ApiException if the item is not one this SDK can read
     *
     * @internal
     */
    public static function fromArray(array $item): self
    {
        $ttl = $item['ttl'] ?? null;
        if (!is_int($ttl) || $ttl < 0) {
            throw new ApiException(200, 'invalid_response', ['field' => 'ttl']);
        }

        return new self(
            ttl: $ttl,
            visitorKind: Json::string($item, 'visitor_kind'),
            agent: Json::nullableString($item, 'agent'),
            ai: AiAgent::fromMember($item['ai'] ?? null),
        );
    }
}
