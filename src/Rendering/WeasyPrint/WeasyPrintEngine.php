<?php

declare(strict_types=1);

namespace App\Rendering\WeasyPrint;

use App\Config\PlatenConfig;
use App\Network\ProxyTicket;
use App\Rendering\Engine;
use App\Rendering\EngineOutput;
use App\Rendering\PageStyles;
use App\Rendering\RenderEngine;
use App\Rendering\RenderException;
use App\Rendering\RenderJob;
use App\Rendering\Source\HtmlDocument;
use App\Rendering\Workspace;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders with the WeasyPrint command line tool, one process per document, so a
 * document that hangs or exhausts memory takes nothing else down with it.
 */
final readonly class WeasyPrintEngine implements RenderEngine
{
    public function __construct(private PlatenConfig $config)
    {
    }

    public function engine(): Engine
    {
        return Engine::WeasyPrint;
    }

    public function userAgent(?string $token): string
    {
        return trim('WeasyPrint (platen) '.($token ?? ''));
    }

    public function supportsPdfProfiles(): bool
    {
        return true;
    }

    public function render(RenderJob $job, Workspace $workspace): EngineOutput
    {
        $html = $job->html;
        if (null !== $job->options->pdfTitle) {
            // WeasyPrint takes the PDF title from <title>.
            $document = HtmlDocument::parse($html);
            $document->setTitle($job->options->pdfTitle);
            $html = $document->toHtml();
        }

        $output = $workspace->file('weasyprint.pdf');
        $process = new Process(
            [...$this->arguments($job), '-', $output],
            $workspace->path,
            $this->environment($job),
            $html,
            max(1.0, $job->deadline->remaining()),
        );
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw $job->deadline->exceeded();
        }
        if (!$process->isSuccessful() || !is_file($output)) {
            throw self::failure($process->getErrorOutput());
        }

        return new EngineOutput($output);
    }

    /**
     * @return list<string>
     */
    public function arguments(RenderJob $job): array
    {
        $options = $job->options;
        // Prince's defaults and the watermark are user stylesheets: the document's own
        // @page rules override the defaults, and nothing overrides the !important watermark.
        $css = $job->pageCss.($job->watermark ? "\n".PageStyles::WATERMARK : '');
        $protocols = $job->allowsNetwork() ? ['http', 'https', 'data'] : ['data'];
        if ($this->config->allowFileUrls) {
            $protocols[] = 'file';
        }

        $arguments = [
            $this->config->weasyprintBinary,
            '--encoding=utf-8',
            '--presentational-hints',
            '--media-type='.$job->media(),
            // An empty base URL keeps relative URLs unresolved instead of pointing them
            // at the working directory.
            '--base-url='.($job->baseUrl ?? ''),
            '--stylesheet=data:text/css;base64,'.base64_encode($css),
            '--timeout='.($options->httpTimeout ?? $this->config->fetchTimeout),
            '--allowed-protocols='.implode(',', $protocols),
        ];
        if (!$job->ignoreResourceErrors) {
            $arguments[] = '--fail-on-http-errors';
        }
        if (null !== $job->profile) {
            $arguments[] = '--pdf-variant='.$job->profile->variant;
            if ($job->profile->tagged) {
                $arguments[] = '--pdf-tags';
            }
        }
        if ($options->pdfForms) {
            $arguments[] = '--pdf-forms';
        }
        if ($options->noCompress) {
            $arguments[] = '--uncompressed-pdf';
        }
        if ($options->noSubsetFonts) {
            $arguments[] = '--full-fonts';
        }

        return $arguments;
    }

    /**
     * Every fetch goes through the egress proxy, which enforces the network policy and
     * adds the headers WeasyPrint's command line cannot send.
     *
     * The Prince layer is asked for with PLATEN_WEASYPRINT_PRINCE=1 rather than an argument:
     * stock WeasyPrint (PLATEN_WEASYPRINT_BINARY=weasyprint) ignores the variable, but would
     * reject an argument it does not know.
     *
     * @return array<string, string|false>
     */
    public function environment(RenderJob $job): array
    {
        $ticket = new ProxyTicket(
            userAgentSuffix: $job->userAgentToken,
            referrer: $job->referrer,
            authorization: $job->credentials?->header(),
            authorizationOrigin: null === $job->credentials ? null : $job->baseOrigin(),
        );
        $proxy = $ticket->proxyUrl($this->config->egressProxy);

        $environment = [];
        // Variables of the HTTP request being served must not leak into the renderer.
        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'HTTP_') || 'REQUEST_METHOD' === $name) {
                $environment[$name] = false;
            }
        }

        return [
            ...$environment,
            'http_proxy' => $proxy,
            'https_proxy' => $proxy,
            'HTTP_PROXY' => $proxy,
            'HTTPS_PROXY' => $proxy,
            'no_proxy' => false,
            'NO_PROXY' => false,
            'all_proxy' => false,
            'ALL_PROXY' => false,
            'PYTHONWARNINGS' => 'ignore',
            'PLATEN_WEASYPRINT_PRINCE' => $job->princeCompat ? '1' : false,
        ];
    }

    private static function failure(string $stderr): RenderException
    {
        if (str_contains($stderr, 'MemoryError')) {
            return new RenderException('The document needs more memory than this server allows');
        }
        preg_match_all('/^([A-Za-z_][\w.]*): (.+)$/m', $stderr, $matches, \PREG_SET_ORDER);
        $fatal = null;
        $cause = null;
        foreach ($matches as [, $type, $message]) {
            if (str_ends_with($type, 'FatalURLFetchingError')) {
                $fatal = $message;
            } elseif (null === $fatal && (str_ends_with($type, 'Error') || str_ends_with($type, 'Exception'))) {
                $cause = $message;
            }
        }
        if (null !== $fatal) {
            $detail = null === $cause ? '' : " ({$cause})";

            return new RenderException("{$fatal}{$detail} (set ignore_resource_errors to true to render anyway)");
        }
        $last = [] === $matches ? trim((string) strrchr("\n".trim($stderr), "\n")) : end($matches)[0];

        return new RenderException('Rendering failed: '.('' === $last ? 'WeasyPrint exited with an error' : $last));
    }
}
