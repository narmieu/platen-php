<?php

declare(strict_types=1);

namespace App\Api;

/**
 * A Content-Disposition header that keeps non-ASCII names (RFC 6266 filename*).
 */
final class ContentDisposition
{
    public static function inline(string $name): string
    {
        $filename = str_ends_with(strtolower($name), '.pdf') ? $name : "{$name}.pdf";
        $filename = preg_replace('/[\x00-\x1f\x7f]/u', '', $filename) ?? 'document.pdf';
        $fallback = str_replace(['"', '\\'], '', preg_replace('/[^\x20-\x7e]/', '', $filename) ?? '');
        if ('' === $fallback || '.pdf' === $fallback) {
            $fallback = 'document.pdf';
        }

        return \sprintf('inline; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($filename));
    }
}
