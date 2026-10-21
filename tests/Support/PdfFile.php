<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;

/**
 * What the end-to-end tests check in a rendered PDF, read with qpdf and pdfparser.
 */
final class PdfFile
{
    private readonly string $path;

    /** @var array<mixed> qpdf's JSON: pages, encryption and every object */
    private readonly array $json;

    private function __construct(string $bytes, private readonly ?string $password)
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'platen-test-');
        file_put_contents($this->path, $bytes);
        $json = json_decode(self::qpdf([
            ...$this->passwordOption(),
            '--json=2', '--json-key=pages', '--json-key=encrypt', '--json-key=qpdf', '--json-stream-data=none',
            $this->path,
        ]), true, 512, \JSON_THROW_ON_ERROR);
        $this->json = \is_array($json) ? $json : [];
    }

    public function __destruct()
    {
        @unlink($this->path);
    }

    public static function fromBytes(string $bytes, ?string $password = null): self
    {
        return new self($bytes, $password);
    }

    public static function requiresPassword(string $bytes): bool
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'platen-test-');
        file_put_contents($path, $bytes);
        try {
            // qpdf exits with 0 when a password is needed, 2 when unencrypted, 3 when none is needed.
            $process = new Process(['qpdf', '--requires-password', $path]);

            return 0 === $process->run();
        } finally {
            @unlink($path);
        }
    }

    /**
     * The text of every page, with whitespace collapsed.
     */
    public function text(): string
    {
        return implode(' ', $this->pageTexts());
    }

    /**
     * @return list<string>
     */
    public function pageTexts(): array
    {
        $path = $this->path;
        if ($this->isEncrypted()) {
            $path = $this->path.'.decrypted';
            self::qpdf([...$this->passwordOption(), '--decrypt', $this->path, $path]);
        }
        try {
            $texts = [];
            foreach (new Parser()->parseFile($path)->getPages() as $page) {
                // pdfparser prints the empty strings of spaced-out text (letter-spacing) as "<>".
                $texts[] = trim((string) preg_replace('/\s+/u', ' ', str_replace('<>', '', $page->getText())));
            }

            return $texts;
        } finally {
            if ($path !== $this->path) {
                @unlink($path);
            }
        }
    }

    public function pageCount(): int
    {
        return \count($this->pages());
    }

    /**
     * @return array{int, int} width and height in points
     */
    public function pageSize(int $index = 0): array
    {
        $page = $this->object(self::string($this->pages()[$index]['object'] ?? null));
        $box = $page['/MediaBox'] ?? $this->object(self::string($page['/Parent'] ?? null))['/MediaBox'] ?? null;
        if (!\is_array($box) || 4 !== \count($box)) {
            throw new \RuntimeException('The page has no media box');
        }
        [$left, $bottom, $right, $top] = array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, array_values($box));

        return [(int) round($right - $left), (int) round($top - $bottom)];
    }

    public function imageCount(): int
    {
        return array_sum(array_map(static fn (array $page): int => \is_array($page['images'] ?? null) ? \count($page['images']) : 0, $this->pages()));
    }

    /**
     * @return list<string> the PostScript names of the embedded fonts, without subset tags
     */
    public function fontNames(): array
    {
        $names = [];
        foreach ($this->objects() as $object) {
            $value = \is_array($object) ? ($object['value'] ?? null) : null;
            if (\is_array($value) && '/Font' === ($value['/Type'] ?? null) && \is_string($value['/BaseFont'] ?? null)) {
                $names[] = (string) preg_replace('/^\/([A-Z]{6}\+)?/', '', $value['/BaseFont']);
            }
        }

        return array_values(array_unique($names));
    }

    public function title(): ?string
    {
        $info = $this->object(self::string($this->trailer()['/Info'] ?? null));
        $title = $info['/Title'] ?? null;
        if (!\is_string($title)) {
            return null;
        }
        if (str_starts_with($title, 'u:')) {
            return substr($title, 2);
        }
        $bytes = str_starts_with($title, 'b:') ? (string) hex2bin(substr($title, 2)) : $title;

        return str_starts_with($bytes, "\xFE\xFF") ? mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE') : $bytes;
    }

    public function isEncrypted(): bool
    {
        return true === ($this->encryption()['encrypted'] ?? false);
    }

    public function encryptionBits(): int
    {
        $parameters = $this->encryption()['parameters'] ?? null;
        $bits = \is_array($parameters) ? ($parameters['bits'] ?? 0) : 0;

        return \is_int($bits) ? $bits : 0;
    }

    /**
     * Whether a permission holds for someone who opens the file with the user password,
     * e.g. "printhigh" or "extract".
     */
    public function allows(string $capability): bool
    {
        $capabilities = $this->encryption()['capabilities'] ?? null;

        return \is_array($capabilities) && true === ($capabilities[$capability] ?? null);
    }

    /**
     * The PDF/A part and conformance level from the XMP metadata, e.g. "3B".
     */
    public function pdfaConformance(): ?string
    {
        $metadata = self::string($this->root()['/Metadata'] ?? null);
        if (1 !== preg_match('/^(\d+) (\d+) R$/', $metadata, $reference)) {
            return null;
        }
        $xmp = self::qpdf([...$this->passwordOption(), "--show-object={$reference[1]},{$reference[2]}", '--filtered-stream-data', $this->path]);
        $part = preg_match('/pdfaid:part(?:>|=")(\d)/', $xmp, $match) ? $match[1] : null;
        $conformance = preg_match('/pdfaid:conformance(?:>|=")([A-Za-z])/', $xmp, $match) ? strtoupper($match[1]) : '';

        return null === $part ? null : $part.$conformance;
    }

    /**
     * @return string|null the colour space of the page's transparency group (e.g. "/DeviceRGB"), null without one
     */
    public function pageGroupColorSpace(int $index = 0): ?string
    {
        $page = $this->object(self::string($this->pages()[$index]['object'] ?? null));
        $group = $page['/Group'] ?? null;
        if (\is_string($group)) {
            $group = $this->object($group);
        }
        $colorSpace = \is_array($group) ? ($group['/CS'] ?? null) : null;
        if (\is_array($colorSpace)) {
            $colorSpace = $colorSpace[0] ?? null;
        }

        return \is_string($colorSpace) ? $colorSpace : null;
    }

    public function hasStructureTree(): bool
    {
        return isset($this->root()['/StructTreeRoot']);
    }

    /**
     * @return list<array<mixed>>
     */
    private function pages(): array
    {
        $pages = $this->json['pages'] ?? null;

        return \is_array($pages) ? array_values(array_filter($pages, \is_array(...))) : [];
    }

    /**
     * @return array<mixed>
     */
    private function encryption(): array
    {
        $encryption = $this->json['encrypt'] ?? null;

        return \is_array($encryption) ? $encryption : [];
    }

    /**
     * @return array<mixed>
     */
    private function trailer(): array
    {
        return $this->value('trailer');
    }

    /**
     * @return array<mixed>
     */
    private function root(): array
    {
        return $this->object(self::string($this->trailer()['/Root'] ?? null));
    }

    /**
     * @return array<mixed>
     */
    private function object(string $reference): array
    {
        return '' === $reference ? [] : $this->value("obj:{$reference}");
    }

    /**
     * @return array<mixed>
     */
    private function value(string $key): array
    {
        $object = $this->objects()[$key] ?? null;
        $value = \is_array($object) ? ($object['value'] ?? null) : null;

        return \is_array($value) ? $value : [];
    }

    /**
     * @return array<mixed> every object and the trailer, by key ("obj:5 0 R", "trailer")
     */
    private function objects(): array
    {
        $sections = $this->json['qpdf'] ?? null;

        return \is_array($sections) && \is_array($sections[1] ?? null) ? $sections[1] : [];
    }

    /**
     * @return list<string>
     */
    private function passwordOption(): array
    {
        return null === $this->password ? [] : ["--password={$this->password}"];
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @param list<string> $arguments
     */
    private static function qpdf(array $arguments): string
    {
        $process = new Process(['qpdf', ...$arguments]);
        $process->run();
        // Exit code 3 means warnings only.
        if (!\in_array($process->getExitCode(), [0, 3], true)) {
            throw new \RuntimeException('qpdf failed: '.$process->getErrorOutput());
        }

        return $process->getOutput();
    }
}
