<?php

declare(strict_types=1);

namespace Fingerly;

/** The API's answer to one {@see Agents::reportSightings()} call. */
final class AgentSightingsAnswer
{
    /**
     * @param string              $rulesVersion names the classification rules; when it changes, every
     *                                          remembered answer is stale
     * @param list<AgentSighting> $items        one answer per reported request, in the order sent
     */
    public function __construct(
        public readonly string $rulesVersion,
        public readonly array $items,
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException if the body is not an answer this SDK can read
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        $rulesVersion = Json::string($body, 'rules_version');
        $raw = $body['items'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ApiException(200, 'invalid_response', ['field' => 'items']);
        }
        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new ApiException(200, 'invalid_response', ['field' => 'items']);
            }

            /** @var array<string, mixed> $item */
            $items[] = AgentSighting::fromArray($item);
        }

        return new self($rulesVersion, $items);
    }
}
