<?php

declare(strict_types=1);

namespace App\Rendering\Source;

/**
 * Tells whether an HTML document can run JavaScript, so it needs a browser.
 */
final class ScriptDetector
{
    public static function needsBrowser(string $html): bool
    {
        return 1 === preg_match('/<script\b/i', $html)
            || 1 === preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $html);
    }
}
