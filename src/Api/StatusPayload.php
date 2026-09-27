<?php

declare(strict_types=1);

namespace App\Api;

use App\Document\DocumentService;
use App\Entity\Document;
use App\Entity\DocumentStatus;

/**
 * The DocStatus object of DocRaptor's status API.
 */
final class StatusPayload
{
    /**
     * @param string $baseUrl where the client reached this server
     *
     * @return array<string, int|string|null>
     */
    public static function of(Document $document, string $baseUrl): array
    {
        return match ($document->status()) {
            DocumentStatus::Completed => [
                'status' => 'completed',
                'download_url' => DocumentService::downloadUrl($baseUrl, $document->downloadId),
                'download_id' => $document->downloadId,
                'number_of_pages' => $document->numberOfPages(),
                'message' => 'Completed at '.$document->completedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('D M d H:i:s +0000 Y'),
            ],
            DocumentStatus::Failed => [
                'status' => 'failed',
                'message' => $document->message(),
                'validation_errors' => $document->validationErrors(),
            ],
            // Not a DocRaptor status; reported as failed so that polling loops end.
            DocumentStatus::Expired => [
                'status' => 'failed',
                'message' => 'This document has expired',
                'validation_errors' => '',
            ],
            DocumentStatus::Queued, DocumentStatus::Working => ['status' => $document->status()->value],
        };
    }
}
