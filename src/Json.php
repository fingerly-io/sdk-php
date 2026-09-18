<?php

declare(strict_types=1);

namespace Fingerly;

/**
 * Reads fields out of a decoded response without trusting their shape.
 *
 * A response that is not what this SDK expects is an {@see ApiException}, not
 * a `TypeError` thrown from inside a constructor. The message says which field
 * disagreed and never quotes its value, because the value is response content.
 *
 * @internal
 */
final class Json
{
    /**
     * Decodes a response body.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException if the body is not a JSON object
     */
    public static function decode(string $body, int $status = 200): array
    {
        try {
            $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException($status, 'invalid_response');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException($status, 'invalid_response');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws ApiException
     */
    public static function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (!is_string($value)) {
            throw new ApiException(200, 'invalid_response', ['field' => $field]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function nullableString(array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Reads a score, keeping null distinct from zero.
     *
     * @param array<string, mixed> $body
     */
    public static function nullableInt(array $body, string $field): ?int
    {
        $value = $body[$field] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return null|array<string, mixed>
     */
    public static function nullableArray(array $body, string $field): ?array
    {
        $value = $body[$field] ?? null;
        if (!is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Parses an RFC 3339 timestamp.
     *
     * @param array<string, mixed> $body
     *
     * @throws ApiException if the field is missing or is not a timestamp
     */
    public static function timestamp(array $body, string $field): \DateTimeImmutable
    {
        $value = self::string($body, $field);

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new ApiException(200, 'invalid_response', ['field' => $field]);
        }
    }
}
