<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Network\NetworkPolicy;
use App\Network\PolicyViolation;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NetworkPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('alwaysBlocked')]
    public function itAlwaysBlocksLinkLocalMetadataAndMulticast(string $ip, string $reason): void
    {
        $policy = new NetworkPolicy(blockPrivate: false, blockedNetworks: []);
        $exception = self::catch($policy, $ip);
        self::assertStringContainsString($reason, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function alwaysBlocked(): iterable
    {
        yield 'cloud metadata endpoint' => ['169.254.169.254', 'link-local or reserved'];
        yield 'ipv6 link-local' => ['fe80::1', 'link-local or reserved'];
        yield 'aws ipv6 metadata' => ['fd00:ec2::254', 'link-local or reserved'];
        yield 'ipv4 multicast' => ['224.0.0.1', 'link-local or reserved'];
        yield 'ipv6 multicast' => ['ff02::1', 'link-local or reserved'];
        yield 'limited broadcast' => ['255.255.255.255', 'link-local or reserved'];
    }

    #[Test]
    public function theIpv4MappedMetadataAddressIsBlockedAsIpv4(): void
    {
        $policy = new NetworkPolicy(blockPrivate: false, blockedNetworks: []);
        // ::ffff:169.254.169.254 is checked as the embedded IPv4 address.
        $exception = self::catch($policy, '::ffff:169.254.169.254');
        self::assertSame('169.254.169.254 is a link-local or reserved address', $exception->getMessage());

        $hex = self::catch($policy, '::ffff:a9fe:a9fe');
        self::assertSame('169.254.169.254 is a link-local or reserved address', $hex->getMessage());
    }

    #[Test]
    #[DataProvider('publicAddresses')]
    public function publicAddressesPassWhenPrivateNetworksAreAllowed(string $ip): void
    {
        $policy = new NetworkPolicy(blockPrivate: false, blockedNetworks: []);
        $policy->check($ip);
        $this->addToAssertionCount(1); // no exception thrown
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'private range allowed when not blocking' => ['10.0.0.1'];
        yield 'loopback allowed when not blocking' => ['127.0.0.1'];
        yield 'public ipv4' => ['8.8.8.8'];
        yield 'public ipv6' => ['2001:4860:4860::8888'];
    }

    #[Test]
    #[DataProvider('privateAddresses')]
    public function privateNetworksAreBlockedOnlyWhenEnabled(string $ip, string $reason): void
    {
        $allowed = new NetworkPolicy(blockPrivate: false, blockedNetworks: []);
        $allowed->check($ip); // passes when private networks are allowed

        $blocked = new NetworkPolicy(blockPrivate: true, blockedNetworks: []);
        $exception = self::catch($blocked, $ip);
        self::assertStringContainsString($reason, $exception->getMessage());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'rfc1918 10/8' => ['10.0.0.1', 'private network'];
        yield 'loopback' => ['127.0.0.1', 'private network'];
        yield 'ipv6 loopback' => ['::1', 'private network'];
        yield 'unique local' => ['fc00::1', 'private network'];
        yield 'carrier grade nat' => ['100.64.0.1', 'private network'];
        yield 'rfc1918 172.16/12' => ['172.31.255.255', 'private network'];
        yield 'rfc1918 192.168/16' => ['192.168.1.1', 'private network'];
        yield 'unspecified v4' => ['0.0.0.0', 'private network'];
        yield 'unspecified v6' => ['::', 'private network'];
    }

    #[Test]
    public function anIpv4MappedPrivateAddressIsBlockedAsIpv4(): void
    {
        $policy = new NetworkPolicy(blockPrivate: true, blockedNetworks: []);
        $exception = self::catch($policy, '::ffff:10.1.2.3');
        self::assertSame('10.1.2.3 is a private network address', $exception->getMessage());
    }

    #[Test]
    public function customBlockedNetworksAreEnforcedForBothFamilies(): void
    {
        $policy = new NetworkPolicy(blockPrivate: false, blockedNetworks: ['203.0.113.0/24', '2001:db8::/32']);

        self::assertSame('203.0.113.9 is in a blocked network', self::catch($policy, '203.0.113.9')->getMessage());
        self::assertSame('2001:db8::1 is in a blocked network', self::catch($policy, '2001:db8::1')->getMessage());
        // The mapped form is checked as IPv4, so the custom IPv4 CIDR still catches it.
        self::assertSame('203.0.113.9 is in a blocked network', self::catch($policy, '::ffff:203.0.113.9')->getMessage());

        $policy->check('8.8.8.8'); // an address in no blocked range passes
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsInputThatIsNotAnIpAddress(): void
    {
        $policy = new NetworkPolicy(blockPrivate: false, blockedNetworks: []);
        $exception = self::catch($policy, 'example.com');
        self::assertSame('example.com is not an IP address', $exception->getMessage());
    }

    #[Test]
    public function itBuildsFromConfig(): void
    {
        $config = TestConfig::create(blockedNetworks: '203.0.113.0/24', blockPrivateNetworks: true);
        $policy = NetworkPolicy::fromConfig($config);

        self::assertStringContainsString('private network', self::catch($policy, '10.0.0.1')->getMessage());
        self::assertStringContainsString('blocked network', self::catch($policy, '203.0.113.7')->getMessage());
    }

    private static function catch(NetworkPolicy $policy, string $ip): PolicyViolation
    {
        try {
            $policy->check($ip);
        } catch (PolicyViolation $exception) {
            return $exception;
        }
        self::fail("Expected {$ip} to be blocked");
    }
}
