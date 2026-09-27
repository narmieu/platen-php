<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Rendering\Origin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OriginTest extends TestCase
{
    #[Test]
    #[DataProvider('origins')]
    public function itExtractsTheOrigin(string $url, ?string $expected): void
    {
        self::assertSame($expected, Origin::of($url));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function origins(): iterable
    {
        yield 'scheme and host are lowercased, default port added' => ['HTTPS://Example.com/a/b', 'https://example.com:443'];
        yield 'http default port' => ['http://example.com', 'http://example.com:80'];
        yield 'explicit port is kept' => ['http://example.com:8080/x', 'http://example.com:8080'];
        yield 'userinfo is dropped' => ['https://user:pw@Example.com/', 'https://example.com:443'];
        yield 'ipv6 host' => ['http://[::1]:8080/', 'http://[::1]:8080'];
        yield 'explicit default port kept verbatim' => ['http://example.com:443', 'http://example.com:443'];
        yield 'non-http scheme is null' => ['ftp://example.com/', null];
        yield 'mailto is null' => ['mailto:a@b.c', null];
        yield 'no host is null' => ['http:///path', null];
        yield 'relative url is null' => ['/relative', null];
    }
}
