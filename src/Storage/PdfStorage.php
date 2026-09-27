<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\PlatenConfig;

/**
 * Stored PDFs of hosted and asynchronous documents, one file per download id.
 */
final readonly class PdfStorage
{
    private string $directory;

    public function __construct(PlatenConfig $config)
    {
        $this->directory = $config->dataDir.'/files';
    }

    public function path(string $downloadId): string
    {
        return $this->directory.'/'.$downloadId.'.pdf';
    }

    public function exists(string $downloadId): bool
    {
        return is_file($this->path($downloadId));
    }

    /**
     * Moves a rendered file into storage.
     */
    public function store(string $file, string $downloadId): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Cannot create the directory {$this->directory}");
        }
        if (!rename($file, $this->path($downloadId))) {
            throw new \RuntimeException("Cannot store {$file}");
        }
    }

    public function delete(string $downloadId): void
    {
        @unlink($this->path($downloadId));
    }
}
