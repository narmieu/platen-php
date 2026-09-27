<?php

declare(strict_types=1);

namespace App\Messenger;

/**
 * Render a queued (asynchronous) document.
 */
final readonly class RenderDocument
{
    public function __construct(public string $statusId)
    {
    }
}
