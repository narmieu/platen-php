<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * A rendered PDF in a temporary file, which the caller moves or deletes.
 */
final readonly class RenderResult
{
    public function __construct(
        public string $file,
        public int $pages,
        public Engine $engine,
    ) {
    }

    public function size(): int
    {
        return (int) filesize($this->file);
    }

    public function discard(): void
    {
        @unlink($this->file);
    }
}
