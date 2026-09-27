<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Api\ApiException;
use App\Document\Coerce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoerceTest extends TestCase
{
    /**
     * @param bool|int|float|string|array<int, int>|null $value
     */
    #[Test]
    #[DataProvider('booleans')]
    public function itCoercesBooleans(bool|int|float|string|array|null $value, ?bool $expected): void
    {
        self::assertSame($expected, Coerce::bool($value, 'flag'));
    }

    /**
     * @return iterable<string, array{0: bool|int|float|string|array<int, int>|null, 1: bool|null}>
     */
    public static function booleans(): iterable
    {
        yield 'null passes through' => [null, null];
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'nonzero int is true' => [2, true];
        yield 'zero int is false' => [0, false];
        yield 'nonzero float is true' => [1.5, true];
        yield 'zero float is false' => [0.0, false];
        yield 'string TRUE (case-insensitive, trimmed)' => ['  TRUE ', true];
        yield 'string yes' => ['yes', true];
        yield 'string on' => ['on', true];
        yield 'string 1' => ['1', true];
        yield 'string off' => ['off', false];
        yield 'string no' => ['no', false];
        yield 'string 0' => ['0', false];
        yield 'empty string is false' => ['', false];
    }

    #[Test]
    public function itRejectsUnrecognisedBooleanStrings(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('flag must be true or false');
        Coerce::bool('maybe', 'flag');
    }

    #[Test]
    public function itRejectsArraysAsBooleans(): void
    {
        $exception = self::catch(static fn (): mixed => Coerce::bool([1], 'flag'));
        self::assertSame(400, $exception->status);
        self::assertSame(['flag must be true or false'], $exception->messages);
    }

    /**
     * @param int|float|string|array<int, int>|null $value
     */
    #[Test]
    #[DataProvider('integers')]
    public function itCoercesIntegers(int|float|string|array|null $value, ?int $expected): void
    {
        self::assertSame($expected, Coerce::int($value, 'count'));
    }

    /**
     * @return iterable<string, array{0: int|float|string|array<int, int>|null, 1: int|null}>
     */
    public static function integers(): iterable
    {
        yield 'null is null' => [null, null];
        yield 'empty string is null' => ['', null];
        yield 'int stays' => [5, 5];
        yield 'finite float truncates' => [5.9, 5];
        yield 'padded numeric string' => ['  42 ', 42];
        yield 'negative string' => ['-3', -3];
        yield 'explicit plus' => ['+3', 3];
        yield 'leading-zero string is decimal' => ['08', 8];
    }

    /**
     * @param int|float|string|array<int, int>|null $value
     */
    #[Test]
    #[DataProvider('nonIntegers')]
    public function itRejectsNonIntegers(int|float|string|array|null $value): void
    {
        $exception = self::catch(static fn (): mixed => Coerce::int($value, 'count'));
        self::assertSame(400, $exception->status);
        self::assertSame('count must be an integer', $exception->messages[0]);
    }

    /**
     * @return iterable<string, array{0: int|float|string|array<int, int>|null}>
     */
    public static function nonIntegers(): iterable
    {
        yield 'decimal string' => ['3.5'];
        yield 'trailing letters' => ['3x'];
        yield 'letters' => ['x'];
        yield 'infinity is not finite' => [\INF];
        yield 'array' => [[1]];
    }

    #[Test]
    public function itCoercesStrings(): void
    {
        self::assertNull(Coerce::string(null, 'name'));
        self::assertSame('a', Coerce::string('a', 'name'));
        self::assertSame('5', Coerce::string(5, 'name'));
        self::assertSame('5.5', Coerce::string(5.5, 'name'));
    }

    #[Test]
    public function itRejectsNonScalarsAsStrings(): void
    {
        $exception = self::catch(static fn (): mixed => Coerce::string(true, 'name'));
        self::assertSame(400, $exception->status);
        self::assertSame('name must be a string', $exception->messages[0]);
    }

    #[Test]
    #[DataProvider('validHttpUrls')]
    public function itAcceptsHttpAndHttpsUrls(string $url): void
    {
        Coerce::httpUrl($url, 'document_url');
        $this->addToAssertionCount(1); // no exception thrown
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validHttpUrls(): iterable
    {
        yield 'http' => ['http://example.com'];
        yield 'https with path' => ['https://example.com/a/b'];
        yield 'uppercase scheme' => ['HTTP://Example.com'];
    }

    #[Test]
    #[DataProvider('invalidHttpUrls')]
    public function itRejectsNonHttpUrls(string $url): void
    {
        // httpUrl returns void, so it is wrapped in a void closure rather than an arrow fn.
        $exception = self::catch(static function () use ($url): void { Coerce::httpUrl($url, 'document_url'); });
        self::assertSame(400, $exception->status);
        self::assertSame('document_url must be an absolute http or https URL', $exception->messages[0]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidHttpUrls(): iterable
    {
        yield 'ftp scheme' => ['ftp://example.com'];
        yield 'no host' => ['https:///path'];
        yield 'not a url' => ['not a url'];
        yield 'relative' => ['/relative/path'];
    }

    #[Test]
    public function itParsesIsoDatesInUtc(): void
    {
        self::assertNull(Coerce::dateTime(null, 'when'));
        self::assertNull(Coerce::dateTime('', 'when'));

        $date = Coerce::dateTime('2026-01-02', 'when');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-01-02 00:00:00 UTC', $date->format('Y-m-d H:i:s T'));

        $withTime = Coerce::dateTime('2026-01-02T03:04:05Z', 'when');
        self::assertInstanceOf(\DateTimeImmutable::class, $withTime);
        self::assertSame('2026-01-02T03:04:05+00:00', $withTime->format('c'));
    }

    #[Test]
    public function itKeepsAnExplicitOffset(): void
    {
        $date = Coerce::dateTime('2026-01-02T03:04:05+02:00', 'when');
        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-01-02T03:04:05+02:00', $date->format('c'));
    }

    #[Test]
    #[DataProvider('invalidDates')]
    public function itRejectsNonIsoDates(int|string $value): void
    {
        $exception = self::catch(static fn (): mixed => Coerce::dateTime($value, 'when'));
        self::assertSame(400, $exception->status);
        self::assertSame('when must be an ISO 8601 date and time', $exception->messages[0]);
    }

    /**
     * @return iterable<string, array{0: int|string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'impossible calendar date' => ['2026-13-40'];
        yield 'free text' => ['not a date'];
        yield 'integer' => [123];
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
