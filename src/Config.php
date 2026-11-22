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
     * Validates a caller-supplied API origin: the client's `endpoint` or the
     * proxy's `upstream`.
     *
     * A key travels with every request sent there, so the value is held to a
     * bare origin: `https://host` or `https://host:port`, printable ASCII
     * only, with no credentials, path, query or fragment. Plain HTTP is
     * accepted only for 127.0.0.1, localhost or [::1], and only when the
     * caller opted in with `allow_insecure_loopback`, for a local test
     * harness. The result is rebuilt from the parsed parts rather than the
     * input. No error names the value, which may have been pasted with a
     * secret in it.
     *
     * @param 'endpoint'|'upstream' $option the option name errors refer to
     *
     * @throws ConfigurationException
     */
    public static function bareOrigin(#[\SensitiveParameter] string $value, bool $allowInsecureLoopback, string $option): string
    {
        $invalid = $option.' must be a bare origin such as https://api.fingerly.io, with no credentials, path, query or fragment';
        if (1 !== preg_match('/\A[\x21-\x7e]+\z/', $value) || 1 === preg_match('/[?#@\\\]/', $value)) {
            throw new ConfigurationException($invalid);
        }
        $parts = parse_url($value);
        if (false === $parts
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))
        ) {
            throw new ConfigurationException($invalid);
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (1 !== preg_match('/\A(?:[a-z0-9-]+(?:\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])\z/', $host)) {
            throw new ConfigurationException($invalid);
        }
        if ('https' !== $scheme) {
            if ('http' !== $scheme) {
                throw new ConfigurationException($invalid);
            }
            if (!$allowInsecureLoopback || !in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) {
                throw new ConfigurationException(
                    $option.' must use https; plain http is allowed only for 127.0.0.1, localhost or [::1] with allow_insecure_loopback',
                );
            }
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Validates an API route version before it is spliced into a URL.
     *
     * @throws ConfigurationException
     */
    public static function normalizeApiVersion(#[\SensitiveParameter] mixed $version): string
    {
        $version ??= 'v1';
        if (!is_string($version) || 1 !== preg_match(self::API_VERSION_PATTERN, $version)) {
            throw new ConfigurationException('api_version must be a version such as "v1" or "v2"');
        }

        return $version;
    }

    /**
     * Validates a duration or size option.
     *
     * @throws ConfigurationException
     */
    public static function positiveNumber(#[\SensitiveParameter] mixed $value, string $name): float
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
    public static function positiveInt(#[\SensitiveParameter] mixed $value, string $name): int
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
    public static function normalizePath(#[\SensitiveParameter] mixed $value): string
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
     * It takes the option names only, never the options: the array holds a
     * key, and an exception thrown here would carry this frame's arguments
     * in its trace.
     *
     * @param list<int|string> $names
     * @param list<string>     $known
     *
     * @throws ConfigurationException
     */
    public static function rejectUnknownKeys(#[\SensitiveParameter] array $names, array $known): void
    {
        $unknown = array_map(
            // A name is echoed only when it looks like an option name, and
            // never when it looks like a key that ended up in the wrong place.
            static fn (int|string $name): string => 1 === preg_match('/\A[A-Za-z0-9_]{1,64}\z/', (string) $name)
                && !str_starts_with((string) $name, 'fly_') ? (string) $name : '(invalid name)',
            array_values(array_diff($names, $known)),
        );
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
