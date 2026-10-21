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
        yield 'handler after a greater-than in a quoted attribute' => ['<a title="a>b" onclick=f()>', true];
        yield 'handler value mentioning another handler in text' => ['<p title="onclick=x">t</p>', false];
        yield 'svg script' => ['<svg><script>f()</script></svg>', true];
        yield 'template' => ['<template><b onclick=f()>x</b></template>', true];
    }

    #[Test]
    public function itIsRightOnMultiMegabyteDocuments(): void
    {
        $image = '<img src="data:image/png;base64,'.str_repeat('QUJD', 1_000_000).'">';

        $start = hrtime(true);
        $plain = ScriptDetector::needsBrowser('<p>a</p>'.$image.'<p>b</p>');
        $elapsedMs = (hrtime(true) - $start) / 1e6;
        $handled = ScriptDetector::needsBrowser($image.'<button onclick="f()">x</button>');

        self::assertFalse($plain);
        self::assertTrue($handled);
        self::assertLessThan(2000, $elapsedMs);
    }

    #[Test]
    public function itDoesNotParseDocumentsWithoutAnythingLikeAHandler(): void
    {
        $table = '<table>'.str_repeat('<tr><td>cell</td></tr>', 20_000).'</table>';

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $needsBrowser = ScriptDetector::needsBrowser($table);
        $parsing = memory_get_peak_usage() - $before;

        self::assertFalse($needsBrowser);
        self::assertLessThan(1_000_000, $parsing);
    }

    #[Test]
    public function itSendsDocumentsWithTooManyTagsToParseToTheBrowser(): void
    {
        // Something like a handler (in the text here), and more than 100,000 tags.
        $html = str_repeat('<p>x</p>', 50_000).'<p>onboarding = fun</p>';

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $needsBrowser = ScriptDetector::needsBrowser($html);
        $parsing = memory_get_peak_usage() - $before;

        self::assertTrue($needsBrowser);
        self::assertLessThan(1_000_000, $parsing);
    }
}
