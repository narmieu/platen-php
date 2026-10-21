<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Document\PrinceOptions;

/**
 * Everything an engine needs to turn one document into a PDF.
 */
final readonly class RenderJob
{
    public function __construct(
        /** The document, as UTF-8. */
        public string $html,
        /** Where relative URLs resolve; null when they cannot. */
        public ?string $baseUrl,
        /** Prince's page defaults and the page_margin option. */
        public string $pageCss,
        public bool $watermark,
        public bool $javascript,
        public PrinceOptions $options,
        public ?PdfProfile $profile,
        public bool $ignoreResourceErrors,
        public bool $ignoreConsoleMessages,
        public ?string $userAgentToken,
        public ?string $referrer,
        public ?Credentials $credentials,
        public Deadline $deadline,
        /** Run WeasyPrint with the Prince compatibility layer. */
        public bool $princeCompat = false,
    ) {
    }

    public function allowsNetwork(): bool
    {
        return !$this->options->noNetwork;
    }

    public function media(): string
    {
        return $this->options->media;
    }

    /**
     * The origin (scheme://host:port) of the base URL, when it is an http(s) URL.
     */
    public function baseOrigin(): ?string
    {
        return null === $this->baseUrl ? null : Origin::of($this->baseUrl);
    }
}
