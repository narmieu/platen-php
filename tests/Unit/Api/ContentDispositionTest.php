<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\ContentDisposition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentDispositionTest extends TestCase
{
    #[Test]
    public function itAppendsAPdfExtensionWhenMissing(): void
    {
        self::assertSame(
            'inline; filename="report.pdf"; filename*=UTF-8\'\'report.pdf',
            ContentDisposition::inline('report'),
        );
    }

    #[Test]
    public function itKeepsAnExistingPdfExtension(): void
    {
        // The extension check is case-insensitive.
        self::assertSame(
            'inline; filename="report.pdf"; filename*=UTF-8\'\'report.pdf',
            ContentDisposition::inline('report.pdf'),
        );
        self::assertStringContainsString('filename="report.PDF"', ContentDisposition::inline('report.PDF'));
    }

    #[Test]
    public function unicodeNamesArePercentEncodedInTheExtendedField(): void
    {
        $header = ContentDisposition::inline('Réport Févr');
        // The ASCII fallback strips non-ASCII bytes; filename* carries the UTF-8 form.
        self::assertStringContainsString('filename="Rport Fvr.pdf"', $header);
        self::assertStringContainsString("filename*=UTF-8''R%C3%A9port%20F%C3%A9vr.pdf", $header);
    }

    #[Test]
    public function aFullyNonAsciiNameFallsBackToDocumentPdf(): void
    {
        $header = ContentDisposition::inline('发票.pdf');
        self::assertStringContainsString('filename="document.pdf"', $header);
        self::assertStringContainsString("filename*=UTF-8''%E5%8F%91%E7%A5%A8.pdf", $header);
    }

    #[Test]
    public function itStripsQuotesAndBackslashesFromTheFallback(): void
    {
        $header = ContentDisposition::inline('na"me\\x');
        self::assertStringContainsString('filename="namex.pdf"', $header);
        // The extended field keeps and percent-encodes the original characters.
        self::assertStringContainsString("filename*=UTF-8''na%22me%5Cx.pdf", $header);
    }

    #[Test]
    public function controlCharactersAreRemoved(): void
    {
        $header = ContentDisposition::inline("caf\xc3\xa9\tbreak\nline");
        // Tab and newline are stripped from both forms.
        self::assertStringContainsString('filename="cafbreakline.pdf"', $header);
        self::assertStringContainsString("filename*=UTF-8''caf%C3%A9breakline.pdf", $header);
    }

    #[Test]
    public function onlyControlCharactersFallBackToDocumentPdf(): void
    {
        $header = ContentDisposition::inline("\x01\x02");
        self::assertSame('inline; filename="document.pdf"; filename*=UTF-8\'\'.pdf', $header);
    }
}
