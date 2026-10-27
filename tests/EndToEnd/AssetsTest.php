<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class AssetsTest extends EndToEndTestCase
{
    #[DataProvider('engines')]
    public function testDocumentUrlWithRelativeAssets(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render(['pipeline' => $engine, 'document_url' => self::assets()->url('/page.html')]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $pdf = PdfFile::fromBytes($response->getContent());
        self::assertStringContainsString('From URL styled', $pdf->text());
        self::assertSame(1, $pdf->imageCount());
    }

    #[DataProvider('engines')]
    public function testBaseUrlResolvesRelativeAssets(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<link rel="stylesheet" href="style.css"><h1>Base</h1><img src="pixel.png" width="30" height="30">',
            'prince_options' => ['baseurl' => self::assets()->url('/')],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $pdf = PdfFile::fromBytes($response->getContent());
        self::assertStringContainsString('Base styled', $pdf->text());
        self::assertSame(1, $pdf->imageCount());
    }

    /**
     * The font's server sends no Access-Control-Allow-Origin header, which Chromium needs
     * for a page from another origin, as platen-chromium serves it.
     */
    #[DataProvider('engines')]
    public function testWebFontsWithoutCorsHeaders(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<link rel="stylesheet" href="fonts.css"><p style="font-family: \'Platen Test\', monospace">Certificate</p>',
            'prince_options' => ['baseurl' => self::assets()->url('/')],
            'ignore_resource_errors' => false,
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        // Chromium names the font PlatenTest-Regular, WeasyPrint Platen-Test.
        $fonts = PdfFile::fromBytes($response->getContent())->fontNames();
        self::assertNotEmpty(preg_grep('/^Platen-?Test/', $fonts), 'Embedded fonts: '.implode(', ', $fonts));
    }

    public function testMissingFontFailsWhenNotIgnored(): void
    {
        self::requireEngine('chromium');
        $html = '<style>@font-face { font-family: Gone; src: url('.self::assets()->url('/fonts/gone.woff').') }</style><p style="font-family: Gone">x</p>';

        self::assertSame(200, self::render(['pipeline' => 'chromium', 'document_content' => $html])->getStatusCode());

        $response = self::render(['pipeline' => 'chromium', 'document_content' => $html, 'ignore_resource_errors' => false]);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('font Gone', $response->getContent(false));
    }

    #[DataProvider('engines')]
    public function testDocumentUrlErrors(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render(['pipeline' => $engine, 'document_url' => self::assets()->url('/nothing-here.html')]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('<errors>', $response->getContent(false));
    }

    #[DataProvider('engines')]
    public function testMissingResourceIsIgnoredByDefault(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<p>ok</p><img src="'.self::assets()->url('/missing.png').'">';

        self::assertSame(200, self::render(['pipeline' => $engine, 'document_content' => $html])->getStatusCode());
    }

    #[DataProvider('engines')]
    public function testMissingResourceFailsWhenNotIgnored(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<p>ok</p><img src="'.self::assets()->url('/missing.png').'">';
        $response = self::render(['pipeline' => $engine, 'document_content' => $html, 'ignore_resource_errors' => false]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('missing.png', $response->getContent(false));
    }

    #[DataProvider('engines')]
    public function testHttpAuthentication(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<img src="'.self::assets()->url('/protected.png').'" width="20" height="20">';

        $denied = self::render(['pipeline' => $engine, 'document_content' => $html]);
        self::assertSame(0, PdfFile::fromBytes($denied->getContent())->imageCount());

        $allowed = self::render([
            'pipeline' => $engine,
            'document_content' => $html,
            'prince_options' => ['http_user' => 'user', 'http_password' => 'pass'],
        ]);
        self::assertSame(1, PdfFile::fromBytes($allowed->getContent())->imageCount());
    }

    #[DataProvider('engines')]
    public function testReferrerAndUserAgentToken(string $engine): void
    {
        self::requireEngine($engine);
        $marker = "token-{$engine}";
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<img src="'.self::assets()->url('/ua.png')."?{$marker}\" width=\"10\" height=\"10\">",
            'referrer' => 'https://app.example.com/certificates',
            'user_agent_token' => $marker,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $requests = self::assets()->headersFor('/ua.png');
        $headers = end($requests);
        self::assertIsArray($headers);
        self::assertStringEndsWith($marker, $headers['user-agent'] ?? '');
        self::assertSame('https://app.example.com/certificates', $headers['referer'] ?? null);
    }
}
