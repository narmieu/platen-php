<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\PlatenConfig;
use App\Supervisor\ChildProcess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs everything platen needs in one process tree: the HTTP server, the render
 * workers and the egress proxy. A child that exits is started again.
 */
#[AsCommand(name: 'platen:serve', description: 'Runs the HTTP server, the render workers and the egress proxy')]
final class ServeCommand extends Command implements SignalableCommandInterface
{
    /** Seconds the workers get to finish their current document when stopping. */
    private const float GRACE = 8.0;

    private bool $stopping = false;

    public function __construct(
        private readonly PlatenConfig $config,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        #[Autowire(env: 'PLATEN_HOST')]
        private readonly string $host,
        #[Autowire(env: 'int:PLATEN_PORT')]
        private readonly int $port,
        #[Autowire(env: 'int:PLATEN_WORKERS')]
        private readonly int $workers,
        #[Autowire(env: 'int:PLATEN_HTTP_WORKERS')]
        private readonly int $httpWorkers,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopping = true;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $log = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->prepare();

        $proxy = $this->console('egress-proxy', ['platen:egress-proxy']);
        $servers = [$this->httpServer()];
        for ($i = 1; $i <= max(1, $this->workers); ++$i) {
            // One worker also runs the schedule (the clean-up of expired documents).
            $transports = 1 === $i ? ['render', 'callbacks', 'scheduler_default'] : ['render', 'callbacks'];
            $servers[] = $this->console("worker-{$i}", [
                'messenger:consume', ...$transports,
                '--keepalive=5', '--time-limit=3600', '--memory-limit=256M', '--sleep=0.5',
            ]);
        }
        $children = [$proxy, ...$servers];
        foreach ($children as $child) {
            $child->start();
        }
        $log->writeln(\sprintf('platen is listening on http://%s:%d', $this->host, $this->port));
        if ([] === $this->config->apiKeys) {
            $log->writeln('<comment>PLATEN_API_KEYS is empty, so any API key is accepted.</comment>');
        }

        while (!$this->stopping) {
            foreach ($children as $child) {
                $pause = $child->supervise(microtime(true));
                if (null !== $pause) {
                    $log->writeln(\sprintf('%s exited with code %d; starting it again in %d s', $child->name, $child->exitCode(), $pause));
                }
            }
            usleep(200_000);
        }

        // Stop taking work first; the workers may still need the proxy to finish theirs.
        $this->stop($servers, self::GRACE);
        $this->stop([$proxy], 2.0);

        return Command::SUCCESS;
    }

    /**
     * Creates the data directories and brings the database schema up to date.
     */
    private function prepare(): void
    {
        foreach ([$this->config->dataDir, $this->config->dataDir.'/files', $this->config->dataDir.'/tmp'] as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
                throw new \RuntimeException("Cannot create the directory {$directory}");
            }
        }
        $application = $this->getApplication() ?? throw new \LogicException('The command has no application');
        $migrate = new ArrayInput(['command' => 'doctrine:migrations:migrate', '--allow-no-migration' => true]);
        $migrate->setInteractive(false);
        $output = new BufferedOutput();
        if (Command::SUCCESS !== $application->find('doctrine:migrations:migrate')->run($migrate, $output)) {
            throw new \RuntimeException("Could not migrate the database:\n".$output->fetch());
        }
    }

    private function httpServer(): ChildProcess
    {
        $address = "{$this->host}:{$this->port}";
        $public = $this->projectDir.'/public';
        $httpWorkers = max(1, $this->httpWorkers);
        $frankenphp = new ExecutableFinder()->find('frankenphp');
        if (null !== $frankenphp) {
            $command = [$frankenphp, 'php-server', '--listen', $address, '--root', $public];
            if ($this->debug) {
                // Classic mode boots the kernel on every request, so code changes apply at once.
                return new ChildProcess('http', $command, $this->childEnvironment());
            }

            // Worker mode: each of PLATEN_HTTP_WORKERS threads boots the kernel once and serves
            // one request at a time; further requests wait for a free worker. A worker is
            // replaced after FRANKENPHP_LOOP_MAX requests, so that leaks cannot build up.
            return new ChildProcess('http', [...$command, '--worker', "{$public}/index.php,{$httpWorkers}"], [
                ...$this->childEnvironment(),
                'FRANKENPHP_LOOP_MAX' => getenv('FRANKENPHP_LOOP_MAX') ?: '500',
            ]);
        }

        // PHP's built-in server, with a worker process per concurrent request. It only
        // exposes the environment (the PLATEN_* settings) through $_ENV.
        $bodyLimit = $this->config->maxRequestMb.'M';

        return new ChildProcess('http', [
            \PHP_BINARY,
            '-d', 'variables_order=EGPCS',
            '-d', 'expose_php=0',
            '-d', 'display_errors=0',
            '-d', "post_max_size={$bodyLimit}",
            '-d', "upload_max_filesize={$bodyLimit}",
            '-d', 'memory_limit='.(4 * $this->config->maxRequestMb + 128).'M',
            '-d', 'max_execution_time=0',
            '-S', $address,
            '-t', $public,
            $public.'/index.php',
        ], [...$this->childEnvironment(), 'PHP_CLI_SERVER_WORKERS' => (string) $httpWorkers]);
    }

    /**
     * @param list<string> $arguments
     */
    private function console(string $name, array $arguments): ChildProcess
    {
        return new ChildProcess($name, [\PHP_BINARY, $this->projectDir.'/bin/console', ...$arguments, '--no-interaction'], $this->childEnvironment());
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        return ['APP_ENV' => $this->environment, 'APP_DEBUG' => $this->debug ? '1' : '0'];
    }

    /**
     * @param list<ChildProcess> $children
     */
    private function stop(array $children, float $grace): void
    {
        foreach ($children as $child) {
            $child->signal(\SIGTERM);
        }
        $deadline = microtime(true) + $grace;
        while (microtime(true) < $deadline && [] !== array_filter($children, static fn (ChildProcess $child): bool => $child->isRunning())) {
            usleep(100_000);
        }
        foreach ($children as $child) {
            $child->signal(\SIGKILL);
        }
    }
}
