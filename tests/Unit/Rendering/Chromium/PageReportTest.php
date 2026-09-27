<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\Chromium;

use App\Rendering\Chromium\PagePreparer;
use App\Rendering\Chromium\PageReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageReportTest extends TestCase
{
    private const string MARKER = '[platen:tok] ';

    #[Test]
    public function itParsesAReportFromAMarkedTitle(): void
    {
        $json = json_encode([
            'title' => 'Real Title',
            'console' => ['log: a', 'log: b'],
            'failed' => ['https://x/img.png'],
            'timeout' => 'render',
            'error' => 'boom',
        ], \JSON_THROW_ON_ERROR);
        $report = PageReport::fromTitle(self::MARKER.$json, self::MARKER);

        self::assertInstanceOf(PageReport::class, $report);
        self::assertSame('Real Title', $report->title);
        self::assertSame(['log: a', 'log: b'], $report->console);
        self::assertSame(['https://x/img.png'], $report->failed);
        self::assertSame('render', $report->timeout);
        self::assertSame('boom', $report->error);
    }

    #[Test]
    public function theMarkerMatchesThePreparer(): void
    {
        self::assertSame(self::MARKER, PagePreparer::marker('tok'));
    }

    #[Test]
    public function nonStringConsoleAndFailedEntriesAreFilteredOut(): void
    {
        $json = json_encode(['console' => ['a', 1, null, 'b'], 'failed' => ['u1', false, 'u2']], \JSON_THROW_ON_ERROR);
        $report = PageReport::fromTitle(self::MARKER.$json, self::MARKER);

        self::assertInstanceOf(PageReport::class, $report);
        // array_values keeps them as proper lists after filtering.
        self::assertSame(['a', 'b'], $report->console);
        self::assertSame(['u1', 'u2'], $report->failed);
    }

    #[Test]
    public function emptyStringsBecomeNull(): void
    {
        $json = json_encode(['title' => '', 'timeout' => '', 'error' => ''], \JSON_THROW_ON_ERROR);
        $report = PageReport::fromTitle(self::MARKER.$json, self::MARKER);

        self::assertInstanceOf(PageReport::class, $report);
        self::assertNull($report->title);
        self::assertNull($report->timeout);
        self::assertNull($report->error);
        self::assertSame([], $report->console);
        self::assertSame([], $report->failed);
    }

    #[Test]
    public function itReturnsNullWithoutTheMarker(): void
    {
        self::assertNull(PageReport::fromTitle('a plain title', self::MARKER));
        self::assertNull(PageReport::fromTitle(null, self::MARKER));
    }

    #[Test]
    public function itReturnsNullWhenThePayloadIsNotJsonObject(): void
    {
        self::assertNull(PageReport::fromTitle(self::MARKER.'not json', self::MARKER));
        self::assertNull(PageReport::fromTitle(self::MARKER.'"a string"', self::MARKER));
    }
}
