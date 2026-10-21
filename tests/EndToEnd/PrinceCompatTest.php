<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;

final class PrinceCompatTest extends EndToEndTestCase
{
    public function testScriptFreeJavaScriptRequestUsesWeasyPrint(): void
    {
        $response = self::render(['pipeline' => '10', 'javascript' => true, 'document_content' => '<p>x</p>']);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('weasyprint', $response->getHeaders()['x-platen-engine'][0]);
    }

    public function testScriptedJavaScriptRequestUsesChromium(): void
    {
        self::requireEngine('chromium');
        $response = self::render([
            'pipeline' => '10',
            'javascript' => true,
            'document_content' => "<p>x</p><script>document.body.append('y')</script>",
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('chromium', $response->getHeaders()['x-platen-engine'][0]);
    }

    public function testPrincePipelineWritesAnRgbPageGroupOnTransparentPages(): void
    {
        $html = '<p style="color: rgba(0,0,0,.5)">x</p>';

        $prince = self::render(['pipeline' => '10', 'document_content' => $html]);
        self::assertSame(200, $prince->getStatusCode(), $prince->getContent(false));
        self::assertSame('/DeviceRGB', PdfFile::fromBytes($prince->getContent())->pageGroupColorSpace());

        $plain = self::render(['document_content' => $html]);
        self::assertSame(200, $plain->getStatusCode(), $plain->getContent(false));
        self::assertNull(PdfFile::fromBytes($plain->getContent())->pageGroupColorSpace());
    }
}
