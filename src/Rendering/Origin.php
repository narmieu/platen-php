<?php

declare(strict_types=1);

namespace App\Rendering;

final class Origin
{
    /**
     * "HTTPS://Example.com/a/b" becomes "https://example.com:443"; null for non-http(s) URLs.
     */
    public static function of(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
            return null;
        }
        $port = $parts['port'] ?? ('https' === $scheme ? 443 : 80);

        return "{$scheme}://{$host}:{$port}";
    }
}
