<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiException;
use App\Api\ApiKeyAuthenticator;
use App\Api\ContentDisposition;
use App\Api\PayloadReader;
use App\Api\StatusPayload;
use App\Config\PlatenConfig;
use App\Document\DocumentRequest;
use App\Document\DocumentService;
use App\Entity\DocumentKind;
use App\Repository\DocumentRepository;
use App\Storage\PdfStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DocRaptor's document API (https://docraptor.com/documentation/api).
 */
final readonly class DocumentController
{
    use JsonResponses;

    public function __construct(
        private PayloadReader $payloads,
        private ApiKeyAuthenticator $auth,
        private DocumentService $documents,
        private DocumentRepository $repository,
        private PdfStorage $storage,
        private PlatenConfig $config,
    ) {
    }

    /**
     * Synchronous by default; DocRaptor's older clients ask for async and hosted
     * documents here with the async and hosted flags.
     */
    #[Route('/docs', name: 'docs_create', methods: ['POST'])]
    #[Route('/docs.json', name: 'docs_create_json', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->handle($request, null);
    }

    #[Route('/async_docs', name: 'async_docs_create', methods: ['POST'])]
    public function createAsync(Request $request): Response
    {
        return $this->handle($request, DocumentKind::Async);
    }

    #[Route('/hosted_docs', name: 'hosted_docs_create', methods: ['POST'])]
    public function createHosted(Request $request): Response
    {
        return $this->handle($request, DocumentKind::Hosted);
    }

    #[Route('/hosted_async_docs', name: 'hosted_async_docs_create', methods: ['POST'])]
    public function createHostedAsync(Request $request): Response
    {
        return $this->handle($request, DocumentKind::HostedAsync);
    }

    #[Route('/status/{id}', name: 'status', methods: ['GET'])]
    public function status(Request $request, string $id): JsonResponse
    {
        $owner = $this->auth->owner($this->auth->keyFrom($request));
        $document = $this->repository->findByStatusId(self::withoutJsonSuffix($id));
        if (null === $document || $document->owner !== $owner || DocumentKind::Sync === $document->kind) {
            throw new ApiException(403, 'You do not have access to this document');
        }

        return self::json(StatusPayload::of($document, $this->baseUrl($request)));
    }

    /**
     * Like DocRaptor's, download URLs work without credentials: the id is an
     * unguessable token.
     */
    #[Route('/download/{id}', name: 'download', methods: ['GET'])]
    public function download(string $id): Response
    {
        $document = $this->documents->download($id);

        return self::pdfResponse($this->storage->path($id), $document->name, $document->numberOfPages());
    }

    #[Route('/expire/{id}', name: 'expire', methods: ['PATCH'])]
    public function expire(Request $request, string $id): Response
    {
        $owner = $this->auth->owner($this->auth->keyFrom($request));
        if (!$this->documents->expire(self::withoutJsonSuffix($id), $owner)) {
            throw new ApiException(403, 'You do not have access to this document');
        }

        return new Response('', 200);
    }

    #[Route('/docs', name: 'docs_list', methods: ['GET'])]
    #[Route('/docs.json', name: 'docs_list_json', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $owner = $this->auth->owner($this->auth->keyFrom($request));
        $page = filter_var($request->query->get('page', '1'), \FILTER_VALIDATE_INT);
        $perPage = filter_var($request->query->get('per_page', '100'), \FILTER_VALIDATE_INT);
        if (false === $page || false === $perPage) {
            throw ApiException::badRequest('page and per_page must be integers');
        }

        $documents = [];
        foreach ($this->repository->listForOwner($owner, max(1, $page), min(1000, max(1, $perPage))) as $document) {
            $entry = [
                'name' => $document->name,
                'tag' => $document->tag,
                'document_type' => $document->documentType,
                'test' => $document->test,
                'status' => $document->status()->value,
                'number_of_pages' => $document->numberOfPages(),
                'created_at' => $document->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'hosted' => $document->kind->isHosted(),
                'async' => $document->kind->isAsync(),
            ];
            if (DocumentKind::Sync !== $document->kind) {
                $entry['status_id'] = $document->statusId;
            }
            $documents[] = $entry;
        }

        return self::json($documents);
    }

    private function handle(Request $request, ?DocumentKind $kind): Response
    {
        // A key in the header or query string is checked before the body is read.
        $key = $this->auth->keyFrom($request);
        $owner = null === $key ? null : $this->auth->owner($key);
        [$payload, $bodyKey] = $this->payloads->read($request);
        $owner ??= $this->auth->owner($bodyKey);

        $document = DocumentRequest::fromRequest($payload);
        $kind ??= DocumentKind::of($document->async, $document->hosted);
        $baseUrl = $this->baseUrl($request);

        return match ($kind) {
            DocumentKind::Sync => $this->pdf($owner, $document, $baseUrl),
            DocumentKind::Hosted => self::json(StatusPayload::of($this->documents->host($owner, $document, $baseUrl), $baseUrl)),
            DocumentKind::Async, DocumentKind::HostedAsync => self::json([
                'status_id' => $this->documents->queue($owner, $document, $payload, $kind->isHosted(), $baseUrl)->statusId,
            ]),
        };
    }

    private function pdf(string $owner, DocumentRequest $document, string $baseUrl): Response
    {
        $result = $this->documents->render($owner, $document, $baseUrl);
        $response = self::pdfResponse($result->file, $document->name, $result->pages);
        $response->headers->set('X-Platen-Engine', $result->engine->value);

        return $response->deleteFileAfterSend();
    }

    private static function pdfResponse(string $file, string $name, ?int $pages): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ContentDisposition::inline($name),
            'X-DocRaptor-Num-Pages' => (string) $pages,
        ], autoEtag: false, autoLastModified: false);
        // BinaryFileResponse marks files public; documents are anything but.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Where the client reached this server, for download links.
     */
    private function baseUrl(Request $request): string
    {
        return $this->config->publicUrl ?? $request->getSchemeAndHttpHost().$request->getBaseUrl();
    }

    private static function withoutJsonSuffix(string $id): string
    {
        return str_ends_with($id, '.json') ? substr($id, 0, -5) : $id;
    }
}
