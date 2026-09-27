<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class PdfOptionsTest extends EndToEndTestCase
{
    #[DataProvider('engines')]
    public function testEncryptionAndPermissions(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<p>secret</p>',
            'prince_options' => ['encrypt' => true, 'user_password' => 'open', 'owner_password' => 'owner', 'disallow_print' => true],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertTrue(PdfFile::requiresPassword($response->getContent()));
        $pdf = PdfFile::fromBytes($response->getContent(), 'open');
        self::assertTrue($pdf->isEncrypted());
        self::assertFalse($pdf->allows('printhigh'));
        self::assertTrue($pdf->allows('extract'));
        self::assertStringContainsString('secret', $pdf->text());
    }

    public function testFortyBitEncryption(): void
    {
        $response = self::render(['document_content' => '<p>x</p>', 'prince_options' => ['encrypt' => true, 'key_bits' => 40]]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame(40, PdfFile::fromBytes($response->getContent())->encryptionBits());
    }

    public function testPdfAProfile(): void
    {
        $response = self::render(['document_content' => '<p>archive</p>', 'prince_options' => ['profile' => 'PDF/A-3b']]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('3B', PdfFile::fromBytes($response->getContent())->pdfaConformance());
    }

    public function testPdfUaProfileIsTagged(): void
    {
        $response = self::render(['document_content' => '<h1>Title</h1><p>x</p>', 'prince_options' => ['profile' => 'PDF/UA-1']]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertTrue(PdfFile::fromBytes($response->getContent())->hasStructureTree());
    }

    public function testProfilesNeedWeasyPrint(): void
    {
        $response = self::render(['pipeline' => 'chromium', 'document_content' => '<p>x</p>', 'prince_options' => ['profile' => 'PDF/A-1b']]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testPdfACannotBeEncrypted(): void
    {
        $response = self::render(['document_content' => '<p>x</p>', 'prince_options' => ['profile' => 'PDF/A-1b', 'encrypt' => true]]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testPageMargin(): void
    {
        self::assertSame(200, self::render(['document_content' => '<p>x</p>', 'prince_options' => ['page_margin' => '1in']])->getStatusCode());

        $injected = self::render(['document_content' => '<p>x</p>', 'prince_options' => ['page_margin' => '1in; } body { color: red']]);
        self::assertSame(400, $injected->getStatusCode());
    }

    public function testStrictHtmlValidation(): void
    {
        $invalid = self::render(['strict' => 'html', 'document_content' => '<html><body><p>unclosed <b>bold</p>']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('<error>', $invalid->getContent(false));

        $valid = self::render([
            'strict' => 'html',
            'document_content' => '<!DOCTYPE html><html><head><title>t</title></head><body><p>fine</p></body></html>',
        ]);
        self::assertSame(200, $valid->getStatusCode(), $valid->getContent(false));
    }

    public function testUnsupportedDocumentType(): void
    {
        $response = self::render(['document_type' => 'xlsx', 'document_content' => '<table><tr><td>1</td></tr></table>']);

        self::assertSame(400, $response->getStatusCode());
    }
}
