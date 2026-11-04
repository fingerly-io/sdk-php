<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Webhook signature verification.
 *
 * Stateless, so no client instance is needed, and it touches neither the
 * network nor ext-curl.
 */
final class Webhook
{
    /** How far a delivery's timestamp may be from now before it is refused. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * How many comma-separated signatures are considered. A rotation sends
     * two; the cap keeps a hostile header from buying one HMAC comparison per
     * comma.
     */
    public const MAX_SIGNATURES = 32;

    /** A lowercase hex digest, exactly as Fingerly sends it. */
    private const SIGNATURE_PATTERN = '/\Asha256=[a-f0-9]{64}\z/';

    /** Whole unsigned seconds; anything else is not a timestamp we signed. */
    private const TIMESTAMP_PATTERN = '/\A[0-9]+\z/';

    private const PREFIX_LENGTH = 7;

    /**
     * Verifies a webhook delivery against the raw request body.
     *
     * Pass the exact bytes received, before any JSON parsing: re-serialising
     * them changes the digest. During a signing-secret rotation Fingerly sends
     * several comma-separated signatures, and any one matching is accepted.
     *
     * Verification proves the delivery came from Fingerly and is recent. It
     * does not deduplicate, so persist processed {@see WebhookEvent::$id}
     * values as well.
     *
     * Malformed or missing input is a false result, never an exception.
     *
     * ```php
     * $valid = \Fingerly\Webhook::verify(
     *     secret: getenv('FINGERLY_WEBHOOK_SECRET'),
     *     payload: $rawBody,
     *     timestamp: $request->header('x-fingerly-timestamp'),
     *     signature: $request->header('x-fingerly-signature'),
     * );
     * ```
     *
     * @param string                  $secret    the endpoint's signing secret
     * @param string                  $payload   the exact bytes received, before any JSON parsing
     * @param null|string             $timestamp the `x-fingerly-timestamp` header, in whole seconds
     * @param null|string             $signature the `x-fingerly-signature` header, which may hold several
     * @param int                     $tolerance replay window in seconds
     * @param null|\DateTimeInterface $now       current time; override only in tests
     */
    public static function verify(
        #[\SensitiveParameter]
        string $secret,
        string $payload,
        ?string $timestamp,
        ?string $signature,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?\DateTimeInterface $now = null,
    ): bool {
        if ('' === $secret || null === $timestamp || '' === $timestamp
            || null === $signature || '' === $signature) {
            return false;
        }
        // Check freshness before spending an HMAC on an obvious replay.
        if (!self::isFresh($timestamp, $now, $tolerance)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        // The limit leaves everything past the cap in one last element, which
        // is then dropped unread.
        $candidates = array_slice(explode(',', $signature, self::MAX_SIGNATURES + 1), 0, self::MAX_SIGNATURES);
        foreach ($candidates as $candidate) {
            if (self::matches($candidate, $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decodes a delivery envelope.
     *
     * Verify first: parsing an unverified payload is reading something anyone
     * could have sent.
     *
     * @throws WebhookException if the payload is not a delivery this SDK can read
     */
    public static function parse(string $payload): WebhookEvent
    {
        try {
            return WebhookEvent::fromArray(Json::decode($payload));
        } catch (ApiException $e) {
            // The JSON helpers speak in API errors; no API call was made here.
            $details = $e->getDetails();
            $field = is_array($details) && is_string($details['field'] ?? null) ? $details['field'] : null;

            throw new WebhookException(null === $field
                ? 'The webhook payload is not a JSON object'
                : sprintf('The webhook payload has no valid "%s" field', $field));
        }
    }

    /** Rejects replays: the signed time must be within tolerance of now. */
    private static function isFresh(string $timestamp, ?\DateTimeInterface $now, int $tolerance): bool
    {
        if (1 !== preg_match(self::TIMESTAMP_PATTERN, $timestamp) || $tolerance < 0) {
            return false;
        }

        $signedAt = (int) $timestamp;
        $nowSeconds = null === $now ? time() : $now->getTimestamp();

        return abs($nowSeconds - $signedAt) <= $tolerance;
    }

    /**
     * Compares one candidate signature in constant time.
     *
     * The candidate must be a lowercase hex digest behind `sha256=`, exactly
     * as Fingerly sends it. Uppercase is refused rather than accepted, because
     * Fingerly never sends it and a lenient parser is one more shape to reason
     * about.
     */
    private static function matches(string $candidate, string $expected): bool
    {
        $value = trim($candidate);
        if (1 !== preg_match(self::SIGNATURE_PATTERN, $value)) {
            return false;
        }

        return hash_equals($expected, substr($value, self::PREFIX_LENGTH));
    }
}
