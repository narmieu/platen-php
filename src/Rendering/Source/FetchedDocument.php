<?php

declare(strict_types=1);

namespace App\Rendering\Source;

final readonly class FetchedDocument
{
    public function __construct(
        /** The document, as UTF-8. */
        public string $html,
        /** The URL it came from, after redirects. */
        public string $url,
    ) {
    }
}
