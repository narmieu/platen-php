<?php

declare(strict_types=1);

namespace App\Messenger;

/**
 * Tell a client that its asynchronous document is ready (DocRaptor's callback_url).
 */
final readonly class SendCallback
{
    /**
     * @param array<string, string> $fields posted form-encoded
     */
    public function __construct(
        public string $url,
        public array $fields,
    ) {
    }
}
