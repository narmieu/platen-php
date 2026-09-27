<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Api\ApiException;
use App\Rendering\StrictHtmlValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StrictHtmlValidatorTest extends TestCase
{
    #[Test]
    public function validHtmlPasses(): void
    {
        new StrictHtmlValidator()->validate('<!DOCTYPE html><html><head><title>x</title></head><body><p>Hi</p></body></html>');
        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    public function itReportsParseErrorsWithLineAndColumn(): void
    {
        $exception = self::validate("<!DOCTYPE html>\n<html><body>\n<p>Hi</div>\n<span></p></body></html>");
        self::assertSame(422, $exception->status);
        self::assertSame('line 3, column 8: unexpected-closed-token', $exception->messages[0]);
        self::assertSame('line 4, column 9: unexpected-element-in-open-elements-stack', $exception->messages[1]);
    }

    #[Test]
    public function aMissingDoctypeIsAParseError(): void
    {
        $exception = self::validate('<html><body><p>Hi</p></body></html>');
        self::assertSame(422, $exception->status);
        self::assertSame('line 1, column 2: unexpected-token-in-initial-mode', $exception->messages[0]);
    }

    #[Test]
    public function itCapsTheNumberOfReportedErrorsAtTwenty(): void
    {
        $exception = self::validate('<!DOCTYPE html>'.str_repeat('</div>', 30));
        self::assertCount(20, $exception->messages);
    }

    private static function validate(string $html): ApiException
    {
        try {
            new StrictHtmlValidator()->validate($html);
        } catch (ApiException $exception) {
            return $exception;
        }
        self::fail('Expected an ApiException');
    }
}
