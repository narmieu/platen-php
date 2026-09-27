<?php

declare(strict_types=1);

namespace App\Document;

use App\Api\ApiException;

/**
 * One document request, in any shape DocRaptor clients send: the JSON body of the
 * official v4 clients, the legacy {"doc": {...}} wrapper or form-encoded doc[...] fields.
 */
final readonly class DocumentRequest
{
    public function __construct(
        public string $name,
        public string $documentType,
        public ?string $documentContent,
        public ?string $documentUrl,
        public bool $test,
        public ?string $pipeline,
        public bool $strictHtml,
        public bool $ignoreResourceErrors,
        public bool $ignoreConsoleMessages,
        public ?string $tag,
        public bool $javascript,
        public ?string $referrer,
        public ?string $callbackUrl,
        public ?int $hostedDownloadLimit,
        public ?\DateTimeImmutable $hostedExpiresAt,
        public ?string $userAgentToken,
        public PrinceOptions $princeOptions,
        /** Legacy flags of POST /docs. */
        public bool $async = false,
        public bool $hosted = false,
    ) {
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public static function fromRequest(array $raw): self
    {
        $string = static fn (string $key): ?string => Coerce::string($raw[$key] ?? null, $key);
        $bool = static fn (string $key, bool $default): bool => Coerce::bool($raw[$key] ?? null, $key) ?? $default;

        $documentType = strtolower(trim($string('document_type') ?? $string('type') ?? 'pdf'));
        if (!\in_array($documentType, ['pdf', 'xls', 'xlsx'], true)) {
            throw ApiException::badRequest('document_type must be one of pdf, xls, xlsx');
        }

        $content = $string('document_content');
        $url = trim($string('document_url') ?? '');
        $url = '' === $url ? null : $url;
        if (null === $content && null === $url) {
            throw ApiException::badRequest('You must supply document_content or document_url');
        }
        if (null === $content && null !== $url) {
            Coerce::httpUrl($url, 'document_url');
        }

        $strict = strtolower(trim($string('strict') ?? 'none'));
        if (!\in_array($strict, ['none', 'html'], true)) {
            throw ApiException::badRequest('strict must be none or html');
        }

        $callbackUrl = $string('callback_url');
        if (null !== $callbackUrl && '' !== $callbackUrl) {
            Coerce::httpUrl($callbackUrl, 'callback_url');
        }

        // Both are sent as request headers.
        foreach (['referrer', 'user_agent_token'] as $key) {
            if (1 === preg_match('/[\x00-\x1f\x7f]/', $string($key) ?? '')) {
                throw ApiException::badRequest("{$key} must not contain control characters");
            }
        }

        $downloadLimit = Coerce::int($raw['hosted_download_limit'] ?? null, 'hosted_download_limit');
        if (null !== $downloadLimit && $downloadLimit < 1) {
            throw ApiException::badRequest('hosted_download_limit must be at least 1');
        }

        return new self(
            name: trim($string('name') ?? '') ?: 'document',
            documentType: $documentType,
            documentContent: $content,
            documentUrl: null === $content ? $url : null,
            test: $bool('test', true),
            pipeline: $string('pipeline'),
            strictHtml: 'html' === $strict,
            ignoreResourceErrors: $bool('ignore_resource_errors', true),
            ignoreConsoleMessages: $bool('ignore_console_messages', false),
            tag: $string('tag'),
            javascript: $bool('javascript', false),
            referrer: $string('referrer') ?: null,
            callbackUrl: $callbackUrl ?: null,
            hostedDownloadLimit: $downloadLimit,
            hostedExpiresAt: Coerce::dateTime($raw['hosted_expires_at'] ?? null, 'hosted_expires_at'),
            userAgentToken: $string('user_agent_token') ?: null,
            princeOptions: PrinceOptions::fromRequest($raw['prince_options'] ?? null),
            async: $bool('async', false),
            hosted: $bool('hosted', false),
        );
    }

    public function runsJavaScript(): bool
    {
        return $this->javascript || $this->princeOptions->javascript;
    }
}
