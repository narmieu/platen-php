<?php

declare(strict_types=1);

namespace App\Rendering\Chromium;

use App\Api\ApiException;
use App\Config\PlatenConfig;
use App\Rendering\Engine;
use App\Rendering\EngineOutput;
use App\Rendering\Pdf\Qpdf;
use App\Rendering\RenderEngine;
use App\Rendering\RenderException;
use App\Rendering\RenderJob;
use App\Rendering\Workspace;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders with Chromium through platen-chromium (docker/chromium), a small service
 * around Playwright that keeps one browser running.
 *
 * The page carries a small script (hook.js) that waits for the document to be ready,
 * collects console output and failed resources, and reports them through the page
 * title, which Chromium writes into the PDF. platen reads the report back with qpdf
 * and restores the real title.
 */
final readonly class ChromiumEngine implements RenderEngine
{
    /**
     * The browser's own user agent says "HeadlessChrome", which some hosts refuse.
     */
    public const string USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36';

    public function __construct(
        private PlatenConfig $config,
        private HttpClientInterface $httpClient,
        private Qpdf $qpdf,
        private FontEmbedder $fonts,
    ) {
    }

    /**
     * The User-Agent Chromium sends, for the requests platen makes on its behalf.
     */
    public static function browserUserAgent(?string $token): string
    {
        return trim(self::USER_AGENT.' '.($token ?? ''));
    }

    public function engine(): Engine
    {
        return Engine::Chromium;
    }

    public function userAgent(?string $token): string
    {
        return self::browserUserAgent($token);
    }

    public function supportsPdfProfiles(): bool
    {
        return false;
    }

    public function render(RenderJob $job, Workspace $workspace): EngineOutput
    {
        $token = bin2hex(random_bytes(12));
        $page = new PagePreparer($this->config->jsTimeout)->prepare($job, $token, $this->fonts->css($job));
        $output = $workspace->file('chromium.pdf');
        $failedRequests = $this->convert($job, $page, $output);

        $info = $this->qpdf->info($output);
        $report = PageReport::fromTitle($info?->title(), PagePreparer::marker($token));
        if (null !== $report) {
            $this->check($job, $report);
        }
        if (!$job->ignoreResourceErrors && [] !== $failedRequests) {
            // What the hook cannot see: CSS backgrounds, fonts and the requests of scripts.
            throw self::resourceErrors($failedRequests);
        }

        // Chromium always compresses; qpdf undoes it for no_compress.
        $decompress = $job->options->noCompress;
        if (null === $info) {
            return new EngineOutput($output, decompress: $decompress);
        }
        $title = $job->options->pdfTitle ?? (null === $report ? $info->title() : $report->title);

        return new EngineOutput($output, $title === $info->title() ? null : $info->withTitle($title), $decompress);
    }

    /**
     * @return list<string> the requests that failed or got an HTTP error status
     */
    private function convert(RenderJob $job, string $page, string $output): array
    {
        $request = [
            'html' => $page,
            'ready' => '__platenReady',
            'timeout' => $job->deadline->remaining(),
            'media' => $job->media(),
            'userAgent' => self::browserUserAgent($job->userAgentToken),
            'headers' => null === $job->referrer ? new \stdClass() : ['Referer' => $job->referrer],
            // Prince sends the credentials with every request. When the document has a
            // base URL, platen keeps them to that origin.
            'authorization' => null === $job->credentials ? null : ['value' => $job->credentials->header(), 'origin' => $job->baseOrigin()],
        ];

        $timeout = max(1.0, $job->deadline->remaining());
        try {
            $response = $this->httpClient->request('POST', rtrim($this->config->chromiumUrl, '/').'/render', [
                'json' => $request,
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw $this->failure($job, $status, $response->getContent(false));
            }
            $file = fopen($output, 'w');
            if (false === $file) {
                throw new \RuntimeException("Cannot write {$output}");
            }
            try {
                foreach ($this->httpClient->stream($response) as $chunk) {
                    fwrite($file, $chunk->getContent());
                }
            } finally {
                fclose($file);
            }
            $failed = json_decode(base64_decode($response->getHeaders()['x-resource-errors'][0] ?? '', true) ?: '[]', true);

            return \is_array($failed) ? array_values(array_filter($failed, \is_string(...))) : [];
        } catch (TimeoutException) {
            throw $job->deadline->exceeded();
        } catch (TransportExceptionInterface $exception) {
            if ($job->deadline->remaining() <= 0.0) {
                throw $job->deadline->exceeded();
            }
            throw new ApiException(503, 'The Chromium renderer is unavailable: '.$exception->getMessage());
        }
    }

    private function check(RenderJob $job, PageReport $report): void
    {
        if ('render' === $report->timeout) {
            throw $job->deadline->exceeded();
        }
        if ('javascript' === $report->timeout) {
            throw new RenderException(\sprintf('docraptorJavaScriptFinished() did not return true within %d seconds', (int) round($this->config->jsTimeout)));
        }
        if ($job->javascript && !$job->ignoreConsoleMessages && [] !== $report->console) {
            throw new RenderException('The document\'s JavaScript wrote to the console, which stops rendering (set ignore_console_messages to true to allow it): '.implode(' | ', \array_slice($report->console, 0, 5)));
        }
        if (!$job->ignoreResourceErrors && [] !== $report->failed) {
            throw self::resourceErrors($report->failed);
        }
    }

    /**
     * @param list<string> $failed
     */
    private static function resourceErrors(array $failed): RenderException
    {
        return new RenderException('Failed to load resources (set ignore_resource_errors to true to render anyway): '.implode('; ', array_unique($failed)));
    }

    private function failure(RenderJob $job, int $status, string $body): ApiException
    {
        $error = json_decode($body, true);
        $message = \is_array($error) && \is_string($error['error'] ?? null) ? $error['error'] : trim($body);

        return match (true) {
            504 === $status => $job->deadline->exceeded(),
            $status >= 400 && $status < 500 => new RenderException("Chromium could not render the document: {$message}"),
            default => new ApiException(502, "The Chromium renderer failed: HTTP {$status} {$message}"),
        };
    }
}
