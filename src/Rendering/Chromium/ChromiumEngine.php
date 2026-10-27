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
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Renders with Chromium through Gotenberg (https://gotenberg.dev).
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
    public const string USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

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
        $this->convert($job, $page, $output);

        $info = $this->qpdf->info($output);
        $report = PageReport::fromTitle($info?->title(), PagePreparer::marker($token));
        if (null !== $report) {
            $this->check($job, $report);
        }

        // Chromium always compresses; qpdf undoes it for no_compress.
        $decompress = $job->options->noCompress;
        if (null === $info) {
            return new EngineOutput($output, decompress: $decompress);
        }
        $title = $job->options->pdfTitle ?? $report->title ?? $info->title();
        if (null === $report && null !== $title && 1 === preg_match('/^[0-9a-f-]{36}\.html$/', $title)) {
            $title = null; // Chromium names untitled pages after Gotenberg's file name
        }

        return new EngineOutput($output, $title === $info->title() ? null : $info->withTitle($title), $decompress);
    }

    private function convert(RenderJob $job, string $page, string $output): void
    {
        $fields = [
            'files' => new DataPart($page, 'index.html', 'text/html'),
            'paperWidth' => '8.5',
            'paperHeight' => '11',
            'marginTop' => '0',
            'marginBottom' => '0',
            'marginLeft' => '0',
            'marginRight' => '0',
            'preferCssPageSize' => 'true',
            'printBackground' => 'true',
            'generateDocumentOutline' => 'true',
            'generateTaggedPdf' => 'true',
            'emulatedMediaType' => $job->media(),
            'skipNetworkIdleEvent' => 'true',
            'skipNetworkAlmostIdleEvent' => 'true',
            'waitForExpression' => "typeof window.__platenReady !== 'function' || window.__platenReady() === true",
            'userAgent' => self::browserUserAgent($job->userAgentToken),
        ];
        $headers = $this->extraHeaders($job);
        if ([] !== $headers) {
            $fields['extraHttpHeaders'] = json_encode($headers, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }
        if (!$job->ignoreResourceErrors) {
            // Complements the hook, which sees failed elements but not CSS backgrounds or fonts.
            $fields['failOnResourceHttpStatusCodes'] = '[499,599]';
            $fields['failOnResourceLoadingFailed'] = 'true';
        }

        $form = new FormDataPart($fields);
        $timeout = max(1.0, $job->deadline->remaining());
        try {
            $response = $this->httpClient->request('POST', rtrim($this->config->gotenbergUrl, '/').'/forms/chromium/convert/html', [
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
                'timeout' => $timeout,
                'max_duration' => $timeout,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw $this->failure($status, $response->getContent(false));
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
        } catch (TimeoutException) {
            throw $job->deadline->exceeded();
        } catch (TransportExceptionInterface $exception) {
            if ($job->deadline->remaining() <= 0.0) {
                throw $job->deadline->exceeded();
            }
            throw new ApiException(503, 'The Chromium renderer (Gotenberg) is unavailable: '.$exception->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function extraHeaders(RenderJob $job): array
    {
        $headers = [];
        if (null !== $job->referrer) {
            $headers['Referer'] = $job->referrer;
        }
        if (null !== $job->credentials) {
            // Prince sends the credentials with every request. When the document has a
            // base URL, platen keeps them to that origin.
            $origin = $job->baseOrigin();
            $scope = null === $origin ? '' : ';scope=^'.preg_quote(preg_replace('/:(80|443)$/', '', $origin) ?? $origin, null).'(:\d+)?/';
            $headers['Authorization'] = $job->credentials->header().$scope;
        }

        return $headers;
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
            throw new RenderException('Failed to load resources (set ignore_resource_errors to true to render anyway): '.implode('; ', array_unique($report->failed)));
        }
    }

    private function failure(int $status, string $body): ApiException
    {
        $message = trim(preg_replace('/\s*\n\s*/', '; ', trim($body)) ?? $body);

        return match (true) {
            409 === $status => new RenderException("Failed to load resources (set ignore_resource_errors to true to render anyway): {$message}"),
            503 === $status => new RenderException('Rendering timed out'),
            $status >= 400 && $status < 500 => new RenderException("Chromium could not render the document: {$message}"),
            default => new ApiException(502, "The Chromium renderer (Gotenberg) failed: HTTP {$status} {$message}"),
        };
    }
}
