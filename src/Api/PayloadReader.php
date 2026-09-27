<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\PlatenConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the document parameters from JSON, form-encoded or multipart bodies.
 */
final readonly class PayloadReader
{
    public function __construct(private PlatenConfig $config)
    {
    }

    /**
     * @return array{array<array-key, mixed>, ?string} the document parameters and any user_credentials in the body
     */
    public function read(Request $request): array
    {
        $declared = (int) $request->headers->get('Content-Length', '0');
        if ($declared > $this->config->maxRequestBytes()) {
            throw new ApiException(413, 'The request body is too large');
        }

        $data = match ($request->getContentTypeFormat()) {
            'form' => $request->request->all(),
            default => $this->decodeJson($request->getContent()),
        };
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')) {
            $data = $request->request->all();
        }

        return self::split($data);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJson(string $body): array
    {
        if ('' === trim($body)) {
            throw ApiException::badRequest('The request body is empty');
        }
        if (\strlen($body) > $this->config->maxRequestBytes()) {
            throw new ApiException(413, 'The request body is too large');
        }
        try {
            $decoded = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::badRequest('The request body must be JSON or form-encoded parameters');
        }
        if (!$decoded instanceof \stdClass) {
            throw ApiException::badRequest('The request body must be a JSON object');
        }

        return (array) json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Unwraps the legacy {"doc": {...}, "user_credentials": "..."} shape.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array{array<array-key, mixed>, ?string}
     */
    private static function split(array $data): array
    {
        $doc = $data;
        $credentials = $data['user_credentials'] ?? null;
        if (\is_array($data['doc'] ?? null)) {
            $doc = $data['doc'];
            $credentials ??= $doc['user_credentials'] ?? null;
        }

        return [$doc, \is_scalar($credentials) && '' !== (string) $credentials ? (string) $credentials : null];
    }
}
