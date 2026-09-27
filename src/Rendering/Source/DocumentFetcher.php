<?php

declare(strict_types=1);

namespace App\Rendering\Source;

use App\Config\PlatenConfig;
use App\Rendering\Deadline;
use App\Rendering\RenderException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads document_url through the egress proxy, so every redirect hop is checked
 * against the network policy.
 */
final readonly class DocumentFetcher
{
    public function __construct(
        private PlatenConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function fetch(string $url, array $headers, bool $verifyTls, ?int $timeout, Deadline $deadline): FetchedDocument
    {
        $limit = $this->config->maxRequestBytes();
        try {
            $response = $this->httpClient->request('GET', $url, [
                'proxy' => $this->config->egressProxy,
                'no_proxy' => '',
                'headers' => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', ...$headers],
                'timeout' => (float) ($timeout ?? $this->config->fetchTimeout),
                'max_duration' => max(1.0, $deadline->remaining()),
                'max_redirects' => 10,
                'verify_peer' => $verifyTls,
                'verify_host' => $verifyTls,
                'on_progress' => static function (int $downloaded) use ($limit): void {
                    if ($downloaded > $limit) {
                        throw new RenderException('document_url is larger than this server accepts');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new RenderException("document_url returned HTTP {$status}".self::reason($response->getInfo('response_headers')));
            }
            $body = $response->getContent();
            $finalUrl = $response->getInfo('url');
            $contentType = $response->getHeaders()['content-type'][0] ?? '';
        } catch (RenderException $exception) {
            throw $exception;
        } catch (TimeoutException) {
            throw new RenderException('document_url timed out');
        } catch (TransportExceptionInterface $exception) {
            if ($exception->getPrevious() instanceof RenderException) {
                throw $exception->getPrevious();
            }
            throw new RenderException('Could not load document_url: '.$exception->getMessage());
        }

        $charset = preg_match('/charset\s*=\s*"?([\w.:-]+)/i', $contentType, $match) ? $match[1] : null;

        return new FetchedDocument(
            HtmlDocument::parse($body, $charset)->toHtml(),
            \is_string($finalUrl) ? $finalUrl : $url,
        );
    }

    /**
     * The proxy explains a refusal in its reason phrase.
     */
    private static function reason(mixed $headers): string
    {
        $statusLine = \is_array($headers) && \is_string($headers[0] ?? null) ? $headers[0] : '';

        return str_contains($statusLine, 'network policy') ? ': '.preg_replace('/^HTTP\/\S+ \d+ /', '', $statusLine) : '';
    }
}
