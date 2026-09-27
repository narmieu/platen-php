<?php

declare(strict_types=1);

namespace App\Rendering\Chromium;

use App\Rendering\Url;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Parsing\SourceException;
use Sabberworm\CSS\Property\Import;
use Sabberworm\CSS\RuleSet\AtRuleSet;
use Sabberworm\CSS\Value\URL as UrlValue;
use Sabberworm\CSS\Value\ValueList;

/**
 * What a stylesheet contributes to a page's web fonts: its @font-face rules and the
 * stylesheets it imports.
 */
final readonly class StyleSheetFonts
{
    /**
     * @param list<string>       $imports   absolute URLs of the stylesheets it imports
     * @param list<FontFaceRule> $fontFaces
     */
    private function __construct(
        public array $imports,
        public array $fontFaces,
    ) {
    }

    /**
     * Finds @import statements and @font-face rules without parsing the whole stylesheet,
     * which in PHP takes a second or more for a large one. Comments and strings match
     * too, so that what they contain is skipped.
     */
    private const string STATEMENTS = <<<'REGEX'
        ~
          /\*(?:[^*]++|\*(?!/))*+(?:\*/)?
        | "(?:[^"\\]++|\\.)*+" | '(?:[^'\\]++|\\.)*+'
        | (?<fontFace>@font-face\s*+\{(?:[^{}"'/]++|"(?:[^"\\]++|\\.)*+"|'(?:[^'\\]++|\\.)*+'|/\*(?:[^*]++|\*(?!/))*+\*/|/)*+\})
        | (?<import>@import\b(?:[^;{}"']++|"(?:[^"\\]++|\\.)*+"|'(?:[^'\\]++|\\.)*+')*+;)
        ~isx
        REGEX;

    /**
     * @param string|null $baseUrl where the stylesheet's relative URLs resolve: the page's
     *                             base URL for a <style> element, the stylesheet's own URL
     *                             for a linked one
     */
    public static function parse(string $css, ?string $baseUrl): self
    {
        if (!preg_match_all(self::STATEMENTS, $css, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL)) {
            return new self([], []);
        }
        $statements = [];
        foreach ($matches as $match) {
            // Chromium loads fonts embedded as data: URLs itself.
            if (null !== $match['import'] || (null !== $match['fontFace'] && 1 !== preg_match('/\bdata:/i', $match['fontFace']))) {
                $statements[] = $match['import'] ?? $match['fontFace'];
            }
        }
        try {
            $document = new Parser(implode("\n", $statements))->parse();
        } catch (SourceException) {
            return new self([], []);
        }

        $imports = [];
        foreach ($document->getContents() as $item) {
            $url = $item instanceof Import ? Url::resolve($item->getLocation()->getURL()->getString(), $baseUrl) : null;
            if (null !== $url) {
                $imports[] = $url;
            }
        }

        $fontFaces = [];
        foreach ($document->getAllRuleSets() as $ruleSet) {
            if (!$ruleSet instanceof AtRuleSet || 'font-face' !== strtolower($ruleSet->atRuleName())) {
                continue;
            }
            $sources = [];
            foreach ($ruleSet->getDeclarations() as $declaration) {
                if ('src' !== strtolower($declaration->getPropertyName())) {
                    continue;
                }
                foreach (self::urlValues($declaration->getValue()) as $value) {
                    $url = Url::resolve($value->getURL()->getString(), $baseUrl);
                    if (null !== $url) {
                        $sources[] = [$url, $value];
                    }
                }
            }
            if ([] !== $sources) {
                $fontFaces[] = new FontFaceRule($ruleSet, $sources);
            }
        }

        return new self($imports, $fontFaces);
    }

    /**
     * @return list<UrlValue> the url() values in a declaration's value, however deeply listed
     */
    private static function urlValues(mixed $value): array
    {
        if ($value instanceof UrlValue) {
            return [$value];
        }
        if (!$value instanceof ValueList) {
            return [];
        }

        return array_merge(...array_map(self::urlValues(...), $value->getListComponents()));
    }
}
