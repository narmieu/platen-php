<?php

declare(strict_types=1);

namespace App\Rendering;

use League\Uri\BaseUri;
use League\Uri\Contracts\UriException;
use League\Uri\Uri;

final class Url
{
    /**
     * Resolves a URL from HTML or CSS against a base URL. Like a browser, it first
     * percent-encodes what a URL may not contain, such as spaces and non-ASCII letters.
     *
     * @param string|null $base null when relative URLs cannot be resolved
     *
     * @return string|null the absolute URL, or null when there is none
     */
    public static function resolve(string $reference, ?string $base): ?string
    {
        $reference = (string) preg_replace_callback(
            '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]+/',
            static fn (array $match): string => rawurlencode($match[0]),
            trim($reference),
        );

        try {
            if (null !== $base) {
                return BaseUri::from($base)->resolve($reference)->getUriString();
            }
            $uri = Uri::new($reference);

            return null === $uri->getScheme() ? null : $uri->toString();
        } catch (UriException) {
            return null;
        }
    }
}
