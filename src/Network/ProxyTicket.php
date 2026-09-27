<?php

declare(strict_types=1);

namespace App\Network;

use App\Rendering\Origin;

/**
 * Per-render instructions for the egress proxy, carried as the proxy username.
 *
 * WeasyPrint's command line cannot set request headers, but it honours HTTP_PROXY with
 * credentials. The proxy reads this ticket from Proxy-Authorization and adds the
 * headers to plain http:// requests (https:// traffic is an end-to-end TLS tunnel).
 * A forged ticket only changes the headers of its sender's own requests, so it is
 * not signed.
 */
final readonly class ProxyTicket
{
    public function __construct(
        public ?string $userAgentSuffix = null,
        public ?string $referrer = null,
        public ?string $authorization = null,
        /** Only requests to this origin get the Authorization header; null means all. */
        public ?string $authorizationOrigin = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->userAgentSuffix && null === $this->referrer && null === $this->authorization;
    }

    /**
     * The proxy URL with this ticket as its username, e.g. http://eyJ...:x@127.0.0.1:3128.
     */
    public function proxyUrl(string $proxy): string
    {
        if ($this->isEmpty()) {
            return $proxy;
        }
        $json = json_encode(array_filter([
            'ua' => $this->userAgentSuffix,
            'referer' => $this->referrer,
            'auth' => $this->authorization,
            'auth_origin' => $this->authorizationOrigin,
        ], static fn (?string $value): bool => null !== $value), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $ticket = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return preg_replace('#^(\w+://)#', "\$1{$ticket}:x@", $proxy) ?? $proxy;
    }

    /**
     * Reads the ticket from a Proxy-Authorization header value.
     */
    public static function fromProxyAuthorization(?string $header): ?self
    {
        if (null === $header || 1 !== preg_match('/^Basic\s+(\S+)$/i', trim($header), $match)) {
            return null;
        }
        $credentials = base64_decode($match[1], true);
        if (false === $credentials) {
            return null;
        }
        $json = base64_decode(strtr(explode(':', $credentials, 2)[0], '-_', '+/'), true);
        $data = false === $json ? null : json_decode($json, true);
        if (!\is_array($data)) {
            return null;
        }
        // A value with a line break would add headers of its own.
        $string = static fn (string $key): ?string => isset($data[$key]) && \is_string($data[$key]) && 1 !== preg_match('/[\x00-\x1f\x7f]/', $data[$key]) ? $data[$key] : null;

        return new self($string('ua'), $string('referer'), $string('auth'), $string('auth_origin'));
    }

    /**
     * Applies the ticket to the headers of a request for $url.
     *
     * @param array<string, string> $headers keyed by lower-case name
     *
     * @return array<string, string>
     */
    public function apply(array $headers, string $url): array
    {
        if (null !== $this->userAgentSuffix) {
            $headers['user-agent'] = trim(($headers['user-agent'] ?? '').' '.$this->userAgentSuffix);
        }
        if (null !== $this->referrer && !isset($headers['referer'])) {
            $headers['referer'] = $this->referrer;
        }
        if (null !== $this->authorization && !isset($headers['authorization'])
            && (null === $this->authorizationOrigin || Origin::of($url) === $this->authorizationOrigin)) {
            $headers['authorization'] = $this->authorization;
        }

        return $headers;
    }
}
