<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Api\ApiException;
use App\Config\PlatenConfig;
use App\Document\DocumentRequest;
use App\Rendering\Pdf\Encryption;
use App\Rendering\Pdf\Qpdf;
use App\Rendering\Source\DocumentFetcher;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Picks an engine for a document request, runs it and post-processes the PDF.
 */
final readonly class Renderer
{
    /** @var array<string, RenderEngine> by Engine value */
    private array $engines;

    /**
     * @param iterable<RenderEngine> $engines
     */
    public function __construct(
        private PlatenConfig $config,
        private DocumentFetcher $fetcher,
        private Qpdf $qpdf,
        private StrictHtmlValidator $validator,
        #[AutowireIterator(RenderEngine::class)]
        iterable $engines,
    ) {
        $byName = [];
        foreach ($engines as $engine) {
            $byName[$engine->engine()->value] = $engine;
        }
        $this->engines = $byName;
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

        $choice = EngineChoice::for($request, $this->config);
        $engine = $this->engines[$choice->engine->value] ?? throw new \LogicException("No RenderEngine for {$choice->engine->value}");
        $profile = PdfProfile::fromPrince($options->profile);
        if (null !== $profile && !$engine->supportsPdfProfiles()) {
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
            princeCompat: $choice->princeCompat,
        );

        $workspace = Workspace::create($this->config->dataDir.'/tmp');
        $output = $engine->render($job, $workspace);

        $pages = $this->qpdf->pageCount($output->pdf);
        $pdf = $output->pdf;
        if (null !== $output->info || null !== $encryption || $output->decompress) {
            $pdf = $workspace->file('final.pdf');
            $this->qpdf->rewrite($output->pdf, $pdf, $workspace, $output->info, $encryption, $output->decompress);
        }

        $result = \dirname($workspace->path).'/'.basename($workspace->path).'.pdf';
        rename($pdf, $result);

        return new RenderResult($result, $pages, $engine->engine());
    }

    /**
     * Headers for downloading document_url, the same the engine sends for its assets.
     *
     * @return array<string, string>
     */
    private function documentHeaders(RenderEngine $engine, DocumentRequest $request, ?Credentials $credentials): array
    {
        $headers = ['User-Agent' => $engine->userAgent($request->userAgentToken)];
        if (null !== $request->referrer) {
            $headers['Referer'] = $request->referrer;
        }
        if (null !== $credentials) {
            $headers['Authorization'] = $credentials->header();
        }

        return $headers;
    }
}
