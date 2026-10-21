<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Document\DocumentRequest;
use App\Rendering\Engine;
use App\Rendering\EngineChoice;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EngineChoiceTest extends TestCase
{
    #[Test]
    #[DataProvider('choices')]
    public function itChoosesTheEngineAndPrinceCompatibility(?string $pipeline, bool $javascript, bool $flag, Engine $engine, bool $compat, string $content = '<p>x</p>', bool $byUrl = false): void
    {
        $request = DocumentRequest::fromRequest([
            ...($byUrl ? ['document_url' => 'https://example.com/a.html'] : ['document_content' => $content]),
            'pipeline' => $pipeline,
            'javascript' => $javascript,
        ]);

        $choice = EngineChoice::for($request, TestConfig::create(princeCompat: $flag));

        self::assertSame($engine, $choice->engine);
        self::assertSame($compat, $choice->princeCompat);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: bool, 2: bool, 3: Engine, 4: bool, 5?: string, 6?: bool}>
     */
    public static function choices(): iterable
    {
        yield 'whole version' => ['10', false, true, Engine::WeasyPrint, true];
        yield 'dotted version' => ['10.1', false, true, Engine::WeasyPrint, true];
        yield 'padded version' => [' 9 ', false, true, Engine::WeasyPrint, true];
        yield 'three parts' => ['1.2.3', false, true, Engine::WeasyPrint, true];
        yield 'no pipeline' => [null, false, true, Engine::WeasyPrint, false];
        yield 'empty pipeline' => ['', false, true, Engine::WeasyPrint, false];
        yield 'weasyprint by name' => ['weasyprint', false, true, Engine::WeasyPrint, false];
        yield 'chromium by name' => ['chromium', false, true, Engine::Chromium, false];
        yield 'version with suffix' => ['10a', false, true, Engine::WeasyPrint, false];
        yield 'flag off' => ['10', false, false, Engine::WeasyPrint, false];
        yield 'javascript without scripts under a number' => ['10', true, true, Engine::WeasyPrint, true];
        yield 'javascript with a script' => ['10', true, true, Engine::Chromium, false, '<script>x()</script>'];
        yield 'javascript without pipeline' => [null, true, true, Engine::Chromium, false];
        yield 'javascript with weasyprint by name' => ['weasyprint', true, true, Engine::Chromium, false];
        yield 'javascript with flag off' => ['10', true, false, Engine::Chromium, false];
        yield 'javascript from document_url' => ['10', true, true, Engine::Chromium, false, '', true];
    }
}
