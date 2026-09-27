<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Tests\Support\AssetServer;
use App\Tests\Support\PlatenServer;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests against a running platen server, through its HTTP API only.
 *
 * The server and the asset server are shared by all tests and stopped when PHPUnit exits.
 * Set PLATEN_TEST_SKIP_CHROMIUM=1 to skip the Chromium cases when Gotenberg is not running.
 */
abstract class EndToEndTestCase extends TestCase
{
    private static ?PlatenServer $platen = null;
    private static ?AssetServer $assets = null;

    /**
     * @return iterable<string, array{string}>
     */
    public static function engines(): iterable
    {
        yield 'weasyprint' => ['weasyprint'];
        yield 'chromium' => ['chromium'];
    }

    protected static function platen(): PlatenServer
    {
        if (null === self::$platen) {
            self::$platen = PlatenServer::start();
            register_shutdown_function(self::stopPlaten(...));
        }

        return self::$platen;
    }

    /**
     * For tests that run a server of their own: only one can run at a time.
     */
    protected static function stopPlaten(): void
    {
        self::$platen?->stop();
        self::$platen = null;
    }

    protected static function assets(): AssetServer
    {
        if (null === self::$assets) {
            self::$assets = AssetServer::start();
            register_shutdown_function(self::$assets->stop(...));
        }

        return self::$assets;
    }

    protected static function client(): HttpClientInterface
    {
        return self::platen()->client();
    }

    /**
     * Renders synchronously, like DocRaptor's POST /docs.
     *
     * @param array<string, mixed> $document
     */
    protected static function render(array $document, ?HttpClientInterface $client = null): ResponseInterface
    {
        return ($client ?? self::client())->request('POST', '/docs', [
            'json' => ['document_type' => 'pdf', 'test' => false, ...$document],
        ]);
    }

    /**
     * @return array<mixed>
     */
    protected static function waitForStatus(string $statusId, ?HttpClientInterface $client = null, float $timeout = 60): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $status = ($client ?? self::client())->request('GET', "/status/{$statusId}")->toArray();
            if (\in_array($status['status'] ?? null, ['completed', 'failed'], true)) {
                return $status;
            }
            usleep(200_000);
        }
        self::fail('The document did not finish');
    }

    protected static function requireEngine(string $engine): void
    {
        if ('chromium' === $engine && '' !== (string) getenv('PLATEN_TEST_SKIP_CHROMIUM')) {
            self::markTestSkipped('PLATEN_TEST_SKIP_CHROMIUM is set');
        }
    }

    protected static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
