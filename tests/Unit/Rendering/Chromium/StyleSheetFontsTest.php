<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Chromium;

use App\Rendering\Chromium\StyleSheetFonts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleSheetFontsTest extends TestCase
{
    private const string BASE = 'https://example.com/css/app.css';

    #[Test]
    public function itFindsImportsAndTheFontFilesOfEachFontFace(): void
    {
        $sheet = StyleSheetFonts::parse(<<<'CSS'
            @import url("fonts.css");
            @import '/print.css' print;
            @font-face {
                font-family: "Brand Serif";
                src: local("Brand Serif"), url(../fonts/brand.woff2) format("woff2"), url('../fonts/brand.woff') format("woff");
                font-weight: 700;
            }
            h1 { font-family: "Brand Serif"; background: url(banner.png) }
            CSS, self::BASE);

        self::assertSame(['https://example.com/css/fonts.css', 'https://example.com/print.css'], $sheet->imports);
        self::assertCount(1, $sheet->fontFaces);
        self::assertSame(
            ['https://example.com/fonts/brand.woff2', 'https://example.com/fonts/brand.woff'],
            $sheet->fontFaces[0]->urls(),
        );
    }

    #[Test]
    public function fontFacesInsideConditionalRulesCount(): void
    {
        $sheet = StyleSheetFonts::parse('@media print { @font-face { font-family: P; src: url(p.woff) } }', self::BASE);

        self::assertSame(['https://example.com/css/p.woff'], $sheet->fontFaces[0]->urls());
    }

    #[Test]
    public function commentsStringsAndEmbeddedFontsAreSkipped(): void
    {
        $sheet = StyleSheetFonts::parse(<<<'CSS'
            /* @font-face { font-family: Old; src: url(old.woff) } @import "old.css"; */
            .quote::before { content: "@font-face { src: url(\"quoted.woff\") }" }
            @font-face { font-family: Icons; src: url(data:font/woff2;base64,d09GMgABAAAA) format("woff2") }
            @font-face { font-family: Brand; /* the } in this comment ends nothing */ src: url("brand.woff") }
            CSS, self::BASE);

        self::assertSame([], $sheet->imports);
        self::assertCount(1, $sheet->fontFaces);
        self::assertSame(['https://example.com/css/brand.woff'], $sheet->fontFaces[0]->urls());
    }

    #[Test]
    public function aStyleSheetWithoutFontsOrImportsHasNothing(): void
    {
        $sheet = StyleSheetFonts::parse('h1 { color: red } p { background: url(x.png) }', self::BASE);

        self::assertSame([], $sheet->imports);
        self::assertSame([], $sheet->fontFaces);
    }

    #[Test]
    public function relativeUrlsNeedABaseUrl(): void
    {
        $sheet = StyleSheetFonts::parse(
            '@import "a.css"; @font-face { font-family: A; src: url(a.woff), url(https://cdn.example.net/a.woff) }',
            null,
        );

        self::assertSame([], $sheet->imports);
        self::assertSame(['https://cdn.example.net/a.woff'], $sheet->fontFaces[0]->urls());
    }

    #[Test]
    public function aFontFaceWithoutFontFilesIsSkipped(): void
    {
        $sheet = StyleSheetFonts::parse('@font-face { font-family: A; src: local("Arial") }', self::BASE);

        self::assertSame([], $sheet->fontFaces);
    }

    #[Test]
    public function withDataUrlsReplacesTheGivenFontsAndKeepsEverythingElse(): void
    {
        $rule = StyleSheetFonts::parse(
            '@font-face { font-family: "Brand"; src: local("Brand"), url(brand.woff2) format("woff2"), url(brand.woff) format("woff"); font-weight: 700; unicode-range: U+0000-00FF }',
            self::BASE,
        )->fontFaces[0];

        self::assertSame(
            '@font-face{font-family:"Brand";src:local("Brand"),url("data:font/woff2;base64,AAAA") format("woff2"),url("https://example.com/css/brand.woff") format("woff");font-weight:700;unicode-range:U+0000-00FF}',
            $rule->withDataUrls(['https://example.com/css/brand.woff2' => 'data:font/woff2;base64,AAAA']),
        );
        // The parsed rule is left as it was, ready for other replacements.
        self::assertStringContainsString(
            'url("https://example.com/css/brand.woff2") format("woff2"),url("data:x")',
            (string) $rule->withDataUrls(['https://example.com/css/brand.woff' => 'data:x']),
        );
    }

    #[Test]
    public function withDataUrlsIsNullWhenNoFontIsReplaced(): void
    {
        $rule = StyleSheetFonts::parse('@font-face { font-family: A; src: url(a.woff) }', self::BASE)->fontFaces[0];

        self::assertNull($rule->withDataUrls([]));
        self::assertNull($rule->withDataUrls(['https://example.com/css/other.woff' => 'data:x']));
    }
}
