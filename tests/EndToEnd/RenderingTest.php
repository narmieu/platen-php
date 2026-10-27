<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class RenderingTest extends EndToEndTestCase
{
    #[DataProvider('engines')]
    public function testRendersPdfWithPrincePageDefaults(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render(['pipeline' => $engine, 'document_content' => '<h1>Sertifikat</h1><p>Đorđe Šćepanović-Žižić</p>']);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $headers = $response->getHeaders();
        self::assertSame('application/pdf', $headers['content-type'][0]);
        self::assertSame('1', $headers['x-docraptor-num-pages'][0]);
        self::assertSame($engine, $headers['x-platen-engine'][0]);
        self::assertSame('no-store, private', $headers['cache-control'][0]);
        self::assertStringStartsWith('%PDF-', $response->getContent());
        $pdf = PdfFile::fromBytes($response->getContent());
        self::assertSame([612, 792], $pdf->pageSize(), 'US Letter, like Prince');
        self::assertStringContainsString('Đorđe Šćepanović-Žižić', $pdf->text());
    }

    #[DataProvider('engines')]
    public function testCssPageSizeWinsOverDefaults(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<style>@page { size: A4 landscape; margin: 0 }</style><p>Landscape</p>';
        $response = self::render(['pipeline' => $engine, 'document_content' => $html]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame([842, 595], PdfFile::fromBytes($response->getContent())->pageSize());
    }

    public function testNumericDocRaptorPipelineUsesTheDefaultEngine(): void
    {
        $response = self::render(['pipeline' => '10.1', 'document_content' => '<p>x</p>']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('weasyprint', $response->getHeaders()['x-platen-engine'][0]);
    }

    #[DataProvider('engines')]
    public function testTestDocumentsAreWatermarked(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<p>'.str_repeat('Line of text. ', 400).'</p>';

        $marked = self::render(['pipeline' => $engine, 'test' => true, 'document_content' => $html]);
        self::assertSame(200, $marked->getStatusCode());
        $pages = (int) $marked->getHeaders()['x-docraptor-num-pages'][0];
        self::assertGreaterThan(1, $pages);
        self::assertSame($pages, substr_count(PdfFile::fromBytes($marked->getContent())->text(), 'TEST DOCUMENT'));

        $clean = self::render(['pipeline' => $engine, 'test' => false, 'document_content' => $html]);
        self::assertStringNotContainsString('TEST DOCUMENT', PdfFile::fromBytes($clean->getContent())->text());
    }

    public function testTestDefaultsToTrueLikeDocRaptor(): void
    {
        $response = self::client()->request('POST', '/docs', ['json' => ['document_type' => 'pdf', 'document_content' => '<p>x</p>']]);

        self::assertStringContainsString('TEST DOCUMENT', PdfFile::fromBytes($response->getContent())->text());
    }

    #[DataProvider('engines')]
    public function testPdfTitle(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<title>Original</title><p>x</p>',
            'prince_options' => ['pdf_title' => 'Certificate 2026'],
        ]);

        self::assertSame('Certificate 2026', PdfFile::fromBytes($response->getContent())->title());
    }

    #[DataProvider('engines')]
    public function testDocumentTitleIsKeptAndAnUntitledDocumentHasNone(string $engine): void
    {
        self::requireEngine($engine);
        $titled = self::render(['pipeline' => $engine, 'document_content' => '<title>Original</title><p>x</p>']);
        $untitled = self::render(['pipeline' => $engine, 'document_content' => '<p>x</p>']);

        self::assertSame('Original', PdfFile::fromBytes($titled->getContent())->title());
        // Not the report platen's page hook leaves in the title.
        self::assertNull(PdfFile::fromBytes($untitled->getContent())->title());
    }

    #[DataProvider('engines')]
    public function testMediaScreen(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<style>
              @media print { .screen-only { display: none } }
              @media screen { .print-only { display: none } }
            </style><p class="screen-only">SCREENTEXT</p><p class="print-only">PRINTTEXT</p>';

        $printed = PdfFile::fromBytes(self::render(['pipeline' => $engine, 'document_content' => $html])->getContent())->text();
        self::assertStringContainsString('PRINTTEXT', $printed);
        self::assertStringNotContainsString('SCREENTEXT', $printed);

        $screen = PdfFile::fromBytes(self::render([
            'pipeline' => $engine,
            'document_content' => $html,
            'prince_options' => ['media' => 'screen'],
        ])->getContent())->text();
        self::assertStringContainsString('SCREENTEXT', $screen);
        self::assertStringNotContainsString('PRINTTEXT', $screen);
    }

    public function testPresentationalAttributesAreHonoured(): void
    {
        $html = '<table border="1" width="100%"><tr><td bgcolor="#ff0000">cell</td></tr></table>';

        self::assertSame(200, self::render(['document_content' => $html])->getStatusCode());
    }

    public function testDownloadFilenameKeepsUnicode(): void
    {
        $response = self::render(['name' => 'Sertifikat Đorđe', 'document_content' => '<p>x</p>']);

        self::assertStringContainsString(
            "filename*=UTF-8''Sertifikat%20%C4%90or%C4%91e.pdf",
            $response->getHeaders()['content-disposition'][0],
        );
    }
}
