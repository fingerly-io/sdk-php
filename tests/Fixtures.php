<?php

declare(strict_types=1);

namespace Fingerly\Tests;

/** Response bodies the happy-path tests read back. */
final class Fixtures
{
    public static function event(string $requestId = 'req_1', ?int $score = 12): string
    {
        return json_encode([
            'request_id' => $requestId,
            'visitor_id' => 'vis_1',
            'occurred_at' => '2026-09-18T08:00:00Z',
            'state' => 'enriched',
            'environment' => 'production',
            'platform' => 'web',
            'sdk_platform' => 'web',
            'sdk_version' => '1.0.0',
            'ip_address' => '203.0.113.7',
            'suspect_score' => $score,
            'suspect_level' => null === $score ? null : 'low',
            'triggers' => [],
            'has_document' => false,
        ], \JSON_THROW_ON_ERROR);
    }

    /** The one retryable failure: the event has not been archived yet. */
    public static function notFound(): string
    {
        return json_encode([
            'error' => ['code' => 'event_not_found', 'message' => 'No such event', 'status' => 404],
            'request_id' => 'req_trace',
        ], \JSON_THROW_ON_ERROR);
    }

    public static function page(int $page = 1, int $pageSize = 50, int $rows = 1): string
    {
        $decoded = [];
        for ($index = 0; $index < $rows; ++$index) {
            $decoded[] = json_decode(self::event('req_'.$index), true, 32, \JSON_THROW_ON_ERROR);
        }

        return json_encode(
            ['rows' => $decoded, 'page' => $page, 'page_size' => $pageSize],
            \JSON_THROW_ON_ERROR,
        );
    }
}
