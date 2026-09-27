<?php

declare(strict_types=1);

namespace App\Network;

use App\Config\PlatenConfig;

/**
 * Which addresses the renderers may connect to.
 *
 * Link-local addresses (cloud metadata endpoints), multicast and broadcast are always
 * blocked; private networks when PLATEN_BLOCK_PRIVATE_NETWORKS is set; and any
 * networks listed in PLATEN_BLOCKED_NETWORKS.
 */
final readonly class NetworkPolicy
{
    private const array ALWAYS_BLOCKED = [
        '169.254.0.0/16', // link-local, including cloud metadata endpoints
        'fe80::/10',
        'fd00:ec2::254/128', // AWS's IPv6 metadata endpoint
        '224.0.0.0/4', // multicast
        'ff00::/8',
        '255.255.255.255/32',
    ];

    private const array PRIVATE = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::/128',
        '::1/128',
        'fc00::/7',
    ];

    /** @var list<IpNetwork> */
    private array $alwaysBlocked;

    /** @var list<IpNetwork> */
    private array $private;

    /** @var list<IpNetwork> */
    private array $blocked;

    /**
     * @param list<string> $blockedNetworks
     */
    public function __construct(
        private bool $blockPrivate = false,
        array $blockedNetworks = [],
    ) {
        $this->alwaysBlocked = array_map(IpNetwork::parse(...), self::ALWAYS_BLOCKED);
        $this->private = array_map(IpNetwork::parse(...), self::PRIVATE);
        $this->blocked = array_map(IpNetwork::parse(...), $blockedNetworks);
    }

    public static function fromConfig(PlatenConfig $config): self
    {
        return new self($config->blockPrivateNetworks, $config->blockedNetworks);
    }

    /**
     * @throws PolicyViolation
     */
    public function check(string $ip): void
    {
        $packed = @inet_pton($ip);
        if (false === $packed) {
            throw new PolicyViolation("{$ip} is not an IP address");
        }
        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is checked as the IPv4 address.
        if (16 === \strlen($packed) && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
            $ip = (string) inet_ntop($packed);
        }
        if (self::matches($this->alwaysBlocked, $packed)) {
            throw new PolicyViolation("{$ip} is a link-local or reserved address");
        }
        if ($this->blockPrivate && self::matches($this->private, $packed)) {
            throw new PolicyViolation("{$ip} is a private network address");
        }
        if (self::matches($this->blocked, $packed)) {
            throw new PolicyViolation("{$ip} is in a blocked network");
        }
    }

    /**
     * @param list<IpNetwork> $networks
     */
    private static function matches(array $networks, string $packed): bool
    {
        foreach ($networks as $network) {
            if ($network->contains($packed)) {
                return true;
            }
        }

        return false;
    }
}
