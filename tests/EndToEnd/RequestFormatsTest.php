<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use App\Tests\Support\PlatenServer;

/**
 * The request shapes DocRaptor accepts, and authentication.
 */
final class RequestFormatsTest extends EndToEndTestCase
{
    public function testLegacyWrapperWithUserCredentials(): void
    {
        $response = self::platen()->client(null)->request('POST', '/docs', ['json' => [
            'user_credentials' => PlatenServer::API_KEY,
            'doc' => ['type' => 'pdf', 'test' => false, 'document_content' => '<p>legacy</p>'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('legacy', PdfFile::fromBytes($response->getContent())->text());
    }

    public function testFormEncodedRequest(): void
    {
        $response = self::platen()->client(null)->request('POST', '/docs', ['body' => [
            'user_credentials' => PlatenServer::API_KEY,
            'doc[type]' => 'pdf',
            'doc[test]' => 'false',
            'doc[document_content]' => '<p class=s>form</p><style>@media print { .s { display: none } }</style>',
            'doc[prince_options][media]' => 'screen',
        ]]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('form', PdfFile::fromBytes($response->getContent())->text());
    }

    public function testUserCredentialsQueryParameter(): void
    {
        $response = self::platen()->client(null)->request('POST', '/docs', [
            'query' => ['user_credentials' => PlatenServer::API_KEY],
            'json' => ['document_type' => 'pdf', 'document_content' => '<p>q</p>'],
        ]);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
    }

    public function testMissingAndWrongKeys(): void
    {
        $document = ['json' => ['document_type' => 'pdf', 'document_content' => '<p>x</p>']];

        self::assertSame(401, self::platen()->client(null)->request('POST', '/docs', $document)->getStatusCode());
        self::assertSame(401, self::platen()->client('nope')->request('POST', '/docs', $document)->getStatusCode());
    }

    public function testBadRequests(): void
    {
        $client = self::client();

        self::assertSame(400, $client->request('POST', '/docs', ['body' => 'not json', 'headers' => ['content-type' => 'application/json']])->getStatusCode());
        self::assertSame(400, $client->request('POST', '/docs', ['json' => [1, 2]])->getStatusCode());
        self::assertSame(400, $client->request('POST', '/docs', ['json' => ['document_type' => 'pdf']])->getStatusCode());
        self::assertSame(400, $client->request('POST', '/docs', ['json' => ['document_type' => 'doc', 'document_content' => 'x']])->getStatusCode());
        self::assertSame(400, $client->request('POST', '/docs', ['json' => ['document_content' => 'x', 'test' => 'maybe']])->getStatusCode());
    }
}
