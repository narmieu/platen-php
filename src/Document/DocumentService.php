<?php

declare(strict_types=1);

namespace App\Document;

use App\Api\ApiException;
use App\Config\PlatenConfig;
use App\Entity\Document;
use App\Entity\DocumentKind;
use App\Entity\DocumentStatus;
use App\Messenger\RenderDocument;
use App\Messenger\SendCallback;
use App\Rendering\Deadline;
use App\Rendering\Renderer;
use App\Rendering\RenderResult;
use App\Repository\DocumentRepository;
use App\Storage\PdfStorage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Synchronous, hosted and queued documents, downloads and their clean-up.
 */
final readonly class DocumentService
{
    /** Attempts for a queued document when the renderer itself is unavailable. */
    private const int ATTEMPTS = 3;

    public function __construct(
        private Renderer $renderer,
        private DocumentRepository $documents,
        private PdfStorage $storage,
        private MessageBusInterface $bus,
        private PlatenConfig $config,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Renders within the request. The caller sends and deletes the result's file.
     */
    public function render(string $owner, DocumentRequest $request, string $baseUrl): RenderResult
    {
        $result = $this->renderer->render($request, $this->config->syncTimeout);
        $document = new Document($owner, DocumentKind::Sync, $request, $baseUrl, $this->clock->now());
        $document->complete($result->pages, null, $result->engine->value, $this->clock->now());
        $this->documents->save($document);

        return $result;
    }

    /**
     * Renders within the request and stores the PDF for download.
     */
    public function host(string $owner, DocumentRequest $request, string $baseUrl): Document
    {
        $result = $this->renderer->render($request, $this->config->syncTimeout);
        $document = new Document($owner, DocumentKind::Hosted, $request, $baseUrl, $this->clock->now());
        $this->store($document, $result);

        return $document;
    }

    /**
     * @param array<array-key, mixed> $payload the request as the client sent it, rendered later
     */
    public function queue(string $owner, DocumentRequest $request, array $payload, bool $hosted, string $baseUrl): Document
    {
        $document = new Document($owner, DocumentKind::of(true, $hosted), $request, $baseUrl, $this->clock->now(), $payload);
        $this->documents->save($document);
        $this->bus->dispatch(new RenderDocument($document->statusId));

        return $document;
    }

    public function renderQueued(string $statusId): void
    {
        $document = $this->documents->findByStatusId($statusId);
        if (null === $document || !$document->isPending()) {
            return; // already rendered, or deleted
        }
        $payload = $document->requestPayload();
        if (null === $payload) {
            $document->fail(['Could not resume this document'], $this->clock->now());
            $this->documents->save($document);

            return;
        }
        $document->start();
        $this->documents->save($document);

        try {
            $request = DocumentRequest::fromRequest($payload);
            $result = $this->renderWithRetries($request);
        } catch (ApiException $exception) {
            $document->fail($exception->messages, $this->clock->now());
            $this->documents->save($document);

            return;
        } catch (\Throwable $exception) {
            $this->logger->error('Document {id} failed', ['id' => $statusId, 'exception' => $exception]);
            $document->fail(['Internal error while rendering'], $this->clock->now());
            $this->documents->save($document);

            return;
        }

        $expiry = DocumentKind::HostedAsync === $document->kind
            ? null
            : $this->clock->now()->modify(\sprintf('+%d seconds', (int) ($this->config->asyncRetentionHours * 3600)));
        $this->store($document, $result, $expiry);

        if (null !== $request->callbackUrl) {
            $this->bus->dispatch(new SendCallback($request->callbackUrl, [
                'download_url' => self::downloadUrl($document->baseUrl, $document->downloadId),
                'download_id' => $document->downloadId,
                'status_id' => $document->statusId,
                'number_of_pages' => (string) $result->pages,
            ]));
        }
    }

    /**
     * Counts one download of a stored document.
     *
     * @throws ApiException when the document cannot be downloaded
     */
    public function download(string $downloadId): Document
    {
        $document = $this->documents->findByDownloadId($downloadId);
        if (null === $document || DocumentKind::Sync === $document->kind) {
            throw ApiException::badRequest('Invalid download id');
        }
        if ($document->isExpiredAt($this->clock->now())) {
            throw ApiException::badRequest('This document has expired');
        }
        if (DocumentStatus::Completed !== $document->status()) {
            throw ApiException::badRequest('This document has not finished rendering');
        }
        if (!$this->documents->countDownload($document)) {
            throw ApiException::badRequest('The download limit for this document has been reached');
        }
        if (!$this->storage->exists($downloadId)) {
            throw ApiException::badRequest('This document is no longer available');
        }

        return $document;
    }

    public function expire(string $downloadId, string $owner): bool
    {
        $document = $this->documents->findByDownloadId($downloadId);
        if (null === $document || $document->owner !== $owner) {
            return false;
        }
        $document->expire($this->clock->now());
        $this->documents->save($document);
        $this->storage->delete($downloadId);

        return true;
    }

    /**
     * Expires stored documents past their expiry and deletes old log entries.
     */
    public function cleanUp(): void
    {
        $now = $this->clock->now();
        $expired = $this->documents->findExpired($now);
        foreach ($expired as $document) {
            $document->expire($now);
            $this->storage->delete($document->downloadId);
        }
        $this->documents->flush();
        $cutoff = $now->modify(\sprintf('-%d seconds', (int) ($this->config->logRetentionDays * 86400)));
        $deleted = $this->documents->deleteLogsBefore($cutoff);
        $this->removeStaleTemporaryFiles($now);
        if ([] !== $expired || $deleted > 0) {
            $this->logger->info('Expired {expired} documents and deleted {deleted} log entries', ['expired' => \count($expired), 'deleted' => $deleted]);
        }
    }

    public static function downloadUrl(string $baseUrl, string $downloadId): string
    {
        return rtrim($baseUrl, '/').'/download/'.$downloadId;
    }

    /**
     * Tries again, within the same time limit, while the renderer is unavailable.
     */
    private function renderWithRetries(DocumentRequest $request): RenderResult
    {
        $deadline = Deadline::in($this->config->asyncTimeout);
        for ($attempt = 1;; ++$attempt) {
            try {
                return $this->renderer->render($request, $deadline->remaining());
            } catch (ApiException $exception) {
                $pause = 2 ** $attempt;
                if ($exception->status < 500 || $attempt >= self::ATTEMPTS || $deadline->remaining() < $pause + 1) {
                    throw $exception;
                }
                $this->logger->warning('Renderer unavailable, retrying: {message}', ['message' => $exception->getMessage()]);
                $this->clock->sleep($pause);
            }
        }
    }

    private function store(Document $document, RenderResult $result, ?\DateTimeImmutable $defaultExpiry = null): void
    {
        $size = $result->size();
        $this->storage->store($result->file, $document->downloadId);
        $document->complete($result->pages, $size, $result->engine->value, $this->clock->now(), $defaultExpiry);
        $this->documents->save($document);
    }

    /**
     * Renders that died mid-way (a killed worker) leave files in the temporary directory.
     */
    private function removeStaleTemporaryFiles(\DateTimeImmutable $now): void
    {
        $directory = $this->config->dataDir.'/tmp';
        foreach (@scandir($directory) ?: [] as $name) {
            $path = "{$directory}/{$name}";
            if ('.' === $name || '..' === $name || (int) @filemtime($path) > $now->getTimestamp() - 86400) {
                continue;
            }
            if (is_dir($path)) {
                array_map(unlink(...), glob("{$path}/*") ?: []);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
