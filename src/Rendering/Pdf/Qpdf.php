<?php

declare(strict_types=1);

namespace App\Rendering\Pdf;

use App\Config\PlatenConfig;
use App\Rendering\Workspace;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * PDF post-processing with the qpdf command line tool.
 */
final readonly class Qpdf
{
    private const float TIMEOUT = 120.0;

    public function __construct(private PlatenConfig $config)
    {
    }

    public function pageCount(string $pdf): int
    {
        // qpdf exits with 3 for warnings; the count on stdout is still valid.
        $output = $this->run([$pdf], ['--show-npages'], okCodes: [0, 3]);

        return (int) trim($output);
    }

    public function info(string $pdf): ?InfoDictionary
    {
        [$objects, $maxObjectId] = $this->json($pdf, ['trailer', '1']);
        $reference = self::value($objects, 'trailer')['/Info'] ?? null;
        if (!\is_string($reference) || 1 !== preg_match('/^(\d+) (\d+) R$/', $reference, $match)) {
            return null;
        }
        // Chromium writes the dictionary as object 1, so one call usually finds both.
        if (!isset($objects["obj:{$reference}"])) {
            [$objects, $maxObjectId] = $this->json($pdf, ["{$match[1]},{$match[2]}"]);
        }
        $values = self::value($objects, "obj:{$reference}");

        return null === $values ? null : new InfoDictionary($reference, $values, $maxObjectId);
    }

    /**
     * Writes a copy of $in to $out with a new information dictionary, encryption and/or
     * uncompressed streams.
     */
    public function rewrite(string $in, string $out, Workspace $workspace, ?InfoDictionary $info = null, ?Encryption $encryption = null, bool $decompress = false): void
    {
        $options = [];
        if (null !== $info) {
            $update = $workspace->write('info.json', $info->toUpdateJson());
            $options[] = "--update-from-json={$update}";
        }
        if ($decompress) {
            array_push($options, '--stream-data=uncompress', '--object-streams=disable');
        }
        if (null !== $encryption) {
            // Passed in an argument file so the passwords stay out of the process list.
            $options[] = '@'.$workspace->write('encryption.args', implode("\n", $encryption->arguments())."\n");
        }
        $this->run([$in, $out], $options, okCodes: [0, 3]);
    }

    /**
     * @param list<string> $objects
     *
     * @return array{array<mixed>, int} the objects keyed as qpdf names them, and the highest object number
     */
    private function json(string $pdf, array $objects): array
    {
        $options = ['--json=2', '--json-key=qpdf'];
        foreach ($objects as $object) {
            $options[] = "--json-object={$object}";
        }
        $decoded = json_decode($this->run([$pdf], $options, okCodes: [0, 3]), true, 64, \JSON_THROW_ON_ERROR);
        $sections = \is_array($decoded) && \is_array($decoded['qpdf'] ?? null) ? $decoded['qpdf'] : [];
        $header = \is_array($sections[0] ?? null) ? $sections[0] : [];
        $maxObjectId = $header['maxobjectid'] ?? 0;

        return [\is_array($sections[1] ?? null) ? $sections[1] : [], \is_int($maxObjectId) ? $maxObjectId : 0];
    }

    /**
     * The value of a dictionary object, e.g. "trailer" or "obj:5 0 R".
     *
     * @param array<mixed> $objects
     *
     * @return array<string, mixed>|null
     */
    private static function value(array $objects, string $key): ?array
    {
        $object = $objects[$key] ?? null;
        $value = \is_array($object) ? ($object['value'] ?? null) : null;
        if (!\is_array($value)) {
            return null;
        }
        $dictionary = [];
        foreach ($value as $name => $item) {
            $dictionary[(string) $name] = $item;
        }

        return $dictionary;
    }

    /**
     * @param list<string> $files
     * @param list<string> $options
     * @param list<int>    $okCodes
     */
    private function run(array $files, array $options, array $okCodes): string
    {
        $process = new Process([$this->config->qpdfBinary, ...$options, ...$files]);
        $process->setTimeout(self::TIMEOUT);
        $process->run();
        if (!\in_array($process->getExitCode(), $okCodes, true)) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }
}
