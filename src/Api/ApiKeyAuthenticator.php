<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\PlatenConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * DocRaptor's two schemes: the API key as the HTTP Basic username (blank password), or
 * a user_credentials parameter in the query string or the body.
 */
final readonly class ApiKeyAuthenticator
{
    public function __construct(private PlatenConfig $config)
    {
    }

    public function enabled(): bool
    {
        return [] !== $this->config->apiKeys;
    }

    /**
     * The key sent in the Authorization header or the query string, if any.
     */
    public function keyFrom(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (0 === strncasecmp($header, 'basic ', 6)) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if (false === $decoded || !mb_check_encoding($decoded, 'UTF-8')) {
                throw new ApiException(401, 'Malformed Authorization header');
            }
            $user = explode(':', $decoded, 2)[0];
            if ('' !== $user) {
                return $user;
            }
        }
        $query = $request->query->all()['user_credentials'] ?? null;

        return \is_string($query) && '' !== $query ? $query : null;
    }

    /**
     * An opaque owner id for the key: documents belong to the key that created them.
     *
     * @throws ApiException 401 when keys are configured and the key is missing or wrong
     */
    public function owner(?string $key): string
    {
        if ($this->enabled()) {
            if (null === $key || '' === $key) {
                throw new ApiException(401, 'Missing API key');
            }
            $valid = false;
            foreach ($this->config->apiKeys as $candidate) {
                $valid = hash_equals($candidate, $key) || $valid;
            }
            if (!$valid) {
                throw new ApiException(401, 'Invalid API key');
            }
        }

        return hash('sha256', $key ?? '');
    }
}
