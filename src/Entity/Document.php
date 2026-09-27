<?php

declare(strict_types=1);

namespace App\Entity;

use App\Document\DocumentRequest;
use App\Repository\DocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One document request: its status, its stored PDF (if any) and its log entry.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\UniqueConstraint(name: 'document_status_id', columns: ['status_id'])]
#[ORM\UniqueConstraint(name: 'document_download_id', columns: ['download_id'])]
#[ORM\Index(name: 'document_owner_created', columns: ['owner', 'created_at'])]
#[ORM\Index(name: 'document_status', columns: ['status'])]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    public readonly string $statusId;

    #[ORM\Column(length: 43)]
    public readonly string $downloadId;

    /** sha256 of the API key: documents belong to the key that created them. */
    #[ORM\Column(length: 64)]
    public readonly string $owner;

    #[ORM\Column(length: 16, enumType: DocumentKind::class)]
    public readonly DocumentKind $kind;

    #[ORM\Column(type: Types::TEXT)]
    public readonly string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public readonly ?string $tag;

    #[ORM\Column(length: 8)]
    public readonly string $documentType;

    #[ORM\Column]
    public readonly bool $test;

    /** Where the client reached platen; download links sent in callbacks use it. */
    #[ORM\Column(type: Types::TEXT)]
    public readonly string $baseUrl;

    #[ORM\Column(nullable: true)]
    public readonly ?int $downloadLimit;

    #[ORM\Column]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 16, enumType: DocumentStatus::class)]
    private DocumentStatus $status;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $engine = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $validationErrors = null;

    #[ORM\Column(nullable: true)]
    private ?int $numberOfPages = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    /** The request of a queued document, as JSON, until it is rendered. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $request = null;

    #[ORM\Column]
    private int $downloadCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * @param array<array-key, mixed>|null $payload the raw request, kept until a queued document is rendered
     */
    public function __construct(
        string $owner,
        DocumentKind $kind,
        DocumentRequest $request,
        string $baseUrl,
        \DateTimeImmutable $now,
        ?array $payload = null,
    ) {
        $this->statusId = bin2hex(random_bytes(16));
        $this->downloadId = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->owner = $owner;
        $this->kind = $kind;
        $this->name = $request->name;
        $this->tag = $request->tag;
        $this->documentType = $request->documentType;
        $this->test = $request->test;
        $this->baseUrl = $baseUrl;
        $this->downloadLimit = $kind->isHosted() ? $request->hostedDownloadLimit : null;
        $this->expiresAt = $kind->isHosted() ? $request->hostedExpiresAt : null;
        $this->createdAt = $now;
        $this->status = $kind->isAsync() ? DocumentStatus::Queued : DocumentStatus::Working;
        $this->request = null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function engine(): ?string
    {
        return $this->engine;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function validationErrors(): ?string
    {
        return $this->validationErrors;
    }

    public function numberOfPages(): ?int
    {
        return $this->numberOfPages;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isPending(): bool
    {
        return DocumentStatus::Queued === $this->status || DocumentStatus::Working === $this->status;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function requestPayload(): ?array
    {
        if (null === $this->request) {
            return null;
        }
        $payload = json_decode($this->request, true);

        return \is_array($payload) ? $payload : null;
    }

    public function start(): void
    {
        $this->status = DocumentStatus::Working;
    }

    /**
     * @param int|null $size the stored PDF's size; null for documents that are not stored
     */
    public function complete(int $pages, ?int $size, string $engine, \DateTimeImmutable $now, ?\DateTimeImmutable $defaultExpiry = null): void
    {
        $this->status = DocumentStatus::Completed;
        $this->numberOfPages = $pages;
        $this->size = $size;
        $this->engine = $engine;
        $this->request = null;
        $this->completedAt = $now;
        $this->expiresAt ??= $defaultExpiry;
    }

    /**
     * @param non-empty-list<string> $messages
     */
    public function fail(array $messages, \DateTimeImmutable $now): void
    {
        $this->status = DocumentStatus::Failed;
        $this->message = $messages[0];
        $this->validationErrors = implode("\n", $messages);
        $this->request = null;
        $this->completedAt = $now;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->status = DocumentStatus::Expired;
        if (null === $this->expiresAt || $this->expiresAt > $now) {
            $this->expiresAt = $now;
        }
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return DocumentStatus::Expired === $this->status || (null !== $this->expiresAt && $this->expiresAt <= $now);
    }

    public function downloadCount(): int
    {
        return $this->downloadCount;
    }
}
