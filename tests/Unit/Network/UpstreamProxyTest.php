<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Network\UpstreamProxy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpstreamProxyTest extends TestCase
{
    #[Test]
    public function noVariableMeansNoProxy(): void
    {
        self::assertNull(UpstreamProxy::fromEnvironment([], 'http'));
        self::assertNull(UpstreamProxy::fromEnvironment(['https_proxy' => '   '], 'https'));
        self::assertNull(UpstreamProxy::fromEnvironment(['https_proxy' => ''], 'https'));
    }

    #[Test]
    public function aLowerCaseVariableWinsOverUpperCase(): void
    {
        $proxy = UpstreamProxy::fromEnvironment([
            'HTTP_PROXY' => 'http://upper.example:3128',
            'http_proxy' => 'lower.example:8080',
        ], 'http');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        // Scheme-less values default to http://.
        self::assertSame('http://lower.example:8080', $proxy->url);
        self::assertSame('tcp://lower.example:8080', $proxy->address);
    }

    #[Test]
    public function itFallsBackToAllProxy(): void
    {
        $proxy = UpstreamProxy::fromEnvironment(['all_proxy' => 'http://fallback.example:3128'], 'https');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        self::assertSame('http://fallback.example:3128', $proxy->url);
    }

    #[Test]
    public function anHttpsProxyConnectsOverTlsOnTheDefaultPort(): void
    {
        $proxy = UpstreamProxy::fromEnvironment(['https_proxy' => 'https://secure.proxy'], 'https');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        self::assertSame('tls://secure.proxy:443', $proxy->address);
    }

    #[Test]
    public function aPlainHttpProxyConnectsOverTcpOnTheDefaultPort(): void
    {
        $proxy = UpstreamProxy::fromEnvironment(['http_proxy' => 'http://proxy.internal'], 'http');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        self::assertSame('tcp://proxy.internal:80', $proxy->address);
    }

    #[Test]
    public function credentialsBecomeABasicAuthorizationHeader(): void
    {
        // The user and password are percent-decoded before being encoded.
        $proxy = UpstreamProxy::fromEnvironment(['all_proxy' => 'https://user%40corp:p%3Ass@secure.proxy'], 'https');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        self::assertSame('Basic '.base64_encode('user@corp:p:ss'), $proxy->authorization);
        self::assertSame('tls://secure.proxy:443', $proxy->address);
    }

    #[Test]
    public function itHasNoAuthorizationWithoutCredentials(): void
    {
        $proxy = UpstreamProxy::fromEnvironment(['http_proxy' => 'http://proxy.internal:3128'], 'http');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        self::assertNull($proxy->authorization);
    }

    #[Test]
    #[DataProvider('unusableProxies')]
    public function itRejectsProxiesItCannotUse(string $value): void
    {
        self::assertNull(UpstreamProxy::fromEnvironment(['https_proxy' => $value], 'https'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableProxies(): iterable
    {
        yield 'unsupported scheme' => ['socks5://proxy:1080'];
        yield 'no host' => ['http:///nohost'];
    }

    #[Test]
    #[DataProvider('bypassRules')]
    public function noProxyBypassRules(string $noProxy, string $host, bool $bypassed): void
    {
        $proxy = UpstreamProxy::fromEnvironment(['http_proxy' => 'http://p:1', 'no_proxy' => $noProxy], 'http');
        self::assertInstanceOf(UpstreamProxy::class, $proxy);
        self::assertSame($bypassed, $proxy->bypasses($host));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function bypassRules(): iterable
    {
        yield 'wildcard bypasses everything' => ['*', 'anything.example', true];
        yield 'exact domain' => ['example.com', 'example.com', true];
        yield 'subdomain of a listed domain' => ['example.com', 'www.example.com', true];
        yield 'domain is case-insensitive' => ['example.com', 'EXAMPLE.com', true];
        yield 'similar but different domain' => ['example.com', 'notexample.com', false];
        yield 'leading dot domain' => ['.internal', 'a.internal', true];
        yield 'leading dot matches the bare domain too' => ['.internal', 'internal', true];
        yield 'wildcard-dot domain' => ['*.corp.test', 'b.corp.test', true];
        yield 'wildcard-dot matches the bare domain too' => ['*.corp.test', 'corp.test', true];
        yield 'ip inside a cidr' => ['10.0.0.0/8', '10.2.3.4', true];
        yield 'ip outside a cidr' => ['10.0.0.0/8', '11.0.0.1', false];
        yield 'exact ipv6 in brackets' => ['::1', '[::1]', true];
        yield 'exact ipv4' => ['192.168.1.5', '192.168.1.5', true];
        yield 'other ipv4' => ['192.168.1.5', '192.168.1.6', false];
        yield 'port is stripped from a domain entry' => ['localhost:8080', 'localhost', true];
    }
}
