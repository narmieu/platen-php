# Next release

WeasyPrint now runs through `weasyprint-platen` (`docker/weasyprint`), which adds two layers
of fixes: a quality layer on every WeasyPrint render, and a Prince layer for requests that
send a DocRaptor pipeline number (such as `"10"` or `"10.1"`). Requests without a pipeline
number never get Prince-layer behaviour.

## Rendering changes (breaking)

<!-- One line per quality-layer change that alters the output of existing documents. -->
- Inline SVG now inherits the page font and resolves CSS custom properties (var()) in style attributes, presentation attributes and SVG <style> rules, like browsers.
- Prince syntax is now understood: flow: static() / content: flow() running elements and the full-width @top / @bottom page regions.
- Pages that use transparency and whose @page asks for -prince-pdf-page-colorspace: cmyk get a CMYK transparency group, as in Prince (colours are not converted; opaque pages get no group).
- Default fonts: serif when no family matches; serif / sans-serif / monospace map to Liberation Serif / Sans / Mono (metric twins of Times New Roman, Arial, Courier New).
- line-height: normal now includes the font's line gap, and glyphs from fallback fonts no longer make lines taller (CSS 2.1, browsers).
- sup and sub inherit the paragraph's line-height and enlarge the line when they stick out (HTML user-agent stylesheet, CSS 2.1).
- Hyphenation picks the dictionary by language and script (Serbian Latin uses sr_Latn; "en" uses en_US); the hyphen is U+2010 only when the font has it, otherwise "-".
- @font-face weight matching follows CSS Fonts Level 4 instead of fontconfig's nearest weight; where that picks another face, glyphs from fallback fonts follow the picked weight too.
- @font-face accepts font-weight ranges (variable fonts), and unicode-range subsets of one weight each keep their own font in the PDF.
- Justified text may compress word spaces down to 5/6 of their width before wrapping (tighter justification, as Prince, InDesign and TeX do).

## Prince compatibility

For requests with a DocRaptor pipeline number, while `PLATEN_PRINCE_COMPAT` is `true` (the
default):

- `javascript: true` documents (DocRaptor's clients always send it) render with WeasyPrint when their `document_content` has no `<script>`, `<template>` or `on…` event-handler attribute, so running headers and footers survive; documents with scripts and `document_url` documents still go to Chromium.
- Pages that use transparency and do not ask for CMYK get an RGB transparency group, and colours with equal red, green and blue are written as DeviceGray, as Prince writes them.
- @font-face weight matching follows CSS Fonts Level 3, as Prince does: for 400 and 500 the other of the two comes first, so italic 500 text of a family with faces 200 and 700 uses the 700 face.
- vertical-align: super raises by half the ascender, and sub lowers by half the descender, of the parent's font times the parent's font size; sub and sup are 83% of the parent's size; font-size: smaller divides by 1.2 and larger multiplies by 1.2.
- Hyphenation uses Prince's dictionaries (da, de, en-US, es, fi, fr, is, it, lt, pl, pt, ru, sl, sv, th); every other language, sr included, gets en-US. The hyphen is always "-" unless the author sets hyphenate-character.
- List markers have no trailing space and sit a fixed 8pt from the content at every font size (mirrored for right-to-left text); marker strings the author wrote keep their spaces.

## Turning it off

- `PLATEN_PRINCE_COMPAT=false` turns the Prince layer off for every request (the quality layer stays).
- `PLATEN_WEASYPRINT_BINARY=weasyprint` runs stock WeasyPrint, without either layer. The Docker image sets `weasyprint-platen`; elsewhere the default stays `weasyprint`.

## Requirements

- `weasyprint-platen` supports exactly WeasyPrint 70.0, pydyf 0.13.0 and pyphen 0.18.1; with other versions every render fails with an error naming them. The Docker image has them.
