<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Api\ApiException;
use App\Config\PlatenConfig;
use App\Document\DocumentRequest;
use App\Rendering\Chromium\ChromiumEngine;
use App\Rendering\Pdf\Encryption;
use App\Rendering\Pdf\Qpdf;
use App\Rendering\Source\DocumentFetcher;
use App\Rendering\WeasyPrint\WeasyPrintEngine;

/**
 * Picks an engine for a document request, runs it and post-processes the PDF.
 */
final readonly class Renderer
{
    public function __construct(
        private PlatenConfig $config,
        private DocumentFetcher $fetcher,
        private WeasyPrintEngine $weasyPrint,
        private ChromiumEngine $chromium,
        private Qpdf $qpdf,
        private StrictHtmlValidator $validator,
    ) {
    }

    /**
     * @throws ApiException when the document cannot be rendered
     */
    public function render(DocumentRequest $request, float $timeout): RenderResult
    {
        $deadline = Deadline::in($timeout);
        $options = $request->princeOptions;
        if ('pdf' !== $request->documentType) {
            throw ApiException::badRequest("document_type {$request->documentType} is not supported yet; use pdf");
        }

        // Only a browser runs JavaScript, so JavaScript documents always use Chromium.
        $engine = $request->runsJavaScript() ? Engine::Chromium : Engine::fromPipeline($request->pipeline, $this->config->defaultEngine);
        $profile = PdfProfile::fromPrince($options->profile);
        if (null !== $profile && Engine::Chromium === $engine) {
            $message = 'PDF profiles (PDF/A, PDF/UA, PDF/X) need the weasyprint pipeline';
            throw ApiException::badRequest($request->runsJavaScript() ? "{$message}, which does not run JavaScript" : $message);
        }
        $encryption = Encryption::fromPrince($options);
        if (null !== $encryption && true === $profile?->forbidsEncryption()) {
            throw ApiException::badRequest('PDF/A and PDF/X documents cannot be encrypted');
        }
        $pageCss = PageStyles::page($options->pageMargin);
        if ($request->strictHtml && null !== $request->documentContent) {
            $this->validator->validate($request->documentContent);
        }

        $credentials = null === $options->httpUser ? null : new Credentials($options->httpUser, $options->httpPassword ?? '');
        $html = $request->documentContent;
        $baseUrl = $options->baseUrl;
        if (null === $html) {
            $document = $this->fetcher->fetch(
                (string) $request->documentUrl,
                $this->documentHeaders($engine, $request, $credentials),
                !$options->insecure,
                $options->httpTimeout,
                $deadline,
            );
            $html = $document->html;
            $baseUrl ??= $document->url;
        }

        $job = new RenderJob(
            html: $html,
            baseUrl: $baseUrl,
            pageCss: $pageCss,
            watermark: $request->test && $this->config->testWatermark,
            javascript: $request->runsJavaScript(),
            options: $options,
            profile: $profile,
            ignoreResourceErrors: $request->ignoreResourceErrors,
            ignoreConsoleMessages: $request->ignoreConsoleMessages,
            userAgentToken: $request->userAgentToken,
            referrer: $request->referrer,
            credentials: $credentials,
            deadline: $deadline,
        );

        $workspace = Workspace::create($this->config->dataDir.'/tmp');
        $output = match ($engine) {
            Engine::WeasyPrint => $this->weasyPrint->render($job, $workspace),
            Engine::Chromium => $this->chromium->render($job, $workspace),
        };

        $pages = $this->qpdf->pageCount($output->pdf);
        $pdf = $output->pdf;
        $decompress = Engine::Chromium === $engine && $options->noCompress;
        if (null !== $output->info || null !== $encryption || $decompress) {
            $pdf = $workspace->file('final.pdf');
            $this->qpdf->rewrite($output->pdf, $pdf, $workspace, $output->info, $encryption, $decompress);
        }

        $result = \dirname($workspace->path).'/'.basename($workspace->path).'.pdf';
        rename($pdf, $result);

        return new RenderResult($result, $pages, $engine);
    }

    /**
     * Headers for downloading document_url, the same the engine sends for its assets.
     *
     * @return array<string, string>
     */
    private function documentHeaders(Engine $engine, DocumentRequest $request, ?Credentials $credentials): array
    {
        $headers = [
            'User-Agent' => Engine::Chromium === $engine
                ? ChromiumEngine::userAgent($request->userAgentToken)
                : trim('WeasyPrint (platen) '.($request->userAgentToken ?? '')),
        ];
        if (null !== $request->referrer) {
            $headers['Referer'] = $request->referrer;
        }
        if (null !== $credentials) {
            $headers['Authorization'] = $credentials->header();
        }

        return $headers;
    }
}
