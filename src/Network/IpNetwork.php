<?php

declare(strict_types=1);

namespace App\Network;

/**
 * An IPv4 or IPv6 network in CIDR notation.
 */
final readonly class IpNetwork
{
    private function __construct(
        private string $address,
        private int $prefix,
    ) {
    }

    public static function parse(string $cidr): self
    {
        $parts = explode('/', trim($cidr), 2);
        $prefix = $parts[1] ?? null;
        $packed = @inet_pton(trim($parts[0], '[]'));
        if (false === $packed) {
            throw new \InvalidArgumentException("Invalid network: {$cidr}");
        }
        $bits = \strlen($packed) * 8;
        $prefix = null === $prefix ? $bits : filter_var($prefix, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $bits]]);
        if (false === $prefix) {
            throw new \InvalidArgumentException("Invalid network prefix: {$cidr}");
        }

        return new self($packed, $prefix);
    }

    /**
     * @param string $ip packed, as inet_pton() returns it
     */
    public function contains(string $ip): bool
    {
        if (\strlen($ip) !== \strlen($this->address)) {
            return false;
        }
        $bytes = intdiv($this->prefix, 8);
        if (0 !== strncmp($ip, $this->address, $bytes)) {
            return false;
        }
        $bits = $this->prefix % 8;
        if (0 === $bits) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (\ord($ip[$bytes]) & $mask) === (\ord($this->address[$bytes]) & $mask);
    }
}
