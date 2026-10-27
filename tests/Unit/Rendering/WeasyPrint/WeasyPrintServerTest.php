<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering\WeasyPrint;

use App\Rendering\Deadline;
use App\Rendering\WeasyPrint\WeasyPrintServer;
use App\Rendering\WeasyPrint\WeasyPrintServerStopped;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeasyPrintServerTest extends TestCase
{
    private string $socket;

    protected function setUp(): void
    {
        $this->socket = sys_get_temp_dir().'/platen-test-'.bin2hex(random_bytes(4)).'.sock';
    }

    protected function tearDown(): void
    {
        @unlink($this->socket);
    }

    #[Test]
    public function anUnreachableServerIsNull(): void
    {
        self::assertNull(new WeasyPrintServer()->render($this->socket, [], [], '<p>x</p>', Deadline::in(5)));
    }

    #[Test]
    public function itSendsTheRequestAndReadsTheAnswer(): void
    {
        $request = $this->serveOnce("{\"exit\": 0, \"stderr\": \"warning\"}\n");

        $answer = new WeasyPrintServer()->render($this->socket, ['-', '/out.pdf'], ['A' => 'a', 'B' => false], '<p>x</p>', Deadline::in(5));

        self::assertSame(['exit' => 0, 'stderr' => 'warning'], $answer);
        [$header, $html] = explode("\n", $request(), 2);
        $header = json_decode($header, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($header);
        self::assertSame(['-', '/out.pdf'], $header['argv']);
        self::assertSame(['A' => 'a', 'B' => null], $header['env']);
        self::assertSame('<p>x</p>', $html);
    }

    #[Test]
    public function noAnswerMeansTheProcessStopped(): void
    {
        $request = $this->serveOnce('');

        try {
            new WeasyPrintServer()->render($this->socket, [], [], '<p>x</p>', Deadline::in(5));
            self::fail('No exception');
        } catch (WeasyPrintServerStopped) {
            self::assertStringEndsWith('<p>x</p>', $request());
        }
    }

    /**
     * Answers one connection from a forked process; the returned function waits for it and
     * returns the request it read.
     *
     * @return \Closure(): string
     */
    private function serveOnce(string $answer): \Closure
    {
        $server = stream_socket_server("unix://{$this->socket}");
        self::assertNotFalse($server);
        $received = $this->socket.'.request';
        $pid = pcntl_fork();
        if (0 === $pid) {
            $connection = stream_socket_accept($server, 5);
            if (false !== $connection) {
                file_put_contents($received, stream_get_contents($connection));
                fwrite($connection, $answer);
                fclose($connection);
            }
            // Ends the child without running PHPUnit's shutdown.
            posix_kill(posix_getpid(), \SIGKILL);
        }
        fclose($server);

        return static function () use ($pid, $received): string {
            pcntl_waitpid($pid, $status);
            $request = (string) file_get_contents($received);
            unlink($received);

            return $request;
        };
    }
}
