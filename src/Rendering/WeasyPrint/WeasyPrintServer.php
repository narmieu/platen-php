<?php

declare(strict_types=1);

namespace App\Rendering\WeasyPrint;

use App\Rendering\Deadline;

/**
 * The client of weasyprint-platen-server, which keeps WeasyPrint imported and forks a
 * process per document (see docker/weasyprint/platen_weasyprint/server.py).
 */
final readonly class WeasyPrintServer
{
    private const int MAX_ANSWER_BYTES = 1024 * 1024;

    /**
     * Renders one document.
     *
     * @param list<string>                $arguments   WeasyPrint's command line without the program
     * @param array<string, string|false> $environment variables for this document; false unsets one
     *
     * @return array{exit: int, stderr: string}|null what weasyprint would have exited with and
     *                                               written to standard error; null when the
     *                                               server cannot be reached
     *
     * @throws WeasyPrintServerStopped when the document's process ended without an answer
     */
    public function render(string $socket, array $arguments, array $environment, string $html, Deadline $deadline): ?array
    {
        $connection = @stream_socket_client("unix://{$socket}", $errorCode, $errorMessage, 1.0);
        if (false === $connection) {
            return null;
        }

        try {
            stream_set_timeout($connection, (int) ceil(max(1.0, $deadline->remaining())));
            $request = json_encode([
                'argv' => $arguments,
                'env' => array_map(static fn (string|false $value): ?string => false === $value ? null : $value, $environment),
                'timeout' => max(1.0, $deadline->remaining()),
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (!self::write($connection, $request."\n".$html)) {
                throw new WeasyPrintServerStopped();
            }
            stream_socket_shutdown($connection, \STREAM_SHUT_WR);

            $line = fgets($connection, self::MAX_ANSWER_BYTES);
            $answer = false === $line ? null : json_decode($line, true);
            if (!\is_array($answer) || !\is_int($answer['exit'] ?? null)) {
                throw new WeasyPrintServerStopped();
            }

            return ['exit' => $answer['exit'], 'stderr' => \is_string($answer['stderr'] ?? null) ? $answer['stderr'] : ''];
        } finally {
            // When platen gives up, closing the connection leaves the process to its own
            // timeout, which the server set from the same deadline.
            fclose($connection);
        }
    }

    /**
     * @param resource $connection
     */
    private static function write($connection, string $data): bool
    {
        for ($written = 0; $written < \strlen($data); $written += $bytes) {
            $bytes = @fwrite($connection, substr($data, $written, 1 << 20));
            if (false === $bytes || 0 === $bytes) {
                return false;
            }
        }

        return true;
    }
}
