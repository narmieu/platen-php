<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiKeyAuthenticator;
use App\Platen;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    use JsonResponses;

    public function __construct(private ApiKeyAuthenticator $auth)
    {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return self::json([
            'name' => 'platen',
            'version' => Platen::VERSION,
            'description' => 'Self-hosted HTML to PDF server with a DocRaptor-compatible API',
            'authentication' => $this->auth->enabled() ? 'enabled' : 'disabled',
        ]);
    }

    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return self::json(['status' => 'ok', 'version' => Platen::VERSION]);
    }
}
