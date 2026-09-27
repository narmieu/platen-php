<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use App\Tests\Support\PlatenServer;
use DocRaptor\ApiException;
use DocRaptor\Configuration;
use DocRaptor\Doc;
use DocRaptor\DocApi;
use DocRaptor\DocStatus;
use DocRaptor\PrinceOptions;

/**
 * DocRaptor's official PHP client, pointed at platen. The only change from talking to
 * DocRaptor is setHost().
 */
final class OfficialClientTest extends EndToEndTestCase
{
    public function testCreateDoc(): void
    {
        $pdf = self::docRaptor()->createDoc(self::certificate());

        self::assertStringContainsString('Đorđe Šćepanović', PdfFile::fromBytes($pdf)->text());
    }

    public function testCreateAsyncDoc(): void
    {
        $docRaptor = self::docRaptor();
        $statusId = $docRaptor->createAsyncDoc(self::certificate())->getStatusId();
        self::assertNotNull($statusId);

        $status = self::waitUntilDone($docRaptor, $statusId);
        self::assertSame('completed', $status->getStatus());
        self::assertSame(1, $status->getNumberOfPages());
        $downloadId = $status->getDownloadId();
        self::assertNotNull($downloadId);
        self::assertStringStartsWith('%PDF-', $docRaptor->getAsyncDoc($downloadId));
    }

    public function testExpiredHostedDocCannotBeDownloaded(): void
    {
        $docRaptor = self::docRaptor();
        $hosted = $docRaptor->createHostedDoc(self::certificate());
        self::assertStringContainsString('/download/', (string) $hosted->getDownloadUrl());
        $downloadId = $hosted->getDownloadId();
        self::assertNotNull($downloadId);

        $docRaptor->expire($downloadId);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(400);
        $docRaptor->getAsyncDoc($downloadId);
    }

    public function testWrongKeyIsRejected(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        self::docRaptor('wrong-key')->createDoc(self::certificate());
    }

    private static function docRaptor(string $key = PlatenServer::API_KEY): DocApi
    {
        $config = new Configuration();
        $config->setUsername($key);
        $config->setHost(self::platen()->url);

        return new DocApi(null, $config);
    }

    private static function certificate(): Doc
    {
        $princeOptions = new PrinceOptions();
        $princeOptions->setMedia('print');
        $doc = new Doc();
        $doc->setTest(false);
        $doc->setName('certificate');
        $doc->setDocumentType('pdf');
        $doc->setDocumentContent('<html><body><h1>Sertifikat</h1><p>Đorđe Šćepanović</p></body></html>');
        $doc->setPrinceOptions($princeOptions);

        return $doc;
    }

    private static function waitUntilDone(DocApi $docRaptor, string $statusId): DocStatus
    {
        $deadline = microtime(true) + 60;
        do {
            $status = $docRaptor->getAsyncDocStatus($statusId);
            if (\in_array($status->getStatus(), ['completed', 'failed'], true)) {
                return $status;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        self::fail('The document did not finish');
    }
}
