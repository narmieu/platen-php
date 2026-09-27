<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\XmlErrorResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class XmlErrorResponseTest extends TestCase
{
    #[Test]
    public function itSetsTheStatusAndXmlContentType(): void
    {
        $response = new XmlErrorResponse(422, ['Something went wrong']);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function itWrapsEachMessageInAnErrorElement(): void
    {
        $response = new XmlErrorResponse(400, ['first', 'second']);
        self::assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<errors>\n  <error>first</error>\n  <error>second</error>\n</errors>\n",
            $response->getContent(),
        );
    }

    #[Test]
    public function itEscapesXmlSpecialCharacters(): void
    {
        $response = new XmlErrorResponse(400, ['it\'s a <trap> & "more"']);
        $body = (string) $response->getContent();
        self::assertStringContainsString('<error>it&apos;s a &lt;trap&gt; &amp; &quot;more&quot;</error>', $body);
        // The angle brackets from the message must not appear unescaped.
        self::assertStringNotContainsString('<trap>', $body);
    }

    #[Test]
    public function invalidUtf8IsSubstitutedRatherThanDropped(): void
    {
        // ENT_SUBSTITUTE replaces the bad byte with U+FFFD instead of returning an empty string.
        $body = (string) new XmlErrorResponse(400, ["bad \xff byte"])->getContent();
        self::assertStringContainsString('<error>', $body);
        self::assertStringContainsString('byte', $body);
    }

    #[Test]
    public function noMessagesProduceAnEmptyErrorsElement(): void
    {
        $response = new XmlErrorResponse(500, []);
        self::assertSame(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<errors>\n</errors>\n",
            $response->getContent(),
        );
    }
}
