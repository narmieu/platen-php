<?php

declare(strict_types=1);

namespace App\Network;

use Clue\React\HttpProxy\ProxyConnector;
use Psr\Log\LoggerInterface;
use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use React\Socket\TimeoutConnector;

use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A small HTTP forward proxy (CONNECT and absolute-form requests) that every renderer
 * fetch goes through.
 *
 * Because each request, including every redirect hop, passes through it, the network
 * policy cannot be bypassed with redirects; and because it connects to the address it
 * checked, DNS rebinding does not help either. Each client connection carries one
 * request: the destination is asked to close the connection after its response.
 */
final readonly class EgressProxy
{
    private const int MAX_HEAD_BYTES = 65536;
    private const float HEAD_TIMEOUT = 30.0;
    private const float IDLE_TIMEOUT = 120.0;
    private const float CONNECT_TIMEOUT = 10.0;
    private const array HOP_BY_HOP = ['proxy-connection', 'proxy-authorization', 'connection', 'keep-alive'];

    /** Connects to addresses the policy has checked. */
    private ConnectorInterface $connector;

    /** Connects to the operator's upstream proxies, which the policy does not apply to. */
    private ConnectorInterface $upstreamConnector;

    public function __construct(
        private NetworkPolicy $policy,
        private ResolverInterface $resolver,
        private LoopInterface $loop,
        private LoggerInterface $logger,
        private ?UpstreamProxy $httpUpstream = null,
        private ?UpstreamProxy $httpsUpstream = null,
    ) {
        $this->connector = new TimeoutConnector(new TcpConnector($loop), self::CONNECT_TIMEOUT, $loop);
        $this->upstreamConnector = new Connector(['dns' => $resolver, 'timeout' => self::CONNECT_TIMEOUT], $loop);
    }

    public function listen(string $address): SocketServer
    {
        $server = new SocketServer($address, [], $this->loop);
        $server->on('connection', $this->accept(...));

        return $server;
    }

    private function accept(ConnectionInterface $client): void
    {
        $buffer = '';
        $timer = $this->loop->addTimer(self::HEAD_TIMEOUT, $client->close(...));
        $onData = function (string $data) use ($client, &$buffer, &$onData, $timer): void {
            $buffer .= $data;
            $end = strpos($buffer, "\r\n\r\n");
            if (false === $end) {
                if (\strlen($buffer) > self::MAX_HEAD_BYTES) {
                    $this->loop->cancelTimer($timer);
                    self::reply($client, 431, 'Request header fields too large');
                }

                return;
            }
            $this->loop->cancelTimer($timer);
            $client->removeListener('data', $onData);
            // Hold the rest of the request until the destination is connected.
            $client->pause();
            $this->route($client, substr($buffer, 0, $end), substr($buffer, $end + 4));
        };
        $client->on('data', $onData);
    }

    private function route(ConnectionInterface $client, string $head, string $rest): void
    {
        $lines = explode("\r\n", $head);
        $requestLine = explode(' ', array_shift($lines), 3);
        if (3 !== \count($requestLine)) {
            self::reply($client, 400, 'Bad request');

            return;
        }
        [$method, $target, $version] = $requestLine;

        if ('CONNECT' === strtoupper($method)) {
            [$host, $port] = self::hostAndPort($target, 443);
            $upstream = $this->upstreamFor($this->httpsUpstream, $host);
            $opening = $this->open($host, $port, $upstream, tunnel: true)->then(
                function (ConnectionInterface $remote) use ($client, $rest): void {
                    $client->write("HTTP/1.1 200 Connection established\r\n\r\n");
                    if ('' !== $rest) {
                        $remote->write($rest);
                    }
                    $this->splice($client, $remote);
                },
                fn (\Throwable $error) => $this->refuse($client, $method, $target, $error),
            );
        } else {
            $url = parse_url($target);
            if (false === $url || 'http' !== strtolower($url['scheme'] ?? '') || !isset($url['host'])) {
                self::reply($client, 400, 'Only absolute http:// URLs can be proxied');

                return;
            }
            $host = trim($url['host'], '[]');
            $upstream = $this->upstreamFor($this->httpUpstream, $host);
            $path = ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
            $head = implode("\r\n", [
                null === $upstream ? "{$method} {$path} {$version}" : "{$method} {$target} {$version}",
                ...self::forwardedHeaders($lines, $target, $upstream),
            ])."\r\n\r\n";

            $opening = $this->open($host, $url['port'] ?? 80, $upstream, tunnel: false)->then(
                function (ConnectionInterface $remote) use ($client, $head, $rest): void {
                    $remote->write($head.$rest);
                    $this->splice($client, $remote);
                },
                fn (\Throwable $error) => $this->refuse($client, $method, $target, $error),
            );
        }
        // A client that gives up stops the lookup or the connection attempt.
        $client->on('close', $opening->cancel(...));
    }

    /**
     * Resolves the host, checks every address against the policy, and connects.
     *
     * @return PromiseInterface<ConnectionInterface>
     */
    private function open(string $host, int $port, ?UpstreamProxy $upstream, bool $tunnel): PromiseInterface
    {
        return $this->resolve($host)->then(
            function (array $addresses) use ($host, $port, $upstream, $tunnel): PromiseInterface {
                // One blocked address blocks the host.
                foreach ($addresses as $address) {
                    $this->policy->check($address);
                }

                return null === $upstream
                    ? $this->connectAny($addresses, $port)
                    : $this->viaUpstream($upstream, $host, $port, $tunnel);
            },
            function (\Throwable $error) use ($host, $port, $upstream, $tunnel): PromiseInterface {
                // A name only the upstream proxy can resolve.
                return null === $upstream ? reject($error) : $this->viaUpstream($upstream, $host, $port, $tunnel);
            },
        );
    }

    /**
     * @return PromiseInterface<list<string>>
     */
    private function resolve(string $host): PromiseInterface
    {
        if (false !== @inet_pton($host)) {
            return resolve([$host]);
        }
        $lookups = array_map(
            fn (int $type): PromiseInterface => $this->resolver->resolveAll($host, $type)->then(
                static fn (array $addresses): array => array_values(array_filter($addresses, \is_string(...))),
                static fn (): array => [],
            ),
            [Message::TYPE_A, Message::TYPE_AAAA],
        );

        return all($lookups)->then(static function (array $results) use ($host): array {
            $addresses = array_values(array_unique(array_merge(...$results)));
            if ([] === $addresses) {
                throw new \RuntimeException("cannot resolve {$host}");
            }

            return $addresses;
        });
    }

    /**
     * @param list<string> $addresses
     *
     * @return PromiseInterface<ConnectionInterface>
     */
    private function connectAny(array $addresses, int $port, ?\Throwable $lastError = null): PromiseInterface
    {
        $address = array_shift($addresses);
        if (null === $address) {
            return reject($lastError ?? new \RuntimeException('no address to connect to'));
        }
        $uri = str_contains($address, ':') ? "tcp://[{$address}]:{$port}" : "tcp://{$address}:{$port}";

        return $this->connector->connect($uri)->then(
            null,
            fn (\Throwable $error): PromiseInterface => $this->connectAny($addresses, $port, $error),
        );
    }

    /**
     * @return PromiseInterface<ConnectionInterface>
     */
    private function viaUpstream(UpstreamProxy $upstream, string $host, int $port, bool $tunnel): PromiseInterface
    {
        if (!$tunnel) {
            // A plain http:// request goes to the upstream proxy as it came, in absolute form.
            return $this->upstreamConnector->connect($upstream->address);
        }
        $authority = str_contains($host, ':') ? "[{$host}]:{$port}" : "{$host}:{$port}";

        return new ProxyConnector($upstream->url, $this->upstreamConnector)->connect("tcp://{$authority}");
    }

    private function upstreamFor(?UpstreamProxy $upstream, string $host): ?UpstreamProxy
    {
        return null !== $upstream && !$upstream->bypasses($host) ? $upstream : null;
    }

    private function refuse(ConnectionInterface $client, string $method, string $target, \Throwable $error): void
    {
        if ($error instanceof PolicyViolation) {
            $this->logger->warning('Blocked {method} {target}: {reason}', ['method' => $method, 'target' => $target, 'reason' => $error->getMessage()]);
            self::reply($client, 403, 'Blocked by network policy: '.$error->getMessage());

            return;
        }
        self::reply($client, 502, "Could not connect to {$target}: ".$error->getMessage());
    }

    /**
     * Pipes both ways until either side closes or the connection is idle too long.
     */
    private function splice(ConnectionInterface $client, ConnectionInterface $remote): void
    {
        $lastActivity = microtime(true);
        $touch = static function () use (&$lastActivity): void {
            $lastActivity = microtime(true);
        };
        $client->on('data', $touch);
        $remote->on('data', $touch);
        $client->pipe($remote);
        $remote->pipe($client);
        $timer = $this->loop->addPeriodicTimer(10.0, static function () use (&$lastActivity, $client): void {
            if (microtime(true) - $lastActivity > self::IDLE_TIMEOUT) {
                $client->close();
            }
        });
        // Flush what the destination sent before closing the client's side.
        $remote->on('close', $client->end(...));
        $client->on('close', function () use ($timer, $remote): void {
            $this->loop->cancelTimer($timer);
            $remote->close();
        });
        $client->resume();
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function forwardedHeaders(array $lines, string $url, ?UpstreamProxy $upstream): array
    {
        $ticket = null;
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = array_map(trim(...), explode(':', $line, 2) + [1 => '']);
            $lower = strtolower($name);
            if ('proxy-authorization' === $lower) {
                $ticket = ProxyTicket::fromProxyAuthorization($value);
            }
            if ('' !== $name && !\in_array($lower, self::HOP_BY_HOP, true)) {
                $headers[] = [$name, $value];
            }
        }

        if (null !== $ticket) {
            $current = [];
            foreach ($headers as [$name, $value]) {
                $current[strtolower($name)] = $value;
            }
            foreach ($ticket->apply($current, $url) as $name => $value) {
                if (($current[$name] ?? null) !== $value) {
                    $headers = array_values(array_filter($headers, static fn (array $header): bool => strtolower($header[0]) !== $name));
                    $headers[] = [ucwords($name, '-'), $value];
                }
            }
        }

        $headers[] = ['Connection', 'close'];
        if (null !== $upstream?->authorization) {
            $headers[] = ['Proxy-Authorization', $upstream->authorization];
        }

        return array_map(static fn (array $header): string => "{$header[0]}: {$header[1]}", $headers);
    }

    /**
     * @return array{string, int}
     */
    private static function hostAndPort(string $authority, int $defaultPort): array
    {
        if (1 === preg_match('/^\[([^]]+)\](?::(\d+))?$/', $authority, $match)) {
            return [$match[1], (int) ($match[2] ?? $defaultPort)];
        }
        $parts = explode(':', $authority);

        return 2 === \count($parts) ? [$parts[0], (int) $parts[1]] : [$authority, $defaultPort];
    }

    private static function reply(ConnectionInterface $client, int $status, string $message): void
    {
        // The reason phrase carries the explanation too: clients often show only that.
        $reason = preg_replace('/[^\x20-\x7e]+/', ' ', $message) ?? 'Error';
        $client->end("HTTP/1.1 {$status} {$reason}\r\nContent-Type: text/plain; charset=utf-8\r\n"
            .'Content-Length: '.\strlen($message)."\r\nConnection: close\r\n\r\n{$message}");
    }
}
