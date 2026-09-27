<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Config\PlatenConfig;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts the callback through the egress proxy, so a callback URL cannot reach a
 * blocked network. A failed callback throws, and Messenger retries it (see messenger.yaml).
 */
#[AsMessageHandler]
final readonly class SendCallbackHandler
{
    public function __construct(
        private PlatenConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function __invoke(SendCallback $message): void
    {
        $response = $this->httpClient->request('POST', $message->url, [
            'body' => $message->fields,
            'proxy' => $this->config->egressProxy,
            'no_proxy' => '',
            'timeout' => 15,
            'max_duration' => 30,
            'max_redirects' => 0,
        ]);
        $status = $response->getStatusCode();
        if ($status >= 500) {
            throw new \RuntimeException("The callback {$message->url} answered HTTP {$status}");
        }
    }
}
