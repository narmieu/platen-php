<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\ApiException;
use App\Api\PayloadReader;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PayloadReaderTest extends TestCase
{
    #[Test]
    public function itReadsAFlatJsonBody(): void
    {
        [$doc, $credentials] = self::reader()->read(self::json('{"document_content":"x","user_credentials":"KEY"}'));
        self::assertSame(['document_content' => 'x', 'user_credentials' => 'KEY'], $doc);
        self::assertSame('KEY', $credentials);
    }

    #[Test]
    public function itUnwrapsTheLegacyDocWrapper(): void
    {
        [$doc, $credentials] = self::reader()->read(self::json('{"doc":{"document_content":"y"},"user_credentials":"K2"}'));
        self::assertSame(['document_content' => 'y'], $doc);
        self::assertSame('K2', $credentials);
    }

    #[Test]
    public function credentialsInsideTheWrapperAreUsedWhenNoneAreOutside(): void
    {
        [$doc, $credentials] = self::reader()->read(self::json('{"doc":{"document_content":"y","user_credentials":"inner"}}'));
        self::assertSame('inner', $credentials);
        self::assertArrayHasKey('user_credentials', $doc);
    }

    #[Test]
    public function itReadsFormEncodedParameters(): void
    {
        $request = new Request(
            [],
            ['document_content' => 'f', 'user_credentials' => 'FK'],
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'REQUEST_METHOD' => 'POST'],
        );
        $request->setMethod('POST');

        [$doc, $credentials] = self::reader()->read($request);
        self::assertSame(['document_content' => 'f', 'user_credentials' => 'FK'], $doc);
        self::assertSame('FK', $credentials);
    }

    #[Test]
    public function noCredentialsMeansNull(): void
    {
        [, $credentials] = self::reader()->read(self::json('{"document_content":"x"}'));
        self::assertNull($credentials);
    }

    #[Test]
    public function anEmptyBodyIsABadRequest(): void
    {
        $exception = self::catch(self::json(''));
        self::assertSame(400, $exception->status);
        self::assertSame('The request body is empty', $exception->messages[0]);
    }

    #[Test]
    public function invalidJsonIsABadRequest(): void
    {
        $exception = self::catch(self::json('{not valid'));
        self::assertSame(400, $exception->status);
        self::assertSame('The request body must be JSON or form-encoded parameters', $exception->messages[0]);
    }

    #[Test]
    public function aJsonArrayIsRejectedAsNotAnObject(): void
    {
        $exception = self::catch(self::json('[1,2,3]'));
        self::assertSame(400, $exception->status);
        self::assertSame('The request body must be a JSON object', $exception->messages[0]);
    }

    #[Test]
    public function aJsonScalarIsRejectedAsNotAnObject(): void
    {
        $exception = self::catch(self::json('"just a string"'));
        self::assertSame(400, $exception->status);
        self::assertSame('The request body must be a JSON object', $exception->messages[0]);
    }

    #[Test]
    public function aDeclaredContentLengthOverTheLimitIsRejectedBeforeReading(): void
    {
        $reader = new PayloadReader(TestConfig::create(maxRequestMb: 1));
        $exception = self::catch(self::json('{"a":1}', ['CONTENT_LENGTH' => (string) (2 * 1024 * 1024)]), $reader);
        self::assertSame(413, $exception->status);
        self::assertSame('The request body is too large', $exception->messages[0]);
    }

    #[Test]
    public function aBodyLargerThanTheLimitIsRejected(): void
    {
        $reader = new PayloadReader(TestConfig::create(maxRequestMb: 1));
        $big = '{"x":"'.str_repeat('a', 1024 * 1024 + 10).'"}';
        $exception = self::catch(self::json($big), $reader);
        self::assertSame(413, $exception->status);
        self::assertSame('The request body is too large', $exception->messages[0]);
    }

    private static function reader(): PayloadReader
    {
        return new PayloadReader(TestConfig::create(maxRequestMb: 100));
    }

    /**
     * @param array<string, string> $server
     */
    private static function json(string $body, array $server = []): Request
    {
        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json', ...$server], $body);
        $request->setMethod('POST');

        return $request;
    }

    private static function catch(Request $request, ?PayloadReader $reader = null): ApiException
    {
        try {
            ($reader ?? self::reader())->read($request);
        } catch (ApiException $exception) {
            return $exception;
        }
        self::fail('Expected an ApiException');
    }
}
