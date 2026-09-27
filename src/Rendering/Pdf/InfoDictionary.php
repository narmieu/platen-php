<?php

declare(strict_types=1);

namespace App\Rendering\Pdf;

/**
 * A PDF's document information dictionary, in qpdf's JSON form ("u:" text strings).
 */
final readonly class InfoDictionary
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public string $reference,
        public array $values,
        public int $maxObjectId,
    ) {
    }

    public function title(): ?string
    {
        return self::text($this->values['/Title'] ?? null);
    }

    public function withTitle(?string $title): self
    {
        $values = $this->values;
        unset($values['/Title']);
        if (null !== $title && '' !== $title) {
            $values['/Title'] = 'u:'.$title;
        }

        return new self($this->reference, $values, $this->maxObjectId);
    }

    /**
     * The document for `qpdf --update-from-json` that replaces this dictionary.
     */
    public function toUpdateJson(): string
    {
        return json_encode([
            'qpdf' => [
                [
                    'jsonversion' => 2,
                    'pushedinheritedpageresources' => false,
                    'calledgetallpages' => false,
                    'maxobjectid' => $this->maxObjectId,
                ],
                ["obj:{$this->reference}" => ['value' => (object) $this->values]],
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        if (str_starts_with($value, 'u:')) {
            return substr($value, 2);
        }
        if (str_starts_with($value, 'b:')) {
            $hex = substr($value, 2);
            // hex2bin() warns about anything but pairs of hex digits.
            $bytes = ctype_xdigit($hex) && 0 === \strlen($hex) % 2 ? hex2bin($hex) : false;

            return false === $bytes ? null : mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
        }

        return null;
    }
}
