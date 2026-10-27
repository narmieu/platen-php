<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Api\ApiException;
use App\Document\DocumentRequest;
use App\Rendering\Engine;
use App\Rendering\EngineOutput;
use App\Rendering\Pdf\Qpdf;
use App\Rendering\RenderEngine;
use App\Rendering\Renderer;
use App\Rendering\RenderJob;
use App\Rendering\Source\DocumentFetcher;
use App\Rendering\StrictHtmlValidator;
use App\Rendering\Workspace;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class RendererTest extends TestCase
{
    #[Test]
    public function anEngineWithoutPdfProfilesRefusesAProfile(): void
    {
        $engine = self::engine(Engine::Chromium, profiles: false);
        $renderer = self::renderer($engine);

        try {
            $renderer->render(self::request(['pipeline' => 'chromium', 'prince_options' => ['profile' => 'PDF/A-3b']]), 10.0);
            self::fail('The profile was accepted');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status);
            self::assertStringContainsString('need the weasyprint pipeline', $exception->getMessage());
        }
        self::assertFalse($engine->rendered);
    }

    #[Test]
    public function anEngineThatIsNotRegisteredIsAnError(): void
    {
        $renderer = self::renderer(self::engine(Engine::WeasyPrint, profiles: true));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No RenderEngine for chromium');

        $renderer->render(self::request(['pipeline' => 'chromium']), 10.0);
    }

    private static function renderer(RenderEngine ...$engines): Renderer
    {
        $config = TestConfig::create();

        return new Renderer($config, new DocumentFetcher($config, new MockHttpClient()), new Qpdf($config), new StrictHtmlValidator(), $engines);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function request(array $fields): DocumentRequest
    {
        return DocumentRequest::fromRequest(['document_content' => '<p>Hi</p>', ...$fields]);
    }

    private static function engine(Engine $engine, bool $profiles): RenderEngine
    {
        return new class($engine, $profiles) implements RenderEngine {
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
        };
    }
}
