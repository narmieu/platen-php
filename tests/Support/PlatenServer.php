<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A platen server started with `bin/console platen:serve`, as in production.
 *
 * Chromium reaches the egress proxy at the address platen-chromium was started with
 * (PLATEN_CHROMIUM_PROXY), 127.0.0.1:3128 unless PLATEN_TEST_EGRESS_PORT says otherwise,
 * so only one server runs at a time.
 */
final class PlatenServer
{
    public const string API_KEY = 'test-key';
    public const string OTHER_KEY = 'other-key';

    private function __construct(
        private readonly Process $process,
        public readonly string $url,
        public readonly string $dataDir,
    ) {
    }

    /**
     * @param array<string, string> $settings PLATEN_* settings without the prefix, e.g. ['SYNC_TIMEOUT' => '4']
     */
    public static function start(array $settings = [], ?string $dataDir = null): self
    {
        $port = FreePort::find();
        $egressPort = getenv('PLATEN_TEST_EGRESS_PORT') ?: '3128';
        $dataDir ??= sys_get_temp_dir().'/platen-test-'.bin2hex(random_bytes(6));
        $environment = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'PLATEN_HOST' => '127.0.0.1',
            'PLATEN_PORT' => (string) $port,
            'PLATEN_DATA_DIR' => $dataDir,
            'PLATEN_API_KEYS' => self::API_KEY.','.self::OTHER_KEY,
            'PLATEN_BLOCKED_NETWORKS' => self::blockedHost().'/32',
            'PLATEN_EGRESS_LISTEN' => "127.0.0.1:{$egressPort}",
            'PLATEN_EGRESS_PROXY' => "http://127.0.0.1:{$egressPort}",
            'PLATEN_WORKERS' => '2',
            'PLATEN_WEASYPRINT_BINARY' => 'weasyprint-platen',
        ];
        foreach ($settings as $name => $value) {
            $environment["PLATEN_{$name}"] = $value;
        }
        // The server reads .env itself; the values this process loaded from it would
        // otherwise pass as real environment variables and win.
        $loaded = \is_string($_SERVER['SYMFONY_DOTENV_VARS'] ?? null) ? explode(',', $_SERVER['SYMFONY_DOTENV_VARS']) : [];
        foreach ([...array_filter($loaded), 'SYMFONY_DOTENV_VARS'] as $name) {
            $environment[$name] ??= false;
        }
        $process = new Process([\PHP_BINARY, 'bin/console', 'platen:serve'], \dirname(__DIR__, 2), $environment, null, null);
        $process->start();

        $server = new self($process, "http://127.0.0.1:{$port}", $dataDir);
        $server->waitUntilHealthy();

        return $server;
    }

    /**
     * An address the renderers must not reach, standing in for an internal network. It
     * must be an address of this machine other than loopback, which Chromium reaches
     * without going through the egress proxy.
     */
    public static function blockedHost(): string
    {
        return getenv('PLATEN_TEST_BLOCKED_HOST') ?: '172.17.0.1';
    }

    public function client(?string $key = self::API_KEY): HttpClientInterface
    {
        return HttpClient::create([
            'base_uri' => $this->url,
            'auth_basic' => null === $key ? null : [$key, ''],
            'timeout' => 120,
            'no_proxy' => '*',
        ]);
    }

    public function stop(): void
    {
        $this->process->stop(15, \SIGTERM);
    }

    public function output(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }

    private function waitUntilHealthy(): void
    {
        $client = $this->client(null);
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            if (!$this->process->isRunning()) {
                throw new \RuntimeException("platen exited:\n".$this->output());
            }
            try {
                if (200 === $client->request('GET', '/healthz', ['timeout' => 1])->getStatusCode()) {
                    return;
                }
            } catch (\Throwable) {
                // Not listening yet.
            }
            usleep(200_000);
        }
        $this->stop();
        throw new \RuntimeException("platen did not start:\n".$this->output());
    }
}
