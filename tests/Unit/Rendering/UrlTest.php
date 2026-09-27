<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Rendering\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    private const string BASE = 'https://example.com/assets/css/app.css?v=1';

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function references(): iterable
    {
        yield 'same directory' => ['brand.woff2', self::BASE, 'https://example.com/assets/css/brand.woff2'];
        yield 'parent directory' => ['../fonts/brand.woff2', self::BASE, 'https://example.com/assets/fonts/brand.woff2'];
        yield 'absolute path' => ['/fonts/brand.woff2', self::BASE, 'https://example.com/fonts/brand.woff2'];
        yield 'scheme-relative' => ['//cdn.example.net/brand.woff2', self::BASE, 'https://cdn.example.net/brand.woff2'];
        yield 'absolute URL' => ['http://other.test/brand.woff2', self::BASE, 'http://other.test/brand.woff2'];
        yield 'query and fragment' => ['brand.woff2?v=2#iefix', self::BASE, 'https://example.com/assets/css/brand.woff2?v=2#iefix'];
        yield 'surrounding whitespace' => ["  brand.woff2\n", self::BASE, 'https://example.com/assets/css/brand.woff2'];
        yield 'spaces and non-ASCII letters' => ['Brand Font/Đurđa.woff2', self::BASE, 'https://example.com/assets/css/Brand%20Font/%C4%90ur%C4%91a.woff2'];
        yield 'percent-encoding kept' => ['brand%20font.woff2', self::BASE, 'https://example.com/assets/css/brand%20font.woff2'];
        yield 'data URL' => ['data:font/woff2;base64,d09GMg==', self::BASE, 'data:font/woff2;base64,d09GMg=='];
        yield 'absolute URL without a base' => ['https://example.com/brand.woff2', null, 'https://example.com/brand.woff2'];
        yield 'relative URL without a base' => ['brand.woff2', null, null];
        yield 'unparseable' => ['http://[example.com/brand.woff2', self::BASE, null];
    }

    #[Test]
    #[DataProvider('references')]
    public function itResolvesLikeABrowser(string $reference, ?string $base, ?string $expected): void
    {
        self::assertSame($expected, Url::resolve($reference, $base));
    }
}
