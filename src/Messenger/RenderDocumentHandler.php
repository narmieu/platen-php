<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Document\DocumentService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RenderDocumentHandler
{
    public function __construct(private DocumentService $documents)
    {
    }

    public function __invoke(RenderDocument $message): void
    {
        $this->documents->renderQueued($message->statusId);
    }
}
