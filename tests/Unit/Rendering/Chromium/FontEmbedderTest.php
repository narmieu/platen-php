<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Chromium;

use App\Document\PrinceOptions;
use App\Rendering\Chromium\FontEmbedder;
use App\Rendering\Credentials;
use App\Rendering\Deadline;
use App\Rendering\PageStyles;
use App\Rendering\RenderJob;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FontEmbedderTest extends TestCase
{
    private const string BASE = 'https://example.com/certificates/';
    private const string FONT = 'FONTDATA';
    private const string FONT_DATA_URL = 'data:font/woff2;base64,Rk9OVERBVEE=';

    /** @var array<string, array<string, list<string>>> the headers of each request, by URL */
    private array $requests = [];

    #[Test]
    public function itEmbedsAFontWhoseServerDoesNotAllowAnyOrigin(): void
    {
        $css = $this->embed(
            '<style>@font-face { font-family: Brand; src: url(fonts/brand.woff2) format("woff2") }</style><p>Hello</p>',
            ['https://example.com/certificates/fonts/brand.woff2' => new MockResponse(self::FONT)],
        );

        self::assertSame('@font-face{font-family:Brand;src:url("'.self::FONT_DATA_URL.'") format("woff2")}', $css);
    }

    #[Test]
    public function itLeavesFontsThatChromiumMayLoadItself(): void
    {
        $css = $this->embed(
            '<style>@font-face { font-family: A; src: url(a.woff2) } @font-face { font-family: B; src: url(b.woff2) }</style>',
            [
                'https://example.com/certificates/a.woff2' => self::font(['Access-Control-Allow-Origin: *']),
                'https://example.com/certificates/b.woff2' => self::font(['Access-Control-Allow-Origin: null']),
            ],
        );

        self::assertSame('', $css);
        self::assertCount(2, $this->requests);
    }

    #[Test]
    public function itFollowsLinkedAndImportedStyleSheetsInCascadeOrder(): void
    {
        $css = $this->embed(
            '<link rel="stylesheet" href="/css/app.css"><style>@font-face { font-family: Inline; src: url(inline.woff2) }</style>',
            [
                'https://example.com/css/app.css' => new MockResponse('@import "fonts/imported.css"; @font-face { font-family: App; src: url(../fonts/app.woff2) }'),
                'https://example.com/css/fonts/imported.css' => new MockResponse('@font-face { font-family: Imported; src: url(imported.woff2) }'),
                'https://example.com/css/fonts/imported.woff2' => new MockResponse(self::FONT),
                'https://example.com/fonts/app.woff2' => new MockResponse(self::FONT),
                'https://example.com/certificates/inline.woff2' => new MockResponse(self::FONT),
            ],
        );

        $families = preg_match_all('/font-family:(\w+)/', $css, $matches) ? $matches[1] : [];
        self::assertSame(['Imported', 'App', 'Inline'], $families);
        self::assertSame(3, substr_count($css, self::FONT_DATA_URL));
    }

    #[Test]
    public function aStyleSheetImportingItselfIsReadOnce(): void
    {
        $css = $this->embed(
            '<link rel="stylesheet" href="loop.css">',
            [
                'https://example.com/certificates/loop.css' => new MockResponse('@import "loop.css"; @font-face { font-family: Loop; src: url(loop.woff2) }'),
                'https://example.com/certificates/loop.woff2' => new MockResponse(self::FONT),
            ],
        );

        self::assertSame(1, substr_count($css, '@font-face'));
        self::assertCount(2, $this->requests);
    }

    #[Test]
    public function itIgnoresLinksThatAreNotStyleSheetsInUse(): void
    {
        $css = $this->embed('<link rel="alternate stylesheet" href="alt.css"><link rel="icon" href="icon.css"><link rel="preload" as="font" href="a.woff2">', []);

        self::assertSame('', $css);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function fontsThatFailToDownloadAreLeftToChromium(): void
    {
        $css = $this->embed(
            '<style>@font-face { font-family: A; src: url(missing.woff2) } @font-face { font-family: B; src: url(broken.woff2) }</style>',
            [
                'https://example.com/certificates/missing.woff2' => new MockResponse('not found', ['http_code' => 404]),
                'https://example.com/certificates/broken.woff2' => new MockResponse('', ['error' => 'Connection reset']),
            ],
        );

        self::assertSame('', $css);
    }

    #[Test]
    public function aStyleSheetThatFailsToDownloadIsSkipped(): void
    {
        $css = $this->embed(
            '<link rel="stylesheet" href="missing.css"><style>@font-face { font-family: A; src: url(a.woff2) }</style>',
            [
                'https://example.com/certificates/missing.css' => new MockResponse('', ['http_code' => 500]),
                'https://example.com/certificates/a.woff2' => new MockResponse(self::FONT),
            ],
        );

        self::assertStringContainsString('font-family:A', $css);
    }

    #[Test]
    public function itAsksLikeChromiumAndSendsCredentialsToTheDocumentsOriginOnly(): void
    {
        $this->embed(
            '<style>@font-face { font-family: A; src: url(a.woff2), url(https://cdn.example.net/a.woff2) }</style>',
            [
                'https://example.com/certificates/a.woff2' => new MockResponse(self::FONT),
                'https://cdn.example.net/a.woff2' => new MockResponse(self::FONT),
            ],
            credentials: new Credentials('user', 'secret'),
            referrer: 'https://app.example.com/courses',
            userAgentToken: 'certificates',
        );

        $own = $this->requests['https://example.com/certificates/a.woff2'];
        self::assertSame(['Origin: null'], $own['origin'] ?? null);
        self::assertSame(['Referer: https://app.example.com/courses'], $own['referer'] ?? null);
        self::assertStringEndsWith(' certificates', ($own['user-agent'] ?? [''])[0]);
        self::assertSame(['Authorization: Basic '.base64_encode('user:secret')], $own['authorization'] ?? null);
        self::assertArrayNotHasKey('authorization', $this->requests['https://cdn.example.net/a.woff2']);
    }

    #[Test]
    public function relativeUrlsNeedABaseUrl(): void
    {
        $css = $this->embed('<style>@font-face { font-family: A; src: url(a.woff2) }</style>', [], baseUrl: null);

        self::assertSame('', $css);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function theDocumentsBaseElementWins(): void
    {
        $this->embed(
            '<base href="https://static.example.com/"><style>@font-face { font-family: A; src: url(a.woff2) }</style>',
            ['https://static.example.com/a.woff2' => new MockResponse(self::FONT)],
        );

        self::assertSame(['https://static.example.com/a.woff2'], array_keys($this->requests));
    }

    #[Test]
    public function withoutNetworkNothingIsRequested(): void
    {
        $css = $this->embed('<style>@font-face { font-family: A; src: url(a.woff2) }</style>', [], noNetwork: true);

        self::assertSame('', $css);
        self::assertSame([], $this->requests);
    }

    /**
     * @param array<string, MockResponse> $responses by URL; any other URL is a test failure
     */
    private function embed(
        string $html,
        array $responses,
        ?string $baseUrl = self::BASE,
        bool $noNetwork = false,
        ?Credentials $credentials = null,
        ?string $referrer = null,
        ?string $userAgentToken = null,
    ): string {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($responses): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('http://127.0.0.1:3128', $options['proxy'] ?? null);
            self::assertArrayHasKey($url, $responses, "Unexpected request for {$url}");
            /** @var array<string, list<string>> $headers */
            $headers = $options['normalized_headers'] ?? [];
            $this->requests[$url] = $headers;

            return $responses[$url];
        });

        return new FontEmbedder(TestConfig::create(), $client)->css(new RenderJob(
            html: $html,
            baseUrl: $baseUrl,
            pageCss: PageStyles::page(null),
            watermark: false,
            javascript: false,
            options: new PrinceOptions(noNetwork: $noNetwork),
            profile: null,
            ignoreResourceErrors: true,
            ignoreConsoleMessages: false,
            userAgentToken: $userAgentToken,
            referrer: $referrer,
            credentials: $credentials,
            deadline: Deadline::in(60.0),
        ));
    }

    /**
     * @param list<string> $headers
     */
    private static function font(array $headers): MockResponse
    {
        return new MockResponse(self::FONT, ['response_headers' => $headers]);
    }
}
