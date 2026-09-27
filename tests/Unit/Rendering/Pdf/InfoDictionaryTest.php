<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Pdf;

use App\Rendering\Pdf\InfoDictionary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InfoDictionaryTest extends TestCase
{
    #[Test]
    public function itDecodesAUnicodeTitle(): void
    {
        $info = new InfoDictionary('5 0 R', ['/Title' => 'u:Hello', '/Author' => 'u:Me'], 12);
        self::assertSame('Hello', $info->title());
    }

    #[Test]
    public function itDecodesAByteStringTitleFromLatin1(): void
    {
        // qpdf writes non-ASCII PDFDocEncoding strings as "b:" hex, decoded as ISO-8859-1.
        $info = new InfoDictionary('5 0 R', ['/Title' => 'b:'.bin2hex("Caf\xe9")], 12);
        self::assertSame('Café', $info->title());
    }

    #[Test]
    public function aMalformedByteStringTitleIsIgnored(): void
    {
        self::assertNull(new InfoDictionary('5 0 R', ['/Title' => 'b:abc'], 12)->title());
        self::assertNull(new InfoDictionary('5 0 R', ['/Title' => 'b:zz'], 12)->title());
    }

    #[Test]
    public function anEmptyUnicodeTitleDecodesToAnEmptyString(): void
    {
        $info = new InfoDictionary('5 0 R', ['/Title' => 'u:'], 12);
        self::assertSame('', $info->title());
    }

    #[Test]
    public function itReturnsNullForAMissingOrNonStringTitle(): void
    {
        self::assertNull(new InfoDictionary('5 0 R', [], 12)->title());
        self::assertNull(new InfoDictionary('5 0 R', ['/Author' => 'u:Me'], 12)->title());
        self::assertNull(new InfoDictionary('5 0 R', ['/Title' => 42], 12)->title());
        // A string without the u:/b: prefix is not recognised.
        self::assertNull(new InfoDictionary('5 0 R', ['/Title' => 'plain'], 12)->title());
    }

    #[Test]
    public function withTitleReplacesTheTitleAsAUnicodeString(): void
    {
        $info = new InfoDictionary('5 0 R', ['/Title' => 'u:Old', '/Author' => 'u:Me'], 12);
        $updated = $info->withTitle('New');

        self::assertNotSame($info, $updated); // immutable
        self::assertSame('New', $updated->title());
        self::assertSame('u:New', $updated->values['/Title']);
        self::assertSame('u:Me', $updated->values['/Author']); // other values are kept
        self::assertSame('Old', $info->title()); // the original is unchanged
    }

    #[Test]
    public function withTitleRemovesTheTitleWhenNullOrEmpty(): void
    {
        $info = new InfoDictionary('5 0 R', ['/Title' => 'u:Old', '/Author' => 'u:Me'], 12);

        $cleared = $info->withTitle(null);
        self::assertArrayNotHasKey('/Title', $cleared->values);
        self::assertNull($cleared->title());

        $emptied = $info->withTitle('');
        self::assertArrayNotHasKey('/Title', $emptied->values);
    }

    #[Test]
    public function toUpdateJsonHasTheQpdfShape(): void
    {
        $info = new InfoDictionary('5 0 R', ['/Author' => 'u:Me'], 12);
        $json = $info->withTitle('New')->toUpdateJson();

        self::assertJsonStringEqualsJsonString(
            '{"qpdf":[{"jsonversion":2,"pushedinheritedpageresources":false,"calledgetallpages":false,"maxobjectid":12},{"obj:5 0 R":{"value":{"/Author":"u:Me","/Title":"u:New"}}}]}',
            $json,
        );
        // Slashes are not escaped, so object keys like "/Title" stay readable.
        self::assertStringContainsString('"/Title":"u:New"', $json);
        self::assertStringNotContainsString('\\/', $json);
    }
}
