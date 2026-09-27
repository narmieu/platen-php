<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use App\Tests\Support\PlatenServer;

final class RestartTest extends EndToEndTestCase
{
    public function testAsyncDocumentsSurviveARestart(): void
    {
        self::stopPlaten();
        $dataDir = sys_get_temp_dir().'/platen-restart-'.bin2hex(random_bytes(6));
        $html = '<p>resumed</p><img src="'.self::assets()->url('/slow.png').'?seconds=3">';

        $server = PlatenServer::start([], $dataDir);
        try {
            $response = $server->client()->request('POST', '/async_docs', ['json' => ['document_type' => 'pdf', 'test' => false, 'document_content' => $html]]);
            $statusId = self::string($response->toArray()['status_id'] ?? null);
            sleep(1); // Rendering has started.
        } finally {
            $server->stop();
        }

        $server = PlatenServer::start([], $dataDir);
        try {
            $status = self::waitForStatus($statusId, $server->client());
            self::assertSame('completed', $status['status']);
            $download = $server->client()->request('GET', self::string($status['download_url'] ?? null));
            self::assertStringContainsString('resumed', PdfFile::fromBytes($download->getContent())->text());
        } finally {
            $server->stop();
        }
    }
}
