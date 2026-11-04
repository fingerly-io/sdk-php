<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Validation and normalisation of caller-supplied configuration.
 *
 * Everything here runs before any network access, so a misconfigured client
 * fails at construction, never mid-request and never against the wrong host.
 *
 * @internal
 */
final class Config
{
    /** Kinds of key Fingerly issues: browser public, server secret, proxy. */
    public const KEY_PUBLIC = 'pk';
    public const KEY_SECRET = 'sk';
    public const KEY_PROXY = 'px';

    /** The one Fingerly API origin. */
    public const DEFAULT_ENDPOINT = 'https://api.fingerly.io';

    /**
     * `$` in PCRE also matches before a trailing newline, so the pattern is
     * anchored with \z instead. A key with a newline on the end is not a key.
     */
    private const KEY_PATTERN = '/\Afly_(pk|sk|px)_(production|staging|development)_[A-Za-z0-9_-]+\z/';

    private const API_VERSION_PATTERN = '/\Av[1-9][0-9]*\z/';

    private const KEY_LABELS = [
        self::KEY_PUBLIC => 'public',
        self::KEY_SECRET => 'secret',
        self::KEY_PROXY => 'proxy',
    ];

    /**
     * Parses a Fingerly key and asserts it is of the expected kind.
     *
     * @param 'pk'|'px'|'sk' $expected
     *
     * @return array{environment: string} the scope the key is bound to
     *
     * @throws ConfigurationException if the key is malformed or of another kind
     */
    public static function parseKey(#[\SensitiveParameter] string $key, string $expected): array
    {
        if (1 !== preg_match(self::KEY_PATTERN, $key, $match) || $match[1] !== $expected) {
            throw new ConfigurationException(
                sprintf('A valid Fingerly %s key is required', self::KEY_LABELS[$expected]),
            );
        }

        return ['environment' => $match[2]];
    }

    /**
     * Normalises a caller-supplied API origin.
     *
     * Credentials, queries and fragments are refused: they would be attached
     * to every authenticated request, and embedded credentials would leak on a
     * redirect. The result has no trailing slash.
     *
     * @throws ConfigurationException
     */
    public static function normalizeOrigin(string $value): string
    {
        $parts = parse_url($value);
        if (false === $parts
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new ConfigurationException(
                'endpoint must be an HTTP(S) URL without credentials, query, or fragment',
            );
        }

        return rtrim($value, '/');
    }

    /**
     * Validates an API route version before it is spliced into a URL.
     *
     * @throws ConfigurationException
     */
    public static function normalizeApiVersion(?string $version): string
    {
        $version ??= 'v1';
        if (1 !== preg_match(self::API_VERSION_PATTERN, $version)) {
            throw new ConfigurationException('api_version must be a version such as "v1" or "v2"');
        }

        return $version;
    }

    /**
     * Validates a duration or size option.
     *
     * @throws ConfigurationException
     */
    public static function positiveNumber(mixed $value, string $name): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new ConfigurationException(sprintf('%s must be a number', $name));
        }
        if (!is_finite((float) $value) || $value <= 0) {
            throw new ConfigurationException(sprintf('%s must be a positive number', $name));
        }

        return (float) $value;
    }

    /**
     * Validates a byte count.
     *
     * @throws ConfigurationException
     */
    public static function positiveInt(mixed $value, string $name): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new ConfigurationException(sprintf('%s must be a positive integer', $name));
        }

        return $value;
    }

    /**
     * Validates the path the dashboard issued for a proxy.
     *
     * It is matched byte for byte, so it has to be a path a request can carry
     * unchanged: slash-separated segments of unreserved characters, with no
     * trailing slash, no empty, `.` or `..` segment, and nothing encoded.
     *
     * @throws ConfigurationException
     */
    public static function normalizePath(mixed $value): string
    {
        $path = is_string($value) ? $value : '';
        $segments = explode('/', substr($path, 1));
        if (1 !== preg_match('#\A(?:/[A-Za-z0-9._~-]+)+\z#', $path)
            || [] !== array_intersect($segments, ['.', '..'])) {
            throw new ConfigurationException('path must be an absolute URL path such as /k7x2qm4a');
        }

        return $path;
    }

    /**
     * Refuses an option this build does not know.
     *
     * A misspelled option is otherwise silent, and a silently ignored timeout
     * or endpoint is exactly the kind of thing nobody notices until it matters.
     *
     * @param array<string, mixed> $options
     * @param list<string>         $known
     *
     * @throws ConfigurationException
     */
    public static function rejectUnknown(array $options, array $known): void
    {
        $unknown = array_diff(array_keys($options), $known);
        if ([] !== $unknown) {
            sort($unknown);

            throw new ConfigurationException(sprintf(
                'Unknown option%s: %s. Known options: %s',
                1 === count($unknown) ? '' : 's',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }
    }
}
