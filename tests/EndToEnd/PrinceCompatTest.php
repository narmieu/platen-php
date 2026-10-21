<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

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
}
