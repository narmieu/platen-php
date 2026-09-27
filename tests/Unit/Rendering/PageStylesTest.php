<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Api\ApiException;
use App\Rendering\PageStyles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageStylesTest extends TestCase
{
    #[Test]
    public function aNullMarginReturnsOnlyThePrinceDefaults(): void
    {
        self::assertSame(PageStyles::PRINCE_DEFAULTS, PageStyles::page(null));
    }

    #[Test]
    #[DataProvider('validMargins')]
    public function itAppendsAValidMarginAfterTheDefaults(string $margin, string $expected): void
    {
        self::assertSame(
            PageStyles::PRINCE_DEFAULTS."\n@page { margin: {$expected}; }",
            PageStyles::page($margin),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function validMargins(): iterable
    {
        yield 'one length' => ['1in', '1in'];
        yield 'two lengths' => ['10pt 20pt', '10pt 20pt'];
        yield 'four lengths' => ['1cm 2cm 3cm 4cm', '1cm 2cm 3cm 4cm'];
        yield 'percentage' => ['5%', '5%'];
        yield 'negative length' => ['-3pt', '-3pt'];
        yield 'unitless zero' => ['0', '0'];
        yield 'surrounding whitespace is trimmed' => ['  2mm  ', '2mm'];
        yield 'mixed units and leading dot' => ['1.5rem .5em', '1.5rem .5em'];
    }

    #[Test]
    #[DataProvider('invalidMargins')]
    public function itRejectsMarginsThatAreNotOneToFourLengths(string $margin): void
    {
        try {
            PageStyles::page($margin);
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status);
            self::assertSame('prince_options.page_margin must be one to four CSS lengths', $exception->messages[0]);

            return;
        }
        self::fail('Expected an ApiException');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidMargins(): iterable
    {
        yield 'five lengths' => ['1in 2in 3in 4in 5in'];
        yield 'keyword' => ['red'];
        yield 'trailing semicolon' => ['1in;'];
        yield 'empty' => [''];
    }
}
