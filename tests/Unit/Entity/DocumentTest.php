<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Document\DocumentRequest;
use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\DocumentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
    }

    #[Test]
    public function aNewSynchronousDocumentStartsWorkingAndKeepsNoPayload(): void
    {
        $document = new Document('owner', DocumentKind::Sync, $this->request(), 'https://host', $this->now);

        self::assertSame(DocumentStatus::Working, $document->status());
        self::assertTrue($document->isPending());
        self::assertNull($document->requestPayload());
        // Sync documents are neither hosted nor limited.
        self::assertNull($document->downloadLimit);
        self::assertNull($document->expiresAt());
        self::assertSame(0, $document->downloadCount());
        self::assertSame(32, \strlen($document->statusId));
        self::assertSame(43, \strlen($document->downloadId));
    }

    #[Test]
    public function aNewAsyncDocumentStartsQueued(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'b', $this->now);
        self::assertSame(DocumentStatus::Queued, $document->status());
        self::assertTrue($document->isPending());
    }

    #[Test]
    public function hostedDocumentsCarryTheDownloadLimitAndExpiry(): void
    {
        $request = $this->request(['hosted_download_limit' => 3, 'hosted_expires_at' => '2026-01-02T00:00:00Z']);
        $document = new Document('owner', DocumentKind::HostedAsync, $request, 'b', $this->now);

        self::assertSame(3, $document->downloadLimit);
        self::assertInstanceOf(\DateTimeImmutable::class, $document->expiresAt());
        self::assertSame('2026-01-02T00:00:00+00:00', $document->expiresAt()->format('c'));
    }

    #[Test]
    public function nonHostedDocumentsIgnoreTheHostedFields(): void
    {
        $request = $this->request(['hosted_download_limit' => 3, 'hosted_expires_at' => '2026-01-02T00:00:00Z']);
        $document = new Document('owner', DocumentKind::Async, $request, 'b', $this->now);

        self::assertNull($document->downloadLimit);
        self::assertNull($document->expiresAt());
    }

    #[Test]
    public function startMovesAQueuedDocumentToWorking(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'b', $this->now);
        $document->start();
        self::assertSame(DocumentStatus::Working, $document->status());
        self::assertTrue($document->isPending());
    }

    #[Test]
    public function completeRecordsTheResultAndClearsThePayload(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'b', $this->now, ['document_content' => 'x']);
        $completedAt = $this->now->modify('+1 hour');
        $document->complete(5, 2048, 'weasyprint', $completedAt);

        self::assertSame(DocumentStatus::Completed, $document->status());
        self::assertFalse($document->isPending());
        self::assertSame(5, $document->numberOfPages());
        self::assertSame('weasyprint', $document->engine());
        self::assertSame($completedAt, $document->completedAt());
        self::assertNull($document->requestPayload()); // dropped once rendered
    }

    #[Test]
    public function completeOnlyFillsAnEmptyExpiryFromTheDefault(): void
    {
        // A hosted document with its own expiry keeps it.
        $withOwn = new Document('owner', DocumentKind::Hosted, $this->request(['hosted_expires_at' => '2026-01-05T00:00:00Z']), 'b', $this->now);
        $withOwn->complete(1, 1, 'weasyprint', $this->now, $this->now->modify('+1 hour'));
        self::assertSame('2026-01-05T00:00:00+00:00', $withOwn->expiresAt()?->format('c'));

        // A document without one takes the default retention expiry.
        $withDefault = new Document('owner', DocumentKind::Async, $this->request(), 'b', $this->now);
        $default = $this->now->modify('+24 hours');
        $withDefault->complete(1, 1, 'weasyprint', $this->now, $default);
        self::assertSame($default, $withDefault->expiresAt());
    }

    #[Test]
    public function failRecordsTheFirstMessageAndAllOfThem(): void
    {
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'b', $this->now, ['document_content' => 'x']);
        $document->fail(['First problem', 'Second problem'], $this->now);

        self::assertSame(DocumentStatus::Failed, $document->status());
        self::assertSame('First problem', $document->message());
        self::assertSame("First problem\nSecond problem", $document->validationErrors());
        self::assertNull($document->requestPayload());
        self::assertSame($this->now, $document->completedAt());
    }

    #[Test]
    public function expireSetsTheStatusAndStampsTheExpiry(): void
    {
        $document = new Document('owner', DocumentKind::Hosted, $this->request(), 'b', $this->now);
        $document->complete(1, 1, 'weasyprint', $this->now);

        $expiredAt = $this->now->modify('+5 hours');
        $document->expire($expiredAt);
        self::assertSame(DocumentStatus::Expired, $document->status());
        self::assertSame($expiredAt, $document->expiresAt());
    }

    #[Test]
    public function expireDoesNotPushAnEarlierExpiryForward(): void
    {
        $document = new Document('owner', DocumentKind::Hosted, $this->request(['hosted_expires_at' => '2026-01-01T01:00:00Z']), 'b', $this->now);
        $document->complete(1, 1, 'weasyprint', $this->now);

        // Expiring later must keep the earlier stamp.
        $document->expire($this->now->modify('+5 hours'));
        self::assertSame('2026-01-01T01:00:00+00:00', $document->expiresAt()?->format('c'));
    }

    #[Test]
    public function isExpiredAtReflectsTheStatusAndTheExpiry(): void
    {
        $document = new Document('owner', DocumentKind::Hosted, $this->request(['hosted_expires_at' => '2026-01-01T02:00:00Z']), 'b', $this->now);
        $document->complete(1, 1, 'weasyprint', $this->now);

        self::assertFalse($document->isExpiredAt($this->now));
        self::assertTrue($document->isExpiredAt($this->now->modify('+2 hours'))); // at the boundary
        self::assertTrue($document->isExpiredAt($this->now->modify('+3 hours')));
    }

    #[Test]
    public function aDocumentWithNoExpiryNeverExpiresByTime(): void
    {
        $document = new Document('owner', DocumentKind::HostedAsync, $this->request(), 'b', $this->now);
        $document->complete(1, 1, 'weasyprint', $this->now); // no default expiry passed
        self::assertNull($document->expiresAt());
        self::assertFalse($document->isExpiredAt($this->now->modify('+100 years')));
    }

    #[Test]
    public function theQueuedRequestPayloadRoundTrips(): void
    {
        $payload = ['document_content' => '<p>x</p>', 'name' => 'My File', 'prince_options' => ['encrypt' => true]];
        $document = new Document('owner', DocumentKind::Async, $this->request(), 'b', $this->now, $payload);
        self::assertSame($payload, $document->requestPayload());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function request(array $overrides = []): DocumentRequest
    {
        return DocumentRequest::fromRequest(['document_content' => '<p>x</p>', 'name' => 'My File', 'tag' => 'tag-1', ...$overrides]);
    }
}
