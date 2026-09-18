<?php

declare(strict_types=1);

namespace Fingerly;

/** One page of events, newest first. */
final class EventPage
{
    /**
     * @param list<Event>          $rows     the events in this page
     * @param int                  $page     the 1-based page number this is
     * @param int                  $pageSize how many rows a full page holds
     * @param array<string, mixed> $raw      the response body exactly as it arrived
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $raw = [],
    ) {}

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException if the body is not a page this SDK can read
     *
     * @internal
     */
    public static function fromArray(array $body): self
    {
        $rows = [];
        foreach (is_array($body['rows'] ?? null) ? $body['rows'] : [] as $row) {
            if (is_array($row)) {
                $rows[] = Event::fromArray($row);
            }
        }

        return new self(
            rows: $rows,
            page: (int) ($body['page'] ?? 0),
            pageSize: (int) ($body['page_size'] ?? 0),
            raw: $body,
        );
    }
}
