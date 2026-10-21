<?php

declare(strict_types=1);

namespace App\Rendering\Source;

use Dom\HTMLDocument as DomDocument;

/**
 * Tells whether an HTML document can run JavaScript, so it needs a browser.
 *
 * Uses the HTML parser instead of regular expressions, which hit PCRE backtrack limits on
 * multi-megabyte tags such as base64 data URIs. The parsed copy is discarded: the HTML that
 * gets rendered is never changed. Any doubt (parser failure, template contents, too many tags
 * to parse) means a browser.
 */
final class ScriptDetector
{
    /** Parsing costs memory per element (2.4 million tags take 400 MB): more go to a browser unparsed. */
    private const int MAX_PARSED_TAGS = 100_000;

    public static function needsBrowser(string $html): bool
    {
        // Linear substring checks: scripts, and templates (their content is not in the element tree).
        if (false !== stripos($html, '<script') || false !== stripos($html, '<template')) {
            return true;
        }
        // Linear too (possessive quantifiers): without anything like "onclick=" there is no
        // handler, and nothing to parse. A PCRE error (false) leaves it to the parser.
        if (0 === preg_match('/on[a-z]++\s*+=/i', $html)) {
            return false;
        }
        if (substr_count($html, '<') > self::MAX_PARSED_TAGS) {
            return true;
        }

        try {
            $dom = DomDocument::createFromString($html, \LIBXML_NOERROR);
            foreach ($dom->getElementsByTagName('*') as $element) {
                if ('script' === strtolower($element->localName)) {
                    return true;
                }
                foreach ($element->attributes as $attribute) {
                    if (1 === preg_match('/^on[a-z]+$/i', $attribute->name)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
