<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Api\ApiException;
use App\Rendering\PdfProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfProfileTest extends TestCase
{
    #[Test]
    public function aBlankProfileIsNull(): void
    {
        self::assertNull(PdfProfile::fromPrince(null));
        self::assertNull(PdfProfile::fromPrince(''));
        self::assertNull(PdfProfile::fromPrince('   '));
    }

    #[Test]
    #[DataProvider('profiles')]
    public function itMapsSupportedProfiles(string $profile, string $variant, bool $tagged, bool $forbidsEncryption): void
    {
        $result = PdfProfile::fromPrince($profile);
        self::assertInstanceOf(PdfProfile::class, $result);
        self::assertSame($variant, $result->variant);
        self::assertSame($tagged, $result->tagged);
        self::assertSame($forbidsEncryption, $result->forbidsEncryption());
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function profiles(): iterable
    {
        yield 'pdf/a lowercased' => ['PDF/A-3b', 'pdf/a-3b', false, true];
        yield 'pdf/ua turns on tagging' => ['pdf/ua-1', 'pdf/ua-1', true, false];
        yield 'combined a+ua keeps a and tags' => ['PDF/A-3a+PDF/UA-1', 'pdf/a-3a', true, true];
        yield 'combined ua+a prefers the a variant' => ['PDF/UA-1+PDF/A-2b', 'pdf/a-2b', true, true];
        yield 'pdf/x forbids encryption' => ['pdf/x-4', 'pdf/x-4', false, true];
        yield 'a trailing conformance suffix is ignored' => ['PDF/A-3b:foo', 'pdf/a-3b', false, true];
    }

    #[Test]
    public function itRejectsUnsupportedProfiles(): void
    {
        try {
            PdfProfile::fromPrince('garbage');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status);
            self::assertSame('Unsupported PDF profile: garbage', $exception->messages[0]);

            return;
        }
        self::fail('Expected an ApiException');
    }
}
