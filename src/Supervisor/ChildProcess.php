<?php

declare(strict_types=1);

namespace App\Supervisor;

/**
 * A long-running child process that shares the supervisor's output and is restarted
 * when it exits, after a pause that grows while it keeps failing quickly.
 */
final class ChildProcess
{
    private const float HEALTHY_AFTER = 30.0;
    private const float MAX_PAUSE = 30.0;

    /** @var resource|null */
    private $process;
    private float $startedAt = 0.0;
    private ?int $exitCode = null;
    private int $quickExits = 0;
    private ?float $restartAt = null;

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment added to the supervisor's own
     */
    public function __construct(
        public readonly string $name,
        private readonly array $command,
        private readonly array $environment = [],
    ) {
    }

    public function start(): void
    {
        $process = proc_open(
            $this->command,
            [0 => ['file', '/dev/null', 'r'], 1 => \STDOUT, 2 => \STDERR],
            $pipes,
            null,
            [...getenv(), ...$this->environment],
        );
        if (false === $process) {
            throw new \RuntimeException("Cannot start {$this->name}");
        }
        $this->process = $process;
        $this->startedAt = microtime(true);
        $this->exitCode = null;
        $this->restartAt = null;
    }

    public function isRunning(): bool
    {
        if (null === $this->process) {
            return false;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return true;
        }
        // The exit code is only reported once; proc_close() then releases the process.
        $this->exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
        proc_close($this->process);
        $this->process = null;

        return false;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Starts the process again once its pause is over.
     *
     * @return float|null the pause in seconds when the process has just exited
     */
    public function supervise(float $now): ?float
    {
        if (null !== $this->process) {
            if ($this->isRunning()) {
                return null;
            }
            $this->quickExits = $now - $this->startedAt < self::HEALTHY_AFTER ? $this->quickExits + 1 : 0;
            $pause = min(self::MAX_PAUSE, 2 ** max(0, $this->quickExits - 1));
            $this->restartAt = $now + $pause;

            return $pause;
        }
        if (null !== $this->restartAt && $now >= $this->restartAt) {
            $this->start();
        }

        return null;
    }

    public function signal(int $signal): void
    {
        if (null !== $this->process) {
            proc_terminate($this->process, $signal);
        }
    }
}
