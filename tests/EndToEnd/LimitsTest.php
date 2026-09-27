<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use App\Tests\Support\PlatenServer;

/**
 * Timeouts, on a server of its own with short limits.
 */
final class LimitsTest extends EndToEndTestCase
{
    private static ?PlatenServer $server = null;

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    public function testWeasyPrintTimeoutStopsTheRenderAndTheNextOneWorks(): void
    {
        $client = self::server()->client();
        $started = microtime(true);

        $response = self::render(['document_content' => '<p>x</p><img src="'.self::assets()->url('/slow.png').'?seconds=30">'], $client);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('timed out', $response->getContent(false));
        self::assertLessThan(15, microtime(true) - $started);

        $next = self::render(['document_content' => '<p>after timeout</p>'], $client);
        self::assertSame(200, $next->getStatusCode(), $next->getContent(false));
        self::assertStringContainsString('after timeout', PdfFile::fromBytes($next->getContent())->text());
    }

    public function testRunawayJavaScriptTimesOut(): void
    {
        self::requireEngine('chromium');
        $client = self::server()->client();
        $started = microtime(true);

        $response = self::render(['pipeline' => 'chromium', 'javascript' => true, 'document_content' => '<p>x</p><script>while (true) {}</script>'], $client);

        self::assertSame(400, $response->getStatusCode());
        self::assertLessThan(15, microtime(true) - $started);

        $next = self::render(['pipeline' => 'chromium', 'javascript' => true, 'document_content' => '<p>still alive</p>'], $client);
        self::assertSame(200, $next->getStatusCode(), $next->getContent(false));
    }

    public function testDocRaptorJavaScriptFinishedNeverTrue(): void
    {
        self::requireEngine('chromium');
        $html = '<p>x</p><script>function docraptorJavaScriptFinished() { return false }</script>';

        $response = self::render(['javascript' => true, 'document_content' => $html], self::server()->client());

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('docraptorJavaScriptFinished', $response->getContent(false));
    }

    private static function server(): PlatenServer
    {
        if (null === self::$server) {
            self::stopPlaten();
            self::$server = PlatenServer::start(['SYNC_TIMEOUT' => '4', 'JS_TIMEOUT' => '2']);
        }

        return self::$server;
    }
}
