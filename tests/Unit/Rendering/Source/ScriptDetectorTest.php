<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Source;

use App\Rendering\Source\ScriptDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScriptDetectorTest extends TestCase
{
    #[Test]
    #[DataProvider('documents')]
    public function itDetectsScriptsAndEventHandlers(string $html, bool $needsBrowser): void
    {
        self::assertSame($needsBrowser, ScriptDetector::needsBrowser($html));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function documents(): iterable
    {
        yield 'plain markup' => ['<p>x</p>', false];
        yield 'script element' => ['<script>x()</script>', true];
        yield 'uppercase external script' => ['<SCRIPT src=a.js>', true];
        yield 'body onload' => ['<body onload="f()">', true];
        yield 'text containing on' => ['<p>onboarding = fun</p>', false];
        yield 'unquoted onerror' => ['<img src=x onerror=alert(1)>', true];
    }
}
