<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\StatusPayload;
use App\Document\DocumentRequest;
use App\Entity\Document;
use App\Entity\DocumentKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatusPayloadTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }

    #[Test]
    public function aQueuedDocumentReportsItsStatus(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'https://host', $this->now);
        self::assertSame(['status' => 'queued'], StatusPayload::of($document, 'https://host'));
    }

    #[Test]
    public function aWorkingDocumentReportsItsStatus(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'https://host', $this->now);
        $document->start();
        self::assertSame(['status' => 'working'], StatusPayload::of($document, 'https://host'));
    }

    #[Test]
    public function aCompletedDocumentReportsTheDownloadUrlAndPages(): void
    {
        $document = new Document('owner', DocumentKind::HostedAsync, $this->request(), 'https://host', $this->now);
        $document->complete(5, 1234, 'weasyprint', $this->now->modify('+1 hour'));

        $payload = StatusPayload::of($document, 'https://host');
        self::assertSame('completed', $payload['status']);
        self::assertSame('https://host/download/'.$document->downloadId, $payload['download_url']);
        self::assertSame($document->downloadId, $payload['download_id']);
        self::assertSame(5, $payload['number_of_pages']);
        // The completion time is formatted as UTC in DocRaptor's style.
        self::assertSame('Completed at Thu Jan 01 01:00:00 +0000 2026', $payload['message']);
    }

    #[Test]
    public function aFailedDocumentReportsTheMessageAndValidationErrors(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'https://host', $this->now);
        $document->fail(['First error', 'Second error'], $this->now);

        self::assertSame([
            'status' => 'failed',
            'message' => 'First error',
            'validation_errors' => "First error\nSecond error",
        ], StatusPayload::of($document, 'https://host'));
    }

    #[Test]
    public function anExpiredDocumentIsReportedAsFailed(): void
    {
        // Expired is not a DocRaptor status; it is surfaced as failed so polling loops end.
        $document = new Document('owner', DocumentKind::Hosted, $this->request(), 'https://host', $this->now);
        $document->complete(1, 1, 'weasyprint', $this->now);
        $document->expire($this->now->modify('+1 hour'));

        self::assertSame([
            'status' => 'failed',
            'message' => 'This document has expired',
            'validation_errors' => '',
        ], StatusPayload::of($document, 'https://host'));
    }

    private function request(): DocumentRequest
    {
        return DocumentRequest::fromRequest(['document_content' => '<p>x</p>', 'name' => 'My File']);
    }
}
