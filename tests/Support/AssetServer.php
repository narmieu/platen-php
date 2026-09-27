<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * A local web server standing in for the asset host and the callback receiver.
 */
final class AssetServer
{
    private function __construct(
        private readonly Process $process,
        public readonly int $port,
        private readonly string $log,
    ) {
    }

    public static function start(): self
    {
        $port = FreePort::find();
        $log = (string) tempnam(sys_get_temp_dir(), 'platen-assets-');
        // Listening on every address lets the tests reach it through a blocked one too.
        $process = new Process(
            [\PHP_BINARY, '-S', "0.0.0.0:{$port}", __DIR__.'/asset-server.php'],
            null,
            ['ASSET_SERVER_LOG' => $log, 'PHP_CLI_SERVER_WORKERS' => '8'],
        );
        $process->start();
        FreePort::waitUntilListening($port, $process);

        return new self($process, $port, $log);
    }

    public function stop(): void
    {
        $this->process->stop(1);
        @unlink($this->log);
    }

    public function url(string $path, string $host = '127.0.0.1'): string
    {
        return "http://{$host}:{$this->port}{$path}";
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function hits(): array
    {
        $hits = [];
        foreach (file($this->log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $hit = json_decode($line, true, 8, \JSON_THROW_ON_ERROR);
            if (!\is_array($hit)) {
                continue;
            }
            $headers = [];
            foreach (\is_array($hit['headers'] ?? null) ? $hit['headers'] : [] as $name => $value) {
                $headers[(string) $name] = \is_string($value) ? $value : '';
            }
            $hits[] = [
                'method' => \is_string($hit['method'] ?? null) ? $hit['method'] : '',
                'path' => \is_string($hit['path'] ?? null) ? $hit['path'] : '',
                'headers' => $headers,
                'body' => \is_string($hit['body'] ?? null) ? $hit['body'] : '',
            ];
        }

        return $hits;
    }

    public function count(string $path): int
    {
        return \count(array_filter($this->hits(), static fn (array $hit): bool => $hit['path'] === $path));
    }

    /**
     * @return list<array<string, string>> the headers of each request for $path
     */
    public function headersFor(string $path): array
    {
        return array_values(array_map(
            static fn (array $hit): array => $hit['headers'],
            array_filter($this->hits(), static fn (array $hit): bool => $hit['path'] === $path),
        ));
    }

    /**
     * @return list<array<string, string>> the form fields of each callback received
     */
    public function callbacks(): array
    {
        $callbacks = [];
        foreach ($this->hits() as $hit) {
            if ('POST' === $hit['method'] && '/callback' === $hit['path']) {
                parse_str($hit['body'], $fields);
                $callback = [];
                foreach ($fields as $name => $value) {
                    $callback[(string) $name] = \is_string($value) ? $value : '';
                }
                $callbacks[] = $callback;
            }
        }

        return $callbacks;
    }
}
