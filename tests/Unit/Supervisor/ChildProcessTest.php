<?php

declare(strict_types=1);

namespace App\Tests\Unit\Supervisor;

use App\Supervisor\ChildProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the supervisor with short-lived `php -r` children, so no real service is needed.
 */
final class ChildProcessTest extends TestCase
{
    #[Test]
    public function itReportsTheExitCodeAfterAChildExits(): void
    {
        $child = new ChildProcess('quick', [\PHP_BINARY, '-r', 'exit(7);']);
        $child->start();
        self::assertTrue($child->isRunning()); // running right after start

        self::waitUntilExited($child);
        self::assertFalse($child->isRunning());
        self::assertSame(7, $child->exitCode());
    }

    #[Test]
    public function itReportsSignalDeathsAs128PlusTheSignal(): void
    {
        $child = new ChildProcess('long', [\PHP_BINARY, '-r', 'sleep(30);']);
        $child->start();
        // Give the child a moment to actually be running before signalling.
        for ($i = 0; $i < 100 && !$child->isRunning(); ++$i) {
            usleep(5_000);
        }
        $child->signal(\SIGKILL);

        self::waitUntilExited($child);
        self::assertSame(128 + \SIGKILL, $child->exitCode());
    }

    #[Test]
    public function itPassesExtraEnvironmentToTheChild(): void
    {
        // The child exits 0 only when it sees the injected variable.
        $child = new ChildProcess('env', [\PHP_BINARY, '-r', 'exit(getenv("PLATEN_CHILD_VAR") === "present" ? 0 : 1);'], ['PLATEN_CHILD_VAR' => 'present']);
        $child->start();
        self::waitUntilExited($child);
        self::assertSame(0, $child->exitCode());
    }

    #[Test]
    public function beforeStartingItIsNotRunningAndHasNoExitCode(): void
    {
        $child = new ChildProcess('never', [\PHP_BINARY, '-r', 'exit(0);']);
        self::assertFalse($child->isRunning());
        self::assertNull($child->exitCode());
        // signal and supervise are safe no-ops before start.
        $child->signal(\SIGTERM);
        self::assertNull($child->supervise(1.0));
    }

    #[Test]
    public function superviseReturnsNullWhileTheChildRuns(): void
    {
        $child = new ChildProcess('long', [\PHP_BINARY, '-r', 'sleep(30);']);
        $child->start();
        self::assertNull($child->supervise(microtime(true)));
        $child->signal(\SIGKILL);
        self::waitUntilExited($child);
    }

    #[Test]
    public function theRestartPauseGrowsWhileTheChildKeepsExitingQuickly(): void
    {
        // Each quick exit doubles the pause, capped at 30 seconds: 1, 2, 4, 8, 16, 30.
        $child = new ChildProcess('flapper', [\PHP_BINARY, '-r', 'exit(1);']);
        $child->start();

        $pauses = [];
        for ($iteration = 0; \count($pauses) < 6; ++$iteration) {
            self::assertLessThan(200, $iteration, 'The child never exited');
            usleep(20_000);
            // supervise() must detect the exit itself to grow the pause.
            $pause = $child->supervise(microtime(true));
            if (null === $pause) {
                continue;
            }
            $pauses[] = $pause;
            // Move the clock past the restart time so the next supervise() restarts it.
            $child->supervise(microtime(true) + $pause + 0.01);
        }

        self::assertSame([1.0, 2.0, 4.0, 8.0, 16.0, 30.0], $pauses);
    }

    private static function waitUntilExited(ChildProcess $child): void
    {
        for ($i = 0; $i < 400; ++$i) {
            if (!$child->isRunning()) {
                return;
            }
            usleep(5_000);
        }
        self::fail('The child did not exit in time');
    }
}
