<?php

declare(strict_types=1);

namespace App\Rendering;

enum Engine: string
{
    case WeasyPrint = 'weasyprint';
    case Chromium = 'chromium';

    /**
     * DocRaptor's `pipeline` picks the engine. Its numeric pipelines (e.g. "10.1")
     * mean Prince versions, so they fall back to the default engine.
     */
    public static function fromPipeline(?string $pipeline, self $default): self
    {
        return match (strtolower(trim((string) $pipeline))) {
            'weasyprint', 'weasy' => self::WeasyPrint,
            'chromium', 'chrome', 'browser' => self::Chromium,
            default => $default,
        };
    }
}
