<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Api\ApiException;

/**
 * A Prince PDF profile (e.g. "PDF/A-3b"), mapped to a WeasyPrint PDF variant.
 */
final readonly class PdfProfile
{
    private const array VARIANTS = [
        'pdf/a-1b', 'pdf/a-2b', 'pdf/a-3b', 'pdf/a-2u', 'pdf/a-3u', 'pdf/a-4u',
        'pdf/a-1a', 'pdf/a-2a', 'pdf/a-3a', 'pdf/a-4e', 'pdf/a-4f',
        'pdf/ua-1', 'pdf/ua-2', 'pdf/x-1a', 'pdf/x-3', 'pdf/x-4', 'pdf/x-5g',
    ];

    private function __construct(
        public string $variant,
        public bool $tagged,
    ) {
    }

    /**
     * Combined profiles such as "PDF/A-3a+PDF/UA-1" use the PDF/A part and turn on tagging.
     *
     * @throws ApiException when the profile is not supported
     */
    public static function fromPrince(?string $profile): ?self
    {
        if (null === $profile || '' === trim($profile)) {
            return null;
        }
        $variant = null;
        $tagged = false;
        foreach (explode('+', $profile) as $part) {
            $part = strtolower(explode(':', trim($part))[0]);
            if (str_starts_with($part, 'pdf/ua')) {
                $tagged = true;
            }
            if (\in_array($part, self::VARIANTS, true) && (null === $variant || str_starts_with($variant, 'pdf/ua'))) {
                $variant = $part;
            }
        }
        if (null === $variant) {
            throw ApiException::badRequest("Unsupported PDF profile: {$profile}");
        }

        return new self($variant, $tagged);
    }

    /**
     * PDF/A and PDF/X forbid encryption.
     */
    public function forbidsEncryption(): bool
    {
        return str_starts_with($this->variant, 'pdf/a') || str_starts_with($this->variant, 'pdf/x');
    }
}
