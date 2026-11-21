<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * What an AI agent's operator says its traffic is for: `AiAgent::$purpose`.
 * The API can add values, so keep a default branch.
 */
final class AiPurpose
{
    /** Collecting content to train models. */
    public const TRAINING = 'training';

    /** Indexing for an AI answer or search product. */
    public const SEARCH = 'search';

    /** Reading a page a person asked an assistant to read. */
    public const USER_FETCH = 'user_fetch';

    /** Driving a browser on a person's behalf. */
    public const AGENT = 'agent';

    /** Every purpose, in the order listed. */
    public const ALL = [self::TRAINING, self::SEARCH, self::USER_FETCH, self::AGENT];

    private function __construct() {}
}
