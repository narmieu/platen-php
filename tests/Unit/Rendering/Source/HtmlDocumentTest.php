<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Source;

use App\Rendering\Source\HtmlDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlDocumentTest extends TestCase
{
    #[Test]
    public function toHtmlAddsADoctypeAndASingleUtf8Meta(): void
    {
        $html = HtmlDocument::parse('<p>Hi</p>')->toHtml();
        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertSame(1, substr_count($html, '<meta charset="utf-8">'));
        self::assertStringContainsString('<p>Hi</p>', $html);
    }

    #[Test]
    public function itKeepsAnExistingDoctypeWithoutDuplicatingIt(): void
    {
        $html = HtmlDocument::parse('<!DOCTYPE html><html><body><p>Hi</p></body></html>')->toHtml();
        self::assertSame(1, substr_count($html, '<!DOCTYPE html>'));
    }

    #[Test]
    public function itReplacesAnyExistingCharsetDeclarations(): void
    {
        $source = '<!DOCTYPE html><html><head>'
            .'<meta charset="windows-1250">'
            .'<meta http-equiv="Content-Type" content="text/html; charset=windows-1250">'
            .'<title>T</title></head><body>x</body></html>';
        $html = HtmlDocument::parse($source, null)->toHtml();

        self::assertSame(1, substr_count($html, '<meta charset="utf-8">'));
        self::assertStringNotContainsString('windows-1250', $html);
        self::assertStringNotContainsString('Content-Type', $html);
        self::assertStringContainsString('<title>T</title>', $html);
    }

    #[Test]
    public function itDecodesLegacyWindows1250BytesToUtf8(): void
    {
        // 0x8a and 0x9e are Š and ž in Windows-1250; the transport encoding is declared in <meta>.
        $source = '<!DOCTYPE html><html><head><meta charset="windows-1250"></head><body>'
            ."\x8a\x9e".'</body></html>';
        $html = HtmlDocument::parse($source, null)->toHtml();

        self::assertStringContainsString('Šž', $html);
        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
    }

    #[Test]
    public function itHonoursATransportEncodingArgument(): void
    {
        // No <meta>: the encoding comes from the Content-Type header instead.
        $html = HtmlDocument::parse('<html><body>'."\x8a\x9e".'</body></html>', 'windows-1250')->toHtml();
        self::assertStringContainsString('Šž', $html);
    }

    #[Test]
    public function anUnknownEncodingFallsBackToUtf8Parsing(): void
    {
        // ValueError from an unknown encoding is caught and the parser retries without it.
        $html = HtmlDocument::parse('<html><body>čž</body></html>', 'no-such-encoding')->toHtml();
        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertTrue(mb_check_encoding($html, 'UTF-8'));
    }

    #[Test]
    public function utf8InputSurvivesTheRoundTrip(): void
    {
        $html = HtmlDocument::parse('<html><body>čž€</body></html>')->toHtml();
        self::assertStringContainsString('čž€', $html);
    }

    #[Test]
    public function hasBaseDetectsABaseHref(): void
    {
        self::assertTrue(HtmlDocument::parse('<head><base href="https://x/"></head>')->hasBase());
        self::assertFalse(HtmlDocument::parse('<head><base></head>')->hasBase()); // no href attribute
        self::assertFalse(HtmlDocument::parse('<p>x</p>')->hasBase());
    }

    #[Test]
    public function setTitleSetsTheDocumentTitle(): void
    {
        $document = HtmlDocument::parse('<head><title>Old</title></head><body>x</body>');
        $document->setTitle('New');
        $html = $document->toHtml();
        self::assertStringContainsString('<title>New</title>', $html);
        self::assertStringNotContainsString('Old', $html);
    }

    #[Test]
    public function unwrapReplacesElementsWithTheirChildren(): void
    {
        $document = HtmlDocument::parse('<body><noscript><p>fallback</p></noscript></body>');
        $document->unwrap('noscript');
        $html = $document->toHtml();
        self::assertStringContainsString('<p>fallback</p>', $html);
        self::assertStringNotContainsString('<noscript>', $html);
    }

    #[Test]
    public function elementBuildsANodeWithAttributesAndText(): void
    {
        $document = HtmlDocument::parse('<body></body>');
        $element = $document->element('div', 'hello', ['class' => 'note', 'data-x' => '1']);
        self::assertSame('div', $element->localName);
        self::assertSame('note', $element->getAttribute('class'));
        self::assertSame('1', $element->getAttribute('data-x'));
        self::assertSame('hello', $element->textContent);
    }
}
