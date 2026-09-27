<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Api\ApiException;
use App\Document\DocumentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentRequestTest extends TestCase
{
    #[Test]
    public function itAppliesDocraptorDefaults(): void
    {
        $request = DocumentRequest::fromRequest(['document_content' => '<p>x</p>']);

        self::assertSame('document', $request->name);
        self::assertSame('pdf', $request->documentType);
        self::assertSame('<p>x</p>', $request->documentContent);
        self::assertNull($request->documentUrl);
        self::assertTrue($request->test); // test defaults to true, so documents are watermarked
        self::assertFalse($request->strictHtml);
        self::assertTrue($request->ignoreResourceErrors);
        self::assertFalse($request->ignoreConsoleMessages);
        self::assertFalse($request->javascript);
        self::assertFalse($request->async);
        self::assertFalse($request->hosted);
        self::assertFalse($request->runsJavaScript());
    }

    #[Test]
    public function itAcceptsTheLegacyTypeAliasAndLowercasesIt(): void
    {
        self::assertSame('xlsx', DocumentRequest::fromRequest(['type' => 'xlsx', 'document_content' => 'x'])->documentType);
        self::assertSame('pdf', DocumentRequest::fromRequest(['document_type' => 'PDF', 'document_content' => 'x'])->documentType);
    }

    #[Test]
    public function itRejectsUnknownDocumentTypes(): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_type' => 'docx', 'document_content' => 'x']));
        self::assertSame('document_type must be one of pdf, xls, xlsx', $exception->messages[0]);
    }

    #[Test]
    public function itRequiresContentOrAUrl(): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest([]));
        self::assertSame(400, $exception->status);
        self::assertSame('You must supply document_content or document_url', $exception->messages[0]);
    }

    #[Test]
    public function itValidatesTheDocumentUrlWhenThereIsNoContent(): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_url' => 'ftp://example.com']));
        self::assertSame('document_url must be an absolute http or https URL', $exception->messages[0]);
    }

    #[Test]
    public function contentTakesPrecedenceOverAUrl(): void
    {
        $request = DocumentRequest::fromRequest(['document_content' => '<p>x</p>', 'document_url' => 'https://example.com']);
        self::assertSame('<p>x</p>', $request->documentContent);
        self::assertNull($request->documentUrl); // the URL is dropped when content is present
    }

    #[Test]
    public function aBlankUrlIsTreatedAsAbsent(): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_url' => '   ']));
        self::assertSame('You must supply document_content or document_url', $exception->messages[0]);
    }

    #[Test]
    public function itValidatesTheStrictFlag(): void
    {
        self::assertTrue(DocumentRequest::fromRequest(['document_content' => 'x', 'strict' => 'HTML'])->strictHtml);
        self::assertFalse(DocumentRequest::fromRequest(['document_content' => 'x', 'strict' => 'none'])->strictHtml);

        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_content' => 'x', 'strict' => 'xml']));
        self::assertSame('strict must be none or html', $exception->messages[0]);
    }

    #[Test]
    public function itValidatesTheCallbackUrl(): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_content' => 'x', 'callback_url' => 'not a url']));
        self::assertSame('callback_url must be an absolute http or https URL', $exception->messages[0]);

        self::assertNull(DocumentRequest::fromRequest(['document_content' => 'x', 'callback_url' => ''])->callbackUrl);
    }

    #[Test]
    #[DataProvider('headerFieldsWithControlChars')]
    public function itRejectsControlCharactersInHeaderFields(string $field, string $value): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_content' => 'x', $field => $value]));
        self::assertSame("{$field} must not contain control characters", $exception->messages[0]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function headerFieldsWithControlChars(): iterable
    {
        yield 'referrer newline' => ['referrer', "https://x\ninjected"];
        yield 'user_agent_token tab' => ['user_agent_token', "token\ttab"];
        yield 'referrer DEL' => ['referrer', "\x7f"];
    }

    #[Test]
    public function itRequiresAPositiveDownloadLimit(): void
    {
        $exception = self::catch(static fn (): mixed => DocumentRequest::fromRequest(['document_content' => 'x', 'hosted_download_limit' => 0]));
        self::assertSame('hosted_download_limit must be at least 1', $exception->messages[0]);

        self::assertSame(5, DocumentRequest::fromRequest(['document_content' => 'x', 'hosted_download_limit' => '5'])->hostedDownloadLimit);
    }

    #[Test]
    public function itTrimsTheNameAndFallsBackToDocument(): void
    {
        self::assertSame('document', DocumentRequest::fromRequest(['document_content' => 'x', 'name' => '   '])->name);
        self::assertSame('My Doc', DocumentRequest::fromRequest(['document_content' => 'x', 'name' => '  My Doc  '])->name);
    }

    #[Test]
    public function javascriptRunsWhenEitherFlagIsSet(): void
    {
        $viaFlag = DocumentRequest::fromRequest(['document_content' => 'x', 'javascript' => true]);
        self::assertTrue($viaFlag->runsJavaScript());

        // prince_options.javascript also turns rendering into a JavaScript render.
        $viaPrince = DocumentRequest::fromRequest(['document_content' => 'x', 'prince_options' => ['javascript' => true]]);
        self::assertFalse($viaPrince->javascript);
        self::assertTrue($viaPrince->princeOptions->javascript);
        self::assertTrue($viaPrince->runsJavaScript());
    }

    #[Test]
    public function itParsesTheHostedExpiryAsUtc(): void
    {
        $request = DocumentRequest::fromRequest(['document_content' => 'x', 'hosted_expires_at' => '2030-01-01T00:00:00Z']);
        self::assertInstanceOf(\DateTimeImmutable::class, $request->hostedExpiresAt);
        self::assertSame('2030-01-01T00:00:00+00:00', $request->hostedExpiresAt->format('c'));
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
