<?php

declare(strict_types=1);

namespace Fingerly\AgentSightings;

use Fingerly\Agents;
use Fingerly\AgentSightingReport;

/**
 * One served request, reduced to what may be sent and keyed as the contract
 * says.
 *
 * @internal
 */
final class Sighting
{
    /** The longest header value reported, in bytes; the API refuses a longer one. */
    public const MAX_HEADER_BYTES = 4096;

    /**
     * The bytes this item counts toward the 64 KiB flush trigger: its sent
     * strings, with no allowance for JSON names or quoting.
     */
    public readonly int $size;

    /** Whether the request carried both `Signature` and `Signature-Input`. */
    public readonly bool $signed;

    /** The section 4.1 cache key. */
    public readonly string $key;

    private function __construct(
        public readonly string $userAgent,
        public readonly string $clientIp,
        public readonly ?string $host,
        public readonly string $method,
        public readonly ?string $signature,
        public readonly ?string $signatureInput,
        public readonly ?string $signatureAgent,
    ) {
        $this->signed = null !== $signature && null !== $signatureInput;
        $this->key = self::key($userAgent, $clientIp, $signatureAgent ?? '', $this->signed);
        $this->size = strlen($userAgent) + strlen($clientIp) + strlen($host ?? '')
            + strlen($method) + strlen($signature ?? '') + strlen($signatureInput ?? '') + strlen($signatureAgent ?? '');
    }

    /**
     * Builds the sighting, or null when the request cannot be reported: a
     * user agent over the limit as the API reads it, or no single client
     * address.
     *
     * A Web Bot Auth header that is empty, only whitespace or over the limit
     * counts as absent before anything
     * else is decided, so the key and `signed` are computed from what is sent.
     * `Host` is sent as received, whatever its length, or left out when empty.
     */
    public static function of(
        string $userAgent,
        ?string $clientIp,
        string $host,
        string $method,
        string $signature,
        string $signatureInput,
        string $signatureAgent,
    ): ?self {
        if (self::sentLength($userAgent) > self::MAX_HEADER_BYTES) {
            return null;
        }
        $ip = null === $clientIp ? null : self::canonicalIp($clientIp);
        if (null === $ip) {
            return null;
        }

        return new self(
            $userAgent,
            $ip,
            '' === $host ? null : $host,
            $method,
            self::header($signature),
            self::header($signatureInput),
            self::header($signatureAgent),
        );
    }

    /**
     * `lowercase hex(first 16 bytes of SHA-256(user_agent 0x00 canonical_ip
     * 0x00 signature_agent 0x00 signed))`, with `signed` the byte `1` or `0`.
     */
    public static function key(string $userAgent, string $canonicalIp, string $signatureAgent, bool $signed): string
    {
        return bin2hex(substr(hash('sha256', $userAgent."\0".$canonicalIp."\0".$signatureAgent."\0".($signed ? '1' : '0'), true), 0, 16));
    }

    /**
     * The address in its canonical text form, or null when it is not exactly
     * one IPv4 or IPv6 literal (a list, padding and a zone id are not).
     *
     * An IPv4-mapped IPv6 address is the IPv4 address; IPv6 is printed as
     * RFC 5952 says: lowercase, no leading zeros, the longest run of two or
     * more zero groups (the first, on a tie) compressed, and never dotted.
     */
    public static function canonicalIp(string $value): ?string
    {
        if (false === filter_var($value, \FILTER_VALIDATE_IP)) {
            return null;
        }
        $bytes = inet_pton($value);
        if (false === $bytes) {
            return null;
        }
        if (4 === strlen($bytes)) {
            return (string) inet_ntop($bytes);
        }
        if (str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        /** @var list<int> $groups */
        $groups = array_values((array) unpack('n8', $bytes));
        $bestStart = -1;
        $bestLength = 0;
        for ($i = 0; $i < 8;) {
            if (0 !== $groups[$i]) {
                ++$i;

                continue;
            }
            $start = $i;
            while ($i < 8 && 0 === $groups[$i]) {
                ++$i;
            }
            if ($i - $start > $bestLength) {
                $bestStart = $start;
                $bestLength = $i - $start;
            }
        }
        $hex = array_map(static fn (int $group): string => dechex($group), $groups);
        if ($bestLength < 2) {
            return implode(':', $hex);
        }

        return implode(':', array_slice($hex, 0, $bestStart)).'::'.implode(':', array_slice($hex, $bestStart + $bestLength));
    }

    /** The item as {@see Agents::reportSightings()} sends it. */
    public function report(int $count): AgentSightingReport
    {
        return new AgentSightingReport(
            userAgent: $this->userAgent,
            clientIp: $this->clientIp,
            host: $this->host,
            method: $this->method,
            signature: $this->signature,
            signatureInput: $this->signatureInput,
            signatureAgent: $this->signatureAgent,
            count: $count,
        );
    }

    /** A header as sent: absent when empty, only whitespace, or over the limit. */
    private static function header(string $value): ?string
    {
        return '' === trim($value) || self::sentLength($value) > self::MAX_HEADER_BYTES ? null : $value;
    }

    /**
     * The bytes the API reads for a header. JSON carries each byte that is
     * not UTF-8 as U+FFFD, three bytes, so a value under the limit here can
     * be over it there, and the API then refuses the whole batch.
     */
    private static function sentLength(string $value): int
    {
        if (1 === preg_match('//u', $value)) {
            return strlen($value);
        }
        $sent = json_decode((string) json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE));

        return is_string($sent) ? strlen($sent) : \PHP_INT_MAX;
    }
}
