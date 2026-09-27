<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Api\ApiException;
use App\Document\PrinceOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrinceOptionsTest extends TestCase
{
    #[Test]
    public function emptyInputYieldsDefaults(): void
    {
        foreach ([null, ''] as $empty) {
            $options = PrinceOptions::fromRequest($empty);
            self::assertSame(128, $options->keyBits);
            self::assertSame('print', $options->media);
            self::assertFalse($options->encrypt);
            self::assertFalse($options->noNetwork);
            self::assertNull($options->baseUrl);
        }
    }

    /**
     * @param string|int|array<int, int> $raw
     */
    #[Test]
    #[DataProvider('nonObjects')]
    public function itRequiresAnObject(string|int|array $raw): void
    {
        $exception = self::catch(static fn (): mixed => PrinceOptions::fromRequest($raw));
        self::assertSame(400, $exception->status);
        self::assertSame('prince_options must be an object', $exception->messages[0]);
    }

    /**
     * @return iterable<string, array{0: string|int|array<int, int>}>
     */
    public static function nonObjects(): iterable
    {
        yield 'scalar string' => ['nope'];
        yield 'scalar int' => [5];
        yield 'list array' => [[1, 2, 3]];
    }

    #[Test]
    public function anEmptyArrayIsAnObject(): void
    {
        // array_is_list([]) is true, but an empty options object is valid.
        $options = PrinceOptions::fromRequest([]);
        self::assertSame(128, $options->keyBits);
    }

    #[Test]
    #[DataProvider('keyBits')]
    public function itValidatesKeyBits(mixed $value, int $expected): void
    {
        self::assertSame($expected, PrinceOptions::fromRequest(['key_bits' => $value])->keyBits);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function keyBits(): iterable
    {
        yield '40' => [40, 40];
        yield '128' => [128, 128];
        yield 'numeric string 40' => ['40', 40];
    }

    #[Test]
    public function itRejectsOtherKeyBits(): void
    {
        $exception = self::catch(static fn (): mixed => PrinceOptions::fromRequest(['key_bits' => 256]));
        self::assertSame(400, $exception->status);
        self::assertSame('prince_options.key_bits must be 40 or 128', $exception->messages[0]);
    }

    #[Test]
    #[DataProvider('media')]
    public function itNormalisesMedia(mixed $value, string $expected): void
    {
        self::assertSame($expected, PrinceOptions::fromRequest(['media' => $value])->media);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function media(): iterable
    {
        yield 'screen lowercased' => ['SCREEN', 'screen'];
        yield 'print' => ['print', 'print'];
        yield 'unknown falls back to print' => ['handheld', 'print'];
        yield 'blank falls back to print' => ['', 'print'];
    }

    #[Test]
    public function blankTextOptionsBecomeNullExceptPasswords(): void
    {
        $options = PrinceOptions::fromRequest([
            'baseurl' => '  ',
            'http_user' => ' ',
            'http_proxy' => '',
            'profile' => '   ',
            'page_margin' => ' ',
            'owner_password' => '  ',
            // Passwords keep their (possibly empty) value.
            'http_password' => '',
            'user_password' => '',
        ]);
        self::assertNull($options->baseUrl);
        self::assertNull($options->httpUser);
        self::assertNull($options->httpProxy);
        self::assertNull($options->profile);
        self::assertNull($options->pageMargin);
        // owner_password uses the "text" helper: blank becomes null.
        self::assertNull($options->ownerPassword);
        // http_password and user_password keep the empty string.
        self::assertSame('', $options->httpPassword);
        self::assertSame('', $options->userPassword);
    }

    #[Test]
    public function itReadsTypedFlagsAndNumbers(): void
    {
        $options = PrinceOptions::fromRequest([
            'no_network' => 'yes',
            'encrypt' => true,
            'disallow_print' => 'on',
            'http_timeout' => '30',
            'baseurl' => 'https://assets.example/',
            'pdf_title' => 'Title',
        ]);
        self::assertTrue($options->noNetwork);
        self::assertTrue($options->encrypt);
        self::assertTrue($options->disallowPrint);
        self::assertSame(30, $options->httpTimeout);
        self::assertSame('https://assets.example/', $options->baseUrl);
        self::assertSame('Title', $options->pdfTitle);
    }

    #[Test]
    public function itReportsTheOffendingOptionName(): void
    {
        $exception = self::catch(static fn (): mixed => PrinceOptions::fromRequest(['insecure' => 'notbool']));
        self::assertSame(400, $exception->status);
        self::assertSame('prince_options.insecure must be true or false', $exception->messages[0]);
    }

    #[Test]
    public function pdfTitleKeepsAnEmptyString(): void
    {
        // pdf_title uses the plain string helper, so '' is preserved (not nulled).
        self::assertSame('', PrinceOptions::fromRequest(['pdf_title' => ''])->pdfTitle);
    }

    private static function catch(callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $exception) {
            return $exception;
        }
        self::fail('Expected an ApiException');
    }
}
