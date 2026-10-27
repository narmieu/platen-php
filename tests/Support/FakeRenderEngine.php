<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Rendering\Engine;
use App\Rendering\EngineOutput;
use App\Rendering\RenderEngine;
use App\Rendering\RenderJob;
use App\Rendering\Workspace;

/**
 * A RenderEngine that records whether it was asked to render, and renders nothing.
 */
final class FakeRenderEngine implements RenderEngine
{
    public bool $rendered = false;

    public function __construct(private readonly Engine $engine, private readonly bool $profiles)
    {
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    public function userAgent(?string $token): string
    {
        return 'test';
    }

    public function supportsPdfProfiles(): bool
    {
        return $this->profiles;
    }

    public function render(RenderJob $job, Workspace $workspace): EngineOutput
    {
        $this->rendered = true;

        throw new \LogicException('Not rendered in this test');
    }
}
