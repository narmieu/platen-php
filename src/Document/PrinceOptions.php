<?php

declare(strict_types=1);

namespace App\Document;

use App\Api\ApiException;

/**
 * DocRaptor's prince_options. Options platen cannot honour are accepted and ignored,
 * as the README documents.
 */
final readonly class PrinceOptions
{
    /** @var array<string, 'bool'|'int'|'string'> */
    private const array TYPES = [
        'baseurl' => 'string',
        'no_xinclude' => 'bool',
        'no_network' => 'bool',
        'no_parallel_downloads' => 'bool',
        'http_user' => 'string',
        'http_password' => 'string',
        'http_proxy' => 'string',
        'http_timeout' => 'int',
        'insecure' => 'bool',
        'media' => 'string',
        'no_author_style' => 'bool',
        'no_default_style' => 'bool',
        'no_embed_fonts' => 'bool',
        'no_subset_fonts' => 'bool',
        'no_compress' => 'bool',
        'encrypt' => 'bool',
        'key_bits' => 'int',
        'user_password' => 'string',
        'owner_password' => 'string',
        'disallow_print' => 'bool',
        'disallow_copy' => 'bool',
        'disallow_annotate' => 'bool',
        'disallow_modify' => 'bool',
        'debug' => 'bool',
        'input' => 'string',
        'version' => 'string',
        'javascript' => 'bool',
        'css_dpi' => 'int',
        'profile' => 'string',
        'pdf_title' => 'string',
        'iframes' => 'bool',
        'page_margin' => 'string',
        'pdf_forms' => 'bool',
    ];

    public function __construct(
        public ?string $baseUrl = null,
        public bool $noNetwork = false,
        public ?string $httpUser = null,
        public ?string $httpPassword = null,
        public ?string $httpProxy = null,
        public ?int $httpTimeout = null,
        public bool $insecure = false,
        public string $media = 'print',
        public bool $noSubsetFonts = false,
        public bool $noCompress = false,
        public bool $encrypt = false,
        public int $keyBits = 128,
        public ?string $userPassword = null,
        public ?string $ownerPassword = null,
        public bool $disallowPrint = false,
        public bool $disallowCopy = false,
        public bool $disallowAnnotate = false,
        public bool $disallowModify = false,
        public bool $javascript = false,
        public ?string $profile = null,
        public ?string $pdfTitle = null,
        public ?string $pageMargin = null,
        public bool $pdfForms = false,
    ) {
    }

    public static function fromRequest(mixed $raw): self
    {
        if (null === $raw || '' === $raw) {
            return new self();
        }
        if (!\is_array($raw) || (array_is_list($raw) && [] !== $raw)) {
            throw ApiException::badRequest('prince_options must be an object');
        }

        // Every known option is type-checked, including the ones platen ignores.
        $values = [];
        foreach (self::TYPES as $key => $type) {
            $name = "prince_options.{$key}";
            $values[$key] = match ($type) {
                'bool' => Coerce::bool($raw[$key] ?? null, $name),
                'int' => Coerce::int($raw[$key] ?? null, $name),
                'string' => Coerce::string($raw[$key] ?? null, $name),
            };
        }
        $bool = static fn (string $key): bool => true === ($values[$key] ?? null);
        $int = static fn (string $key): ?int => \is_int($values[$key] ?? null) ? $values[$key] : null;
        $string = static fn (string $key): ?string => \is_string($values[$key] ?? null) ? $values[$key] : null;
        // Blank strings count as unset, except for passwords.
        $text = static fn (string $key): ?string => '' === trim($string($key) ?? '') ? null : $string($key);

        $keyBits = $int('key_bits') ?? 128;
        if (!\in_array($keyBits, [40, 128], true)) {
            throw ApiException::badRequest('prince_options.key_bits must be 40 or 128');
        }
        $media = strtolower(trim($string('media') ?? ''));
        $baseUrl = trim($string('baseurl') ?? '');

        return new self(
            baseUrl: '' === $baseUrl ? null : $baseUrl,
            noNetwork: $bool('no_network'),
            httpUser: $text('http_user'),
            httpPassword: $string('http_password'),
            httpProxy: $text('http_proxy'),
            httpTimeout: $int('http_timeout'),
            insecure: $bool('insecure'),
            media: \in_array($media, ['print', 'screen'], true) ? $media : 'print',
            noSubsetFonts: $bool('no_subset_fonts'),
            noCompress: $bool('no_compress'),
            encrypt: $bool('encrypt'),
            keyBits: $keyBits,
            userPassword: $string('user_password'),
            ownerPassword: $text('owner_password'),
            disallowPrint: $bool('disallow_print'),
            disallowCopy: $bool('disallow_copy'),
            disallowAnnotate: $bool('disallow_annotate'),
            disallowModify: $bool('disallow_modify'),
            javascript: $bool('javascript'),
            profile: $text('profile'),
            pdfTitle: $string('pdf_title'),
            pageMargin: $text('page_margin'),
            pdfForms: $bool('pdf_forms'),
        );
    }
}
