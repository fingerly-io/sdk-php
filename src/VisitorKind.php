<?php

declare(strict_types=1);

namespace Fingerly;

/** What is behind a submission: `Event::$visitorKind` and `AgentSighting::$visitorKind`. */
final class VisitorKind
{
    public const HUMAN = 'human';
    public const AI_BOT = 'ai_bot';
    public const AUTOMATION = 'automation';
    public const SEARCH_CRAWLER = 'search_crawler';

    /** Only in an agent sightings answer: a request the server could not use; ttl 0, never remembered. */
    public const UNKNOWN = 'unknown';

    /** Every kind, in the order listed. */
    public const ALL = [self::HUMAN, self::AI_BOT, self::AUTOMATION, self::SEARCH_CRAWLER, self::UNKNOWN];

    private function __construct() {}
}
