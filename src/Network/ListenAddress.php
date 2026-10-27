<?php

declare(strict_types=1);

namespace App\Network;

/**
 * The address the egress proxy listens on, as host:port.
 *
 * The host may be a name, such as a Docker network alias: listening on the address it
 * resolves to keeps the proxy off the container's other networks.
 */
final class ListenAddress
{
    /**
     * @param \Closure(string): (list<string>|false) $lookup IPv4 addresses for a name
     *
     * @return string the address with the host as an IP address, e.g. 172.20.0.3:3128
     *
     * @throws \InvalidArgumentException when the address has no port or the name does not resolve
     */
    public static function resolve(string $address, ?\Closure $lookup = null): string
    {
        if (1 !== preg_match('/^(?:\[(?<ipv6>[^]]+)\]|(?<host>[^:]+)):(?<port>\d+)$/', trim($address), $match)) {
            throw new \InvalidArgumentException("The egress proxy address must be host:port, not \"{$address}\".");
        }
        if ('' !== $match['ipv6'] || false !== @inet_pton($match['host'])) {
            return trim($address);
        }

        $lookup ??= static fn (string $host): array|false => gethostbynamel($host);
        $addresses = $lookup($match['host']);
        if (false === $addresses || [] === $addresses) {
            throw new \InvalidArgumentException("Cannot resolve the egress proxy host \"{$match['host']}\".");
        }

        return "{$addresses[0]}:{$match['port']}";
    }
}
