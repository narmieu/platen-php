<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Chromium;

use App\Document\PrinceOptions;
use App\Rendering\Chromium\PagePreparer;
use App\Rendering\Deadline;
use App\Rendering\PageStyles;
use App\Rendering\RenderJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PagePreparerTest extends TestCase
{
    private const string TOKEN = 'tok123';

    #[Test]
    public function withoutAPolicyJavascriptPagesOnTheNetworkGetNoCspMeta(): void
    {
        // JavaScript on and network allowed: a nonce would disable 'unsafe-inline', and
        // the page needs its own inline scripts, so there is no CSP at all.
        $html = self::prepare(self::job('<p>x</p>', javascript: true, noNetwork: false));
        self::assertStringNotContainsString('Content-Security-Policy', $html);
        // The hook script runs without a nonce so 'unsafe-inline' still applies.
        self::assertMatchesRegularExpression('/<script>[^<]*__platenReady/s', $html);
        self::assertStringNotContainsString('nonce=', $html);
    }

    #[Test]
    public function javascriptOffOnTheNetworkRestrictsScriptsToTheNonce(): void
    {
        $html = self::prepare(self::job('<p>x</p>', javascript: false, noNetwork: false));
        self::assertStringContainsString(
            '<meta http-equiv="Content-Security-Policy" content="script-src \'nonce-'.self::TOKEN.'\'">',
            $html,
        );
        // The hook script carries the nonce; nothing else may run.
        self::assertStringContainsString('<script nonce="'.self::TOKEN.'">', $html);
    }

    #[Test]
    public function javascriptOffWithNoNetworkLocksEverythingDown(): void
    {
        $html = self::prepare(self::job('<p>x</p>', javascript: false, noNetwork: true));
        $policy = self::cspContent($html);
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringContainsString("script-src 'nonce-".self::TOKEN."'", $policy);
        self::assertStringContainsString("style-src 'unsafe-inline' data:", $policy);
        self::assertStringContainsString('img-src data: blob:', $policy);
        self::assertStringContainsString('font-src data:', $policy);
        self::assertStringContainsString('media-src data: blob:', $policy);
        // Without JavaScript there is no connect-src.
        self::assertStringNotContainsString('connect-src', $policy);
        self::assertStringContainsString('<script nonce="'.self::TOKEN.'">', $html);
    }

    #[Test]
    public function javascriptOnWithNoNetworkAllowsInlineScriptsAndConnect(): void
    {
        $html = self::prepare(self::job('<p>x</p>', javascript: true, noNetwork: true));
        $policy = self::cspContent($html);
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringContainsString("script-src 'unsafe-inline' 'unsafe-eval' data: blob:", $policy);
        self::assertStringContainsString('connect-src data: blob:', $policy);
        // Scripts run inline, so the hook has no nonce.
        self::assertStringNotContainsString('nonce=', $html);
    }

    #[Test]
    public function aBaseIsAddedOnlyWhenTheDocumentHasNone(): void
    {
        $withBase = self::prepare(self::job('<head><base href="https://original/"></head><body>x</body>', javascript: false, baseUrl: 'https://platen/'));
        self::assertStringContainsString('<base href="https://original/">', $withBase);
        self::assertStringNotContainsString('https://platen/', $withBase);

        $withoutBase = self::prepare(self::job('<body>x</body>', javascript: false, baseUrl: 'https://platen/'));
        self::assertStringContainsString('<base href="https://platen/">', $withoutBase);
    }

    #[Test]
    public function noBaseIsAddedWhenTheJobHasNoBaseUrl(): void
    {
        $html = self::prepare(self::job('<body>x</body>', javascript: false, baseUrl: null));
        self::assertStringNotContainsString('<base', $html);
    }

    #[Test]
    public function noscriptIsUnwrappedWhenJavascriptIsOff(): void
    {
        // Prince shows <noscript> content; Chromium (which still runs platen's script) would not.
        $html = self::prepare(self::job('<body><noscript><p>No JS</p></noscript></body>', javascript: false));
        self::assertStringContainsString('<p>No JS</p>', $html);
        self::assertStringNotContainsString('<noscript>', $html);
    }

    #[Test]
    public function noscriptIsLeftAloneWhenJavascriptIsOn(): void
    {
        $html = self::prepare(self::job('<body><noscript><p>No JS</p></noscript></body>', javascript: true));
        self::assertStringContainsString('<noscript>', $html);
    }

    #[Test]
    public function iframesAreSandboxedWithoutAllowScriptsWhenJavascriptIsOff(): void
    {
        $html = self::prepare(self::job(
            '<body><iframe src="a" sandbox="allow-forms allow-scripts allow-popups"></iframe><iframe src="b"></iframe></body>',
            javascript: false,
        ));
        // allow-scripts is stripped; the other tokens are kept.
        self::assertStringContainsString('sandbox="allow-forms allow-popups"', $html);
        self::assertDoesNotMatchRegularExpression('/sandbox="[^"]*allow-scripts/', $html);
        // A sandbox-less iframe gets the most restrictive sandbox.
        self::assertStringContainsString('<iframe src="b" sandbox="">', $html);
    }

    #[Test]
    public function iframesKeepTheirSandboxWhenJavascriptIsOn(): void
    {
        $html = self::prepare(self::job('<body><iframe src="a" sandbox="allow-scripts"></iframe></body>', javascript: true));
        self::assertStringContainsString('sandbox="allow-scripts"', $html);
    }

    #[Test]
    public function aMixedCaseAllowScriptsTokenIsAlsoRemoved(): void
    {
        // HTML sandbox tokens are ASCII case-insensitive, so a mixed-case ALLOW-SCRIPTS
        // re-enables scripting in the frame even though JavaScript is off. It must be removed.
        $html = self::prepare(self::job('<body><iframe src="a" sandbox="ALLOW-SCRIPTS allow-popups"></iframe></body>', javascript: false));
        self::assertSame(1, preg_match('/<iframe[^>]*\ssandbox="([^"]*)"/', $html, $match));
        $tokens = preg_split('/\s+/', strtolower($match[1]), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        self::assertNotContains('allow-scripts', $tokens, 'The iframe still permits scripts with JavaScript off');
    }

    #[Test]
    public function itOverridesTheTitleWithPdfTitle(): void
    {
        $html = self::prepare(self::job('<head><title>Original</title></head><body>x</body>', javascript: false, pdfTitle: 'From Options'));
        self::assertStringContainsString('<title>From Options</title>', $html);
        self::assertStringNotContainsString('<title>Original</title>', $html);
    }

    #[Test]
    public function itAppendsTheWatermarkStyleForTestDocuments(): void
    {
        $html = self::prepare(self::job('<body>x</body>', javascript: false, watermark: true));
        self::assertStringContainsString('data-platen="watermark"', $html);
        self::assertStringContainsString(PageStyles::WATERMARK_TEXT, $html);

        $plain = self::prepare(self::job('<body>x</body>', javascript: false, watermark: false));
        self::assertStringNotContainsString('data-platen="watermark"', $plain);
    }

    #[Test]
    public function itAlwaysAddsThePageDefaultsStyle(): void
    {
        $html = self::prepare(self::job('<body>x</body>', javascript: false));
        self::assertStringContainsString('data-platen="defaults"', $html);
        self::assertStringContainsString('@page { size: letter; margin: 54pt; }', $html);
    }

    private static function prepare(RenderJob $job): string
    {
        return new PagePreparer(30.0)->prepare($job, self::TOKEN);
    }

    private static function job(
        string $html,
        bool $javascript,
        bool $noNetwork = false,
        ?string $baseUrl = null,
        ?string $pdfTitle = null,
        bool $watermark = false,
    ): RenderJob {
        return new RenderJob(
            html: $html,
            baseUrl: $baseUrl,
            pageCss: PageStyles::page(null),
            watermark: $watermark,
            javascript: $javascript,
            options: new PrinceOptions(noNetwork: $noNetwork, pdfTitle: $pdfTitle),
            profile: null,
            ignoreResourceErrors: true,
            ignoreConsoleMessages: false,
            userAgentToken: null,
            referrer: null,
            credentials: null,
            deadline: Deadline::in(60.0),
        );
    }

    private static function cspContent(string $html): string
    {
        self::assertSame(1, preg_match('/Content-Security-Policy" content="([^"]*)"/', $html, $match));

        return $match[1];
    }
}
