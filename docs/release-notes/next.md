# Next release

## Rendering changes (breaking)

<!-- One line per quality-layer change that alters the output of existing documents. -->
- Inline SVG now inherits the page font and resolves CSS custom properties (var()), like browsers.
- Prince syntax is now understood: flow: static() / content: flow() running elements and the full-width @top / @bottom page regions.
- Pages that ask for -prince-pdf-page-colorspace: cmyk get a CMYK transparency group, as in Prince.
- Default fonts: serif when no family matches; serif / sans-serif / monospace map to Liberation Serif / Sans / Mono (metric twins of Times New Roman, Arial, Courier New).
- line-height: normal now includes the font's line gap, and glyphs from fallback fonts no longer make lines taller (CSS 2.1, browsers).
- sup and sub inherit the paragraph's line-height and enlarge the line when they stick out (HTML user-agent stylesheet, CSS 2.1).
- Hyphenation picks the dictionary by language and script (Serbian Latin uses sr_Latn; "en" uses en_US); the hyphen is U+2010 only when the font has it, otherwise "-".
- @font-face weight matching follows CSS Fonts Level 4 instead of fontconfig's nearest weight; where that picks another face, glyphs from fallback fonts follow the picked weight too.
- @font-face accepts font-weight ranges (variable fonts), and unicode-range subsets of one weight each keep their own font in the PDF.
- Justified text may compress word spaces down to 5/6 of their width before wrapping (tighter justification, as Prince, InDesign and TeX do).
