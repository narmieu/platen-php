<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Network\IpNetwork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IpNetworkTest extends TestCase
{
    #[Test]
    #[DataProvider('membership')]
    public function itDecidesMembership(string $cidr, string $ip, bool $contained): void
    {
        self::assertSame($contained, IpNetwork::parse($cidr)->contains((string) inet_pton($ip)));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function membership(): iterable
    {
        yield 'inside /8' => ['10.0.0.0/8', '10.1.2.3', true];
        yield 'outside /8' => ['10.0.0.0/8', '11.0.0.1', false];
        yield 'network address of /24' => ['192.168.1.0/24', '192.168.1.0', true];
        yield 'broadcast of /24' => ['192.168.1.0/24', '192.168.1.255', true];
        yield 'just outside /24' => ['192.168.1.0/24', '192.168.2.0', false];
        yield 'single host /32 match' => ['192.168.1.5/32', '192.168.1.5', true];
        yield 'single host /32 miss' => ['192.168.1.5/32', '192.168.1.6', false];
        yield 'non-byte-aligned prefix /12 in range' => ['172.16.0.0/12', '172.31.255.255', true];
        yield 'non-byte-aligned prefix /12 out of range' => ['172.16.0.0/12', '172.32.0.1', false];
        yield 'match all v4 with /0' => ['0.0.0.0/0', '8.8.8.8', true];
        yield 'ipv6 /64 in range' => ['2001:db8::/64', '2001:db8::1', true];
        yield 'ipv6 /32 out of range' => ['2001:db8::/32', '2001:db9::1', false];
        yield 'ipv6 host /128' => ['::1/128', '::1', true];
    }

    #[Test]
    public function anIpv4NetworkNeverContainsAnIpv6Address(): void
    {
        // A packed length mismatch cannot match, so families never cross.
        self::assertFalse(IpNetwork::parse('10.0.0.0/8')->contains((string) inet_pton('::1')));
        self::assertFalse(IpNetwork::parse('::/0')->contains((string) inet_pton('10.0.0.1')));
    }

    #[Test]
    public function aBareAddressIsAFullLengthPrefix(): void
    {
        self::assertTrue(IpNetwork::parse('10.0.0.1')->contains((string) inet_pton('10.0.0.1')));
        self::assertFalse(IpNetwork::parse('10.0.0.1')->contains((string) inet_pton('10.0.0.2')));
    }

    #[Test]
    public function itStripsBracketsAroundAnIpv6Address(): void
    {
        self::assertTrue(IpNetwork::parse('[::1]/128')->contains((string) inet_pton('::1')));
    }

    #[Test]
    #[DataProvider('invalidNetworks')]
    public function itRejectsMalformedNetworks(string $cidr): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IpNetwork::parse($cidr);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidNetworks(): iterable
    {
        yield 'not an address' => ['nonsense'];
        yield 'v4 prefix too large' => ['10.0.0.0/33'];
        yield 'v6 prefix too large' => ['::1/129'];
        yield 'empty prefix' => ['10.0.0.0/'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
    }
}
