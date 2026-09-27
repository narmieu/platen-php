<?php

declare(strict_types=1);

namespace App\Rendering\Chromium;

use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\RuleSet\AtRuleSet;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Value\URL as UrlValue;

/**
 * One @font-face rule of a stylesheet and the font files its src descriptor points to.
 */
final readonly class FontFaceRule
{
    /**
     * @param list<array{string, UrlValue}> $sources each absolute URL with the value that names it
     */
    public function __construct(
        private AtRuleSet $rule,
        private array $sources,
    ) {
    }

    /**
     * @return list<string> absolute URLs, in the order the rule lists them
     */
    public function urls(): array
    {
        return array_values(array_unique(array_map(static fn (array $source): string => $source[0], $this->sources)));
    }

    /**
     * The same rule with some of its font files replaced by data: URLs. It keeps every
     * other descriptor, so it describes the same face and, coming later, wins. Its other
     * URLs become absolute, as the rule moves from its stylesheet into the page.
     *
     * @param array<string, string> $dataUrls data: URLs by the absolute URL they replace
     *
     * @return string|null the rule as CSS, or null when none of its fonts is replaced
     */
    public function withDataUrls(array $dataUrls): ?string
    {
        if ([] === array_intersect_key($dataUrls, array_flip($this->urls()))) {
            return null;
        }

        $originals = [];
        foreach ($this->sources as [$url, $value]) {
            $originals[] = [$value, $value->getURL()];
            $value->setURL(new CSSString($dataUrls[$url] ?? $url));
        }
        try {
            return $this->rule->render(OutputFormat::createCompact());
        } finally {
            foreach ($originals as [$value, $original]) {
                $value->setURL($original);
            }
        }
    }
}
