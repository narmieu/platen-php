<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DocumentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentKindTest extends TestCase
{
    #[Test]
    #[DataProvider('flags')]
    public function itMapsAsyncAndHostedFlagsToAKind(bool $async, bool $hosted, DocumentKind $expected): void
    {
        self::assertSame($expected, DocumentKind::of($async, $hosted));
    }

    /**
     * @return iterable<string, array{0: bool, 1: bool, 2: DocumentKind}>
     */
    public static function flags(): iterable
    {
        yield 'neither is sync' => [false, false, DocumentKind::Sync];
        yield 'hosted only' => [false, true, DocumentKind::Hosted];
        yield 'async only' => [true, false, DocumentKind::Async];
        yield 'both is hosted async' => [true, true, DocumentKind::HostedAsync];
    }

    #[Test]
    #[DataProvider('asyncCases')]
    public function isAsyncReflectsBackgroundRendering(DocumentKind $kind, bool $async): void
    {
        self::assertSame($async, $kind->isAsync());
    }

    /**
     * @return iterable<string, array{0: DocumentKind, 1: bool}>
     */
    public static function asyncCases(): iterable
    {
        yield 'sync' => [DocumentKind::Sync, false];
        yield 'hosted' => [DocumentKind::Hosted, false];
        yield 'async' => [DocumentKind::Async, true];
        yield 'hosted async' => [DocumentKind::HostedAsync, true];
    }

    #[Test]
    #[DataProvider('hostedCases')]
    public function isHostedReflectsStoredDownloads(DocumentKind $kind, bool $hosted): void
    {
        self::assertSame($hosted, $kind->isHosted());
    }

    /**
     * @return iterable<string, array{0: DocumentKind, 1: bool}>
     */
    public static function hostedCases(): iterable
    {
        yield 'sync' => [DocumentKind::Sync, false];
        yield 'hosted' => [DocumentKind::Hosted, true];
        yield 'async' => [DocumentKind::Async, false];
        yield 'hosted async' => [DocumentKind::HostedAsync, true];
    }
}
