<?php

declare(strict_types=1);

namespace App\Document;

use App\Api\ApiException;

/**
 * Lenient type coercion for request parameters. Form-encoded clients send every value
 * as a string, JSON clients send real types; both must work.
 */
final class Coerce
{
    private const array TRUE = ['true', '1', 'yes', 'on'];
    private const array FALSE = ['false', '0', 'no', 'off', ''];

    public static function bool(mixed $value, string $name): ?bool
    {
        if (null === $value || \is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return 0.0 !== (float) $value;
        }
        if (\is_string($value)) {
            $lowered = strtolower(trim($value));
            if (\in_array($lowered, self::TRUE, true)) {
                return true;
            }
            if (\in_array($lowered, self::FALSE, true)) {
                return false;
            }
        }

        throw ApiException::badRequest("{$name} must be true or false");
    }

    public static function int(mixed $value, string $name): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && is_finite($value)) {
            return (int) $value;
        }
        if (\is_string($value) && 1 === preg_match('/^\s*[+-]?\d+\s*$/', $value)) {
            return (int) trim($value);
        }

        throw ApiException::badRequest("{$name} must be an integer");
    }

    public static function string(mixed $value, string $name): ?string
    {
        if (null === $value || \is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw ApiException::badRequest("{$name} must be a string");
    }

    public static function httpUrl(string $url, string $name): void
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        $host = (string) parse_url($url, \PHP_URL_HOST);
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
            throw ApiException::badRequest("{$name} must be an absolute http or https URL");
        }
    }

    public static function dateTime(mixed $value, string $name): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $iso = '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/i';
        if (!\is_string($value) || 1 !== preg_match($iso, trim($value))) {
            throw ApiException::badRequest("{$name} must be an ISO 8601 date and time");
        }
        try {
            return new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ApiException::badRequest("{$name} must be an ISO 8601 date and time");
        }
    }
}
