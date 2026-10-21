<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\WeasyPrint;

use App\Document\PrinceOptions;
use App\Rendering\Deadline;
use App\Rendering\PageStyles;
use App\Rendering\RenderJob;
use App\Rendering\WeasyPrint\WeasyPrintEngine;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeasyPrintEngineTest extends TestCase
{
    #[Test]
    #[DataProvider('binaries')]
    public function itAsksForThePrinceLayerThroughTheEnvironmentOnly(string $binary): void
    {
        $engine = new WeasyPrintEngine(TestConfig::create(weasyprintBinary: $binary));

        $arguments = $engine->arguments(self::job(true));

        // Stock WeasyPrint rejects arguments it does not know, and ignores the variable.
        self::assertSame($binary, $arguments[0]);
        self::assertSame($engine->arguments(self::job(false)), $arguments);
        self::assertSame([], array_values(array_filter($arguments, static fn (string $argument): bool => str_starts_with($argument, '--platen'))));
        self::assertSame('1', $engine->environment(self::job(true))['PLATEN_WEASYPRINT_PRINCE']);
        // Unset, so that a value in platen's own environment does not reach the renderer.
        self::assertFalse($engine->environment(self::job(false))['PLATEN_WEASYPRINT_PRINCE']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function binaries(): iterable
    {
        yield 'weasyprint-platen' => ['weasyprint-platen'];
        yield 'stock weasyprint' => ['weasyprint'];
    }

    private static function job(bool $princeCompat): RenderJob
    {
        return new RenderJob(
            html: '<p>x</p>',
            baseUrl: null,
            pageCss: PageStyles::page(null),
            watermark: false,
            javascript: false,
            options: new PrinceOptions(),
            profile: null,
            ignoreResourceErrors: true,
            ignoreConsoleMessages: false,
            userAgentToken: null,
            referrer: null,
            credentials: null,
            deadline: Deadline::in(60.0),
            princeCompat: $princeCompat,
        );
    }
}
