<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Config\PlatenConfig;
use App\Document\DocumentRequest;
use App\Rendering\Source\ScriptDetector;

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
        $hasPipelineNumber = 1 === preg_match('/^\d+(\.\d+)*$/', trim((string) $request->pipeline));
        // Only a browser runs JavaScript. A DocRaptor client always sends javascript: true, so under a
        // pipeline number a document without scripts or handlers keeps WeasyPrint (and the Prince layer).
        $scriptFree = $hasPipelineNumber
            && $config->princeCompat
            && null !== $request->documentContent
            && !ScriptDetector::needsBrowser($request->documentContent);
        $engine = $request->runsJavaScript() && !$scriptFree
            ? Engine::Chromium
            : Engine::fromPipeline($request->pipeline, $config->defaultEngine);
        $princeCompat = $config->princeCompat && Engine::WeasyPrint === $engine && $hasPipelineNumber;

        return new self($engine, $princeCompat);
    }
}
