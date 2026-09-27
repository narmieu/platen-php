<?php

declare(strict_types=1);

namespace App\Rendering\Chromium;

/**
 * What the page hook observed, read back from the PDF's title.
 */
final readonly class PageReport
{
    /**
     * @param list<string> $console
     * @param list<string> $failed
     */
    public function __construct(
        public ?string $title,
        public array $console,
        public array $failed,
        public ?string $timeout,
        public ?string $error,
    ) {
    }

    public static function fromTitle(?string $title, string $marker): ?self
    {
        if (null === $title || !str_starts_with($title, $marker)) {
            return null;
        }
        $data = json_decode(substr($title, \strlen($marker)), true);
        if (!\is_array($data)) {
            return null;
        }
        $strings = static fn (mixed $list): array => \is_array($list) ? array_values(array_filter($list, is_string(...))) : [];
        $string = static fn (mixed $value): ?string => \is_string($value) && '' !== $value ? $value : null;

        return new self(
            $string($data['title'] ?? null),
            $strings($data['console'] ?? null),
            $strings($data['failed'] ?? null),
            $string($data['timeout'] ?? null),
            $string($data['error'] ?? null),
        );
    }
}
