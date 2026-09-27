<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * A private temporary directory for the files of one render, removed with the object.
 */
final class Workspace
{
    private bool $removed = false;

    private function __construct(public readonly string $path)
    {
    }

    public function __destruct()
    {
        $this->remove();
    }

    public static function create(string $root): self
    {
        if (!is_dir($root) && !@mkdir($root, 0o700, true) && !is_dir($root)) {
            throw new \RuntimeException("Cannot create the directory {$root}");
        }
        $path = $root.'/render-'.bin2hex(random_bytes(8));
        if (!mkdir($path, 0o700)) {
            throw new \RuntimeException("Cannot create the directory {$path}");
        }

        return new self($path);
    }

    public function file(string $name): string
    {
        return $this->path.'/'.$name;
    }

    public function write(string $name, string $contents): string
    {
        $file = $this->file($name);
        if (false === file_put_contents($file, $contents)) {
            throw new \RuntimeException("Cannot write {$file}");
        }
        chmod($file, 0o600);

        return $file;
    }

    public function remove(): void
    {
        if ($this->removed) {
            return;
        }
        $this->removed = true;
        foreach (@scandir($this->path) ?: [] as $name) {
            if ('.' !== $name && '..' !== $name) {
                @unlink($this->path.'/'.$name);
            }
        }
        @rmdir($this->path);
    }
}
