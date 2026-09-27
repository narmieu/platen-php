<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Rendering\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    #[Test]
    #[DataProvider('pipelines')]
    public function itPicksAnEngineFromThePipeline(?string $pipeline, Engine $expected): void
    {
        // The default matters only when the pipeline does not name an engine.
        self::assertSame($expected, Engine::fromPipeline($pipeline, Engine::WeasyPrint));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: Engine}>
     */
    public static function pipelines(): iterable
    {
        yield 'null falls back to default' => [null, Engine::WeasyPrint];
        yield 'empty falls back to default' => ['', Engine::WeasyPrint];
        yield 'weasyprint' => ['weasyprint', Engine::WeasyPrint];
        yield 'weasy alias, uppercase' => ['WEASY', Engine::WeasyPrint];
        yield 'chromium' => ['chromium', Engine::Chromium];
        yield 'chrome alias' => ['chrome', Engine::Chromium];
        yield 'browser alias' => ['browser', Engine::Chromium];
        yield 'aliases are trimmed' => ['  Chromium  ', Engine::Chromium];
        yield 'numeric prince pipeline falls back' => ['10.1', Engine::WeasyPrint];
        yield 'unknown falls back' => ['prince', Engine::WeasyPrint];
    }

    #[Test]
    public function theDefaultIsReturnedForUnknownPipelines(): void
    {
        self::assertSame(Engine::Chromium, Engine::fromPipeline('10.1', Engine::Chromium));
    }
}
