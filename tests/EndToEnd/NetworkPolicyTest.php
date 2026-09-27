<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\PdfFile;
use App\Tests\Support\PlatenServer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What documents may fetch. The server blocks PlatenServer::blockedHost(), which stands
 * in for an internal network.
 */
final class NetworkPolicyTest extends EndToEndTestCase
{
    #[DataProvider('engines')]
    public function testRedirectIntoBlockedNetworkIsStopped(string $engine): void
    {
        self::requireEngine($engine);
        $target = self::assets()->url('/secret', PlatenServer::blockedHost());
        $html = '<img src="'.self::assets()->url('/redirect').'?to='.rawurlencode($target).'" width="10" height="10">';
        $before = self::assets()->count('/secret');

        $response = self::render(['pipeline' => $engine, 'document_content' => $html, 'ignore_resource_errors' => false]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($before, self::assets()->count('/secret'), 'The blocked host was contacted');
    }

    #[DataProvider('engines')]
    public function testBlockedNetworkDirect(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<img src="'.self::assets()->url('/secret', PlatenServer::blockedHost()).'">';
        $before = self::assets()->count('/secret');

        $response = self::render(['pipeline' => $engine, 'document_content' => $html, 'ignore_resource_errors' => false]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($before, self::assets()->count('/secret'), 'The blocked host was contacted');
    }

    #[DataProvider('engines')]
    public function testCloudMetadataAddressIsBlocked(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<img src="http://169.254.169.254/latest/meta-data/">',
            'ignore_resource_errors' => false,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('169.254.169.254', $response->getContent(false));
    }

    #[DataProvider('engines')]
    public function testFileUrlsAreBlocked(string $engine): void
    {
        self::requireEngine($engine);
        $response = self::render([
            'pipeline' => $engine,
            'document_content' => '<link rel="stylesheet" href="file:///etc/passwd"><img src="file:///etc/hostname"><p>x</p>',
            'ignore_resource_errors' => false,
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testFileDocumentUrlIsRejected(): void
    {
        $response = self::render(['document_url' => 'file:///etc/passwd']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('http', $response->getContent(false));
    }

    #[DataProvider('engines')]
    public function testNoNetwork(string $engine): void
    {
        self::requireEngine($engine);
        $html = '<img src="'.self::assets()->url("/pixel.png?no-network-{$engine}").'" width="10" height="10">';
        $before = \count(self::assets()->hits());

        $response = self::render(['pipeline' => $engine, 'document_content' => $html, 'prince_options' => ['no_network' => true]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, PdfFile::fromBytes($response->getContent())->imageCount());
        self::assertCount($before, self::assets()->hits());
    }
}
