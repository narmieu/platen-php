<?php

declare(strict_types=1);

namespace App\Network;

/**
 * An operator's outbound proxy (HTTP_PROXY / HTTPS_PROXY / NO_PROXY) that the egress
 * proxy forwards to after applying the network policy.
 */
final readonly class UpstreamProxy
{
    /**
     * @param list<string> $noProxy
     */
    private function __construct(
        /** The proxy URL, credentials included, e.g. http://user:secret@proxy.internal:3128. */
        #[\SensitiveParameter]
        public string $url,
        /** Where to connect, e.g. tcp://proxy.internal:3128 (tls:// for an https:// proxy). */
        public string $address,
        public ?string $authorization,
        private array $noProxy,
    ) {
    }

    /**
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment, string $scheme): ?self
    {
        $value = self::variable($environment, "{$scheme}_proxy") ?? self::variable($environment, 'all_proxy');
        if (null === $value) {
            return null;
        }
        $url = str_contains($value, '://') ? $value : "http://{$value}";
        $parts = parse_url($url);
        $secure = 'https' === strtolower($parts['scheme'] ?? '');
        if (false === $parts || !isset($parts['host']) || (!$secure && 'http' !== strtolower($parts['scheme'] ?? ''))) {
            return null;
        }
        $authorization = isset($parts['user'])
            ? 'Basic '.base64_encode(rawurldecode($parts['user']).':'.rawurldecode($parts['pass'] ?? ''))
            : null;
        $noProxy = array_values(array_filter(array_map(
            static fn (string $entry): string => strtolower(trim($entry)),
            explode(',', self::variable($environment, 'no_proxy') ?? ''),
        )));
        $address = ($secure ? 'tls' : 'tcp').'://'.$parts['host'].':'.($parts['port'] ?? ($secure ? 443 : 80));

        return new self($url, $address, $authorization, $noProxy);
    }

    /**
     * Whether NO_PROXY exempts the host: "*", a domain and its subdomains, an IP address
     * or a CIDR network (for IP address hosts).
     */
    public function bypasses(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        $ip = @inet_pton($host);
        foreach ($this->noProxy as $entry) {
            if ('*' === $entry) {
                return true;
            }
            if (false !== $ip && 1 === preg_match('/^[\d.:a-f]+(\/\d+)?$/', $entry)) {
                try {
                    if (IpNetwork::parse($entry)->contains($ip)) {
                        return true;
                    }
                } catch (\InvalidArgumentException) {
                }
                continue;
            }
            $domain = ltrim(preg_replace('/:\d+$/', '', $entry) ?? $entry, '*.');
            if ('' !== $domain && ($host === $domain || str_ends_with($host, ".{$domain}"))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $environment
     */
    private static function variable(array $environment, string $name): ?string
    {
        // Lower-case variables win, as in curl and Python.
        $value = $environment[$name] ?? $environment[strtoupper($name)] ?? '';

        return '' === trim($value) ? null : trim($value);
    }
}
