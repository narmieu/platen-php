<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

final class FreePort
{
    public static function find(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            throw new \RuntimeException('Cannot find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public static function waitUntilListening(int $port, Process $process, float $timeout = 10.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                throw new \RuntimeException("The server exited:\n".$process->getOutput().$process->getErrorOutput());
            }
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.2);
            if (false !== $connection) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException("Nothing is listening on port {$port}");
    }
}
