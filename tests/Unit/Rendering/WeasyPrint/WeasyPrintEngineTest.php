<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\WeasyPrint;

use App\Document\PrinceOptions;
use App\Rendering\Deadline;
use App\Rendering\PageStyles;
use App\Rendering\RenderJob;
use App\Rendering\WeasyPrint\WeasyPrintEngine;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeasyPrintEngineTest extends TestCase
{
    #[Test]
    public function itPassesThePrinceFlagOnlyForPrinceCompatibleJobs(): void
    {
        $engine = new WeasyPrintEngine(TestConfig::create(weasyprintBinary: 'weasyprint-platen'));

        $without = $engine->arguments(self::job(false));
        $with = $engine->arguments(self::job(true));

        self::assertSame('weasyprint-platen', $without[0]);
        self::assertSame('weasyprint-platen', $with[0]);
        self::assertNotContains('--platen-prince', $without);
        self::assertContains('--platen-prince', $with);
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
