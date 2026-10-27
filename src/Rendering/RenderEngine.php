<?php

declare(strict_types=1);

namespace App\Rendering;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Turns a RenderJob into a PDF. Each implementation is one Engine; the Renderer finds them
 * all through this interface, so a new engine is a new class.
 */
#[AutoconfigureTag]
interface RenderEngine
{
    public function engine(): Engine;

    /**
     * The User-Agent platen sends when it downloads document_url for this engine.
     */
    public function userAgent(?string $token): string;

    /**
     * Whether the engine writes PDF/A, PDF/UA and PDF/X (prince_options.profile).
     */
    public function supportsPdfProfiles(): bool;

    /**
     * @throws RenderException when the document cannot be rendered
     */
    public function render(RenderJob $job, Workspace $workspace): EngineOutput;
}
