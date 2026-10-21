# Next release

## Rendering changes (breaking)

<!-- One line per quality-layer change that alters the output of existing documents. -->
- Inline SVG now inherits the page font and resolves CSS custom properties (var()), like browsers.
- Prince syntax is now understood: flow: static() / content: flow() running elements and the full-width @top / @bottom page regions.
- Pages that ask for -prince-pdf-page-colorspace: cmyk get a CMYK transparency group, as in Prince.
- Default fonts: serif when no family matches; serif / sans-serif / monospace map to Liberation Serif / Sans / Mono (metric twins of Times New Roman, Arial, Courier New).
