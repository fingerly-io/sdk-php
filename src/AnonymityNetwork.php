<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * The anonymity network an event's address belongs to:
 * `Event::$anonymityNetwork`, null on an ordinary connection.
 */
final class AnonymityNetwork
{
    public const TOR = 'tor';
    public const RELAY = 'relay';
    public const VPN = 'vpn';
    public const PROXY = 'proxy';
    public const HOSTING = 'hosting';

    /** Every network, in the order listed. */
    public const ALL = [self::TOR, self::RELAY, self::VPN, self::PROXY, self::HOSTING];

    private function __construct() {}
}
