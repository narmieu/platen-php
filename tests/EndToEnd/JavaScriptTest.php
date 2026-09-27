<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class JavaScriptTest extends EndToEndTestCase
{
    private const string SCRIPTED = "<p id=x>static</p><script>document.getElementById('x').textContent = 'dynamic'</script>";

    #[DataProvider('engines')]
    public function testJavaScriptIsDisabledByDefault(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render(['pipeline' => $engine, 'document_content' => self::SCRIPTED]);

        self::assertStringContainsString('static', PdfFile::fromBytes($response->getContent())->text());
    }

    #[DataProvider('engines')]
    public function testJavaScriptRunsWhenEnabled(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render(['pipeline' => $engine, 'javascript' => true, 'document_content' => self::SCRIPTED]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('dynamic', PdfFile::fromBytes($response->getContent())->text());
    }

    public function testPrinceJavaScriptOptionAlsoRunsScripts(): void
    {
        self::requireEngine('chromium');
        $response = self::render(['document_content' => self::SCRIPTED, 'prince_options' => ['javascript' => true]]);

        self::assertStringContainsString('dynamic', PdfFile::fromBytes($response->getContent())->text());
    }

    public function testWaitsForDocRaptorJavaScriptFinished(): void
    {
        self::requireEngine('chromium');
        $html = "<p id=x>waiting</p><script>
              var done = false;
              setTimeout(function () { document.getElementById('x').textContent = 'finished late'; done = true; }, 1200);
              function docraptorJavaScriptFinished() { return done; }
            </script>";
        $response = self::render(['javascript' => true, 'document_content' => $html]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('finished late', PdfFile::fromBytes($response->getContent())->text());
    }

    public function testConsoleOutputStopsRenderingLikeDocRaptor(): void
    {
        self::requireEngine('chromium');
        $html = "<p>x</p><script>console.log('debug output')</script>";

        $response = self::render(['javascript' => true, 'document_content' => $html]);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('debug output', $response->getContent(false));

        $allowed = self::render(['javascript' => true, 'ignore_console_messages' => true, 'document_content' => $html]);
        self::assertSame(200, $allowed->getStatusCode());
    }

    public function testCanvasIsPrinted(): void
    {
        self::requireEngine('chromium');
        $html = "<canvas id=c width=120 height=60></canvas><script>
              var ctx = document.getElementById('c').getContext('2d');
              ctx.fillStyle = '#c33'; ctx.fillRect(0, 0, 120, 60);
            </script>";
        $response = self::render(['javascript' => true, 'document_content' => $html]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(1, PdfFile::fromBytes($response->getContent())->imageCount());
    }

    public function testPagedMediaCssWorksWithJavaScript(): void
    {
        self::requireEngine('chromium');
        $html = "<style>
              @page { size: A5; @bottom-center { content: \"Page \" counter(page) \" of \" counter(pages) } }
              h1 { page-break-before: always }
            </style><h1>One</h1><h1 id=two>Two</h1>
            <script>document.getElementById('two').textContent = 'Two (JS)'</script>";
        $response = self::render(['javascript' => true, 'document_content' => $html]);

        $pdf = PdfFile::fromBytes($response->getContent());
        self::assertSame([420, 595], $pdf->pageSize());
        self::assertStringContainsString('Two (JS)', $pdf->text());
        self::assertStringContainsString('Page 2 of 2', $pdf->text());
    }
}
