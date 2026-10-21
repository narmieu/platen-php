<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Config\PlatenConfig;
use App\Document\DocumentRequest;

/**
 * Which engine renders a request, and whether WeasyPrint should imitate Prince.
 */
final readonly class EngineChoice
{
    public function __construct(
        public Engine $engine,
        /** Apply the Prince compatibility layer: a Prince pipeline number on a WeasyPrint render. */
        public bool $princeCompat,
    ) {
    }

    public static function for(DocumentRequest $request, PlatenConfig $config): self
    {
        // Only a browser runs JavaScript, so JavaScript documents always use Chromium.
        $engine = $request->runsJavaScript() ? Engine::Chromium : Engine::fromPipeline($request->pipeline, $config->defaultEngine);
        $princeCompat = $config->princeCompat
            && Engine::WeasyPrint === $engine
            && 1 === preg_match('/^\d+(\.\d+)*$/', trim((string) $request->pipeline));

        return new self($engine, $princeCompat);
    }
}
