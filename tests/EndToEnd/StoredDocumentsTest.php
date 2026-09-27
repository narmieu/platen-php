<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use App\Tests\Support\PlatenServer;

/**
 * Async and hosted documents, callbacks and the document listing.
 */
final class StoredDocumentsTest extends EndToEndTestCase
{
    public function testAsyncDocument(): void
    {
        $response = self::client()->request('POST', '/async_docs', ['json' => [
            'document_type' => 'pdf', 'test' => false, 'document_content' => '<p>async</p>',
        ]]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        $status = self::waitForStatus(self::string($response->toArray()['status_id'] ?? null));
        self::assertSame('completed', $status['status']);
        self::assertSame(1, $status['number_of_pages']);
        self::assertStringEndsWith('/download/'.self::string($status['download_id'] ?? null), self::string($status['download_url'] ?? null));
        self::assertStringStartsWith('Completed at', self::string($status['message'] ?? null));

        // Download URLs need no credentials.
        $download = self::platen()->client(null)->request('GET', self::string($status['download_url']));
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('no-store, private', $download->getHeaders()['cache-control'][0]);
        self::assertStringContainsString('async', PdfFile::fromBytes($download->getContent())->text());
    }

    public function testLegacyAsyncFlag(): void
    {
        $response = self::client()->request('POST', '/docs', ['json' => [
            'type' => 'pdf', 'async' => true, 'document_content' => '<p>legacy async</p>',
        ]]);

        $status = self::waitForStatus(self::string($response->toArray()['status_id'] ?? null));
        self::assertSame('completed', $status['status']);
    }

    public function testAsyncFailureIsReported(): void
    {
        $response = self::client()->request('POST', '/async_docs', ['json' => [
            'document_type' => 'pdf', 'javascript' => true, 'document_content' => "<script>console.error('boom')</script>",
        ]]);

        $status = self::waitForStatus(self::string($response->toArray()['status_id'] ?? null));
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('boom', self::string($status['validation_errors'] ?? null));
    }

    public function testStatusIsPrivateToItsOwner(): void
    {
        $response = self::client()->request('POST', '/async_docs', ['json' => ['document_type' => 'pdf', 'document_content' => '<p>x</p>']]);
        $statusId = self::string($response->toArray()['status_id'] ?? null);

        self::assertSame(403, self::platen()->client(PlatenServer::OTHER_KEY)->request('GET', "/status/{$statusId}")->getStatusCode());
        self::assertSame(200, self::client()->request('GET', "/status/{$statusId}.json")->getStatusCode());
    }

    public function testCallbackIsPosted(): void
    {
        $response = self::client()->request('POST', '/async_docs', ['json' => [
            'document_type' => 'pdf', 'document_content' => '<p>callback</p>', 'callback_url' => self::assets()->url('/callback'),
        ]]);
        $status = self::waitForStatus(self::string($response->toArray()['status_id'] ?? null));

        $deadline = microtime(true) + 10;
        do {
            $callbacks = array_filter(
                self::assets()->callbacks(),
                static fn (array $callback): bool => ($callback['download_id'] ?? null) === $status['download_id'],
            );
            if ([] !== $callbacks) {
                break;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        $callback = array_values($callbacks)[0] ?? null;
        self::assertNotNull($callback, 'No callback arrived');
        self::assertSame($status['download_url'], $callback['download_url'] ?? null);
    }

    public function testHostedDocumentWithDownloadLimit(): void
    {
        $response = self::client()->request('POST', '/hosted_docs', ['json' => [
            'document_type' => 'pdf', 'document_content' => '<p>hosted</p>', 'hosted_download_limit' => 1,
        ]]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $hosted = $response->toArray();
        self::assertSame('completed', $hosted['status']);

        $downloads = self::platen()->client(null);
        self::assertSame(200, $downloads->request('GET', self::string($hosted['download_url']))->getStatusCode());
        $second = $downloads->request('GET', self::string($hosted['download_url']));
        self::assertSame(400, $second->getStatusCode());
        self::assertStringContainsString('limit', $second->getContent(false));
    }

    public function testHostedExpiryAndExpire(): void
    {
        $downloads = self::platen()->client(null);
        $expired = self::client()->request('POST', '/hosted_docs', ['json' => [
            'document_type' => 'pdf', 'document_content' => '<p>x</p>', 'hosted_expires_at' => '2020-01-01T00:00:00Z',
        ]])->toArray();
        self::assertSame(400, $downloads->request('GET', self::string($expired['download_url']))->getStatusCode());

        $hosted = self::client()->request('POST', '/hosted_docs', ['json' => ['document_type' => 'pdf', 'document_content' => '<p>x</p>']])->toArray();
        $downloadUrl = self::string($hosted['download_url']);
        self::assertSame(200, $downloads->request('GET', $downloadUrl)->getStatusCode());
        self::assertSame(200, self::client()->request('PATCH', '/expire/'.self::string($hosted['download_id']))->getStatusCode());
        self::assertSame(400, $downloads->request('GET', $downloadUrl)->getStatusCode());
    }

    public function testHostedAsyncDocument(): void
    {
        $response = self::client()->request('POST', '/hosted_async_docs', ['json' => ['document_type' => 'pdf', 'document_content' => '<p>ha</p>']]);
        $status = self::waitForStatus(self::string($response->toArray()['status_id'] ?? null));

        self::assertSame(200, self::platen()->client(null)->request('GET', self::string($status['download_url']))->getStatusCode());
    }

    public function testInvalidDownloadId(): void
    {
        self::assertSame(400, self::platen()->client(null)->request('GET', '/download/not-a-real-id')->getStatusCode());
    }

    public function testDocumentListing(): void
    {
        self::client()->request('POST', '/docs', ['json' => [
            'document_type' => 'pdf', 'document_content' => '<p>x</p>', 'name' => 'listed', 'tag' => 't1',
        ]])->getContent();

        $documents = self::client()->request('GET', '/docs.json', ['query' => ['per_page' => 5]])->toArray();
        self::assertIsArray($documents[0] ?? null);
        self::assertSame('listed', $documents[0]['name']);
        self::assertSame('t1', $documents[0]['tag']);
    }
}
