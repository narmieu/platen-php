<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Network\ListenAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListenAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function ipAddresses(): iterable
    {
        yield 'IPv4' => ['127.0.0.1:3128'];
        yield 'any IPv4' => ['0.0.0.0:3128'];
        yield 'IPv6' => ['[::1]:3128'];
    }

    #[Test]
    #[DataProvider('ipAddresses')]
    public function anIpAddressIsKept(string $address): void
    {
        $lookup = static fn (string $host): never => throw new \LogicException("{$host} was looked up");

        self::assertSame($address, ListenAddress::resolve($address, $lookup));
    }

    #[Test]
    public function aNameBecomesTheAddressItResolvesTo(): void
    {
        $lookup = static fn (string $host): array => 'egress' === $host ? ['172.20.0.3', '172.20.0.9'] : [];

        self::assertSame('172.20.0.3:3128', ListenAddress::resolve('egress:3128', $lookup));
    }

    #[Test]
    public function aNameThatDoesNotResolveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot resolve the egress proxy host "egress"');

        ListenAddress::resolve('egress:3128', static fn (string $host): false => false);
    }

    #[Test]
    public function anAddressWithoutAPortIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ListenAddress::resolve('egress');
    }
}
