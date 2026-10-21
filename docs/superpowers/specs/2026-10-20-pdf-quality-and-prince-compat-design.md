# Quality layer and Prince compatibility for platen's WeasyPrint engine

Date: 2026-10-20 · Status: approved (two-layer split, §4) · Target: platen `main` (WeasyPrint 70.0)

## 1. Intent

The end goal is a very good PDF library. This work gets there in two layers:

- a **quality layer**, on for every request: fixes where WeasyPrint 70.0 departs from the CSS
  specifications or from what browsers do (inline SVG, line metrics, superscripts, fonts,
  hyphenation, Prince syntax that harms nobody);
- a **Prince layer**, on only for requests that carry a DocRaptor pipeline number: Prince
  15.1's own choices where they differ from the specs, so DocRaptor documents render like
  DocRaptor.

platen promises "point DocRaptor's client at it and your existing code keeps working". For
documents written against DocRaptor (Prince 15.1) that promise does not hold yet: the career
assessment certificates of `career-assessment-api` render with no running headers or footers,
extra pages, a black chart, and — once the templates are rewritten by hand — still drift by up
to 12.5mm, show different colours, superscripts, bullets, justification and hyphenation.

This work adds a **Prince compatibility mode** to the WeasyPrint engine so that such documents
render like Prince does, **without changing the documents and without changing the client
beyond its host**. Every behaviour below was measured against DocRaptor (Prince 15.1) and
against platen (WeasyPrint 70.0) with purpose-built calibration documents; the rules are
stated as formulas with test vectors, not as guesses.

## 2. Goals, non-goals, anti-goals

Goals
- Every request renders closer to the CSS specifications and to browsers (quality layer).
- The six career-assessment parity scenarios (§9) meet the acceptance thresholds with the
  **original, unmodified templates** and the **unchanged client** (`javascript: true`,
  `pipeline: "10"`, `prince_options.media: print`), only the host pointing at platen.
- Each emulated Prince behaviour is exact to the measured rule (formulas in §5), so other
  DocRaptor documents benefit too.

Non-goals
- Prince's JavaScript APIs, `prince-script()`, footnote/sidenote regions, Prince-only CSS not
  listed in §5.
- Converting page content to CMYK (Prince does not do that either, see F3).
- Changing the Chromium engine (other than routing, F1).

Anti-goals (a failure even if parity numbers look good)
- Prince quirks leaking into requests without a DocRaptor pipeline number (no `pipeline`,
  `pipeline: "weasyprint"`, `pipeline: "chromium"`). Those requests get the quality layer
  only.
- A quality-layer change that is not backed by a CSS specification or by browser behaviour.
- Shipping the quality layer silently: it changes output for existing platen users, so it
  is a breaking release with release notes listing each behaviour change.
- A fork of WeasyPrint. Patches live in platen and are pinned to the WeasyPrint version the
  image installs; a version mismatch must fail loudly, never half-apply.
- Committing `career-assessment-api` templates, logos, texts or Adobe Fonts kit IDs to the
  public platen repository. Parity fixtures with client content stay private (§8.3).

## 3. Baseline today (measured)

Six scenarios (2 × IdentyFi, 2 × WayFi, 2 × BasicCheck) built from the app's real
`HtmlResultGenerator` with synthetic data covering every template branch. Same HTML sent to
DocRaptor (public test key, test mode) and platen.

| Path | Result |
|---|---|
| platen as-is (client sends `javascript: true` → Chromium) | no running headers/footers; cover logos at the top; IdentyFi 7→8 pages, WayFi 5→8 (blank pages); page size 209.89×297.01mm |
| platen, WeasyPrint, original templates | no running headers/footers (`flow: static()`, `@top`/`@bottom` ignored); IdentyFi 7→8, BasicCheck 4→5 pages; bell-curve SVG solid black |
| platen, WeasyPrint, templates hand-converted to standard CSS | WayFi/BasicCheck text-identical; IdentyFi words drift ≤12.5mm, 39 words move to the next page; BasicCheck cover 3.17mm higher; colours brighter; bullets 2.09mm off |

The hand conversion was a diagnostic only. This design makes it unnecessary.

## 4. Architecture

All of the following are **design calls made during analysis; challenge any of them**.

4.0 Two layers (approved)

| Layer | Applies to | Contents (§5) |
|---|---|---|
| Quality | every WeasyPrint render | F2 Prince syntax accepted; F3a honour `-prince-pdf-page-colorspace`; F4a CSS Fonts 4 weight matching; F5 generic and default fonts; F6 line metrics; F7a `sup`/`sub` inherit line-height, CSS 2.1 inline boxes; F8 justification may shrink spaces; F9a hyphenation dictionary per language and script, U+2010 only when the font has it; F11 inline SVG |
| Prince | `pipeline` is a DocRaptor pipeline number | F1 routing; F3b RGB page group everywhere, neutral colours as DeviceGray; F4b CSS Fonts 3 weight matching; F7b Prince's raise/drop, `sup`/`sub` 83%, `smaller`/`larger` ÷/×1.2; F9b English patterns for languages Prince lacks, hyphen always `-`; F10 list markers |

Where both layers define the same behaviour (F4, F9) the Prince layer wins when active.

4.1 Compat switch (PHP)
- `RenderJob` gains `bool $princeCompat`. `Renderer::render()` sets it to true only when
  `pipeline` is a DocRaptor pipeline number (`^\d+(\.\d+)*$`, e.g. `"10"`, `"10.1"`) — the
  case DocRaptor maps to a Prince version. Requests without a pipeline, or with `weasyprint`
  / `chromium`, get the quality layer only (anti-goal 1). Decided by the owner (§12);
  `career-assessment-api` always sends a number (`DocRaptorPdfClient::extractPipeline()`
  defaults to `"10"`).
- Requests that do send a pipeline number change behaviour on upgrade: call that out in the
  changelog and README.
- Kill switch: env `PLATEN_PRINCE_COMPAT` (default `true`). When `false`, every request gets
  the quality layer only.
- The WeasyPrint binary becomes platen's wrapper (§4.2) for every render; it receives
  `--platen-prince` when `$job->princeCompat`. All existing CLI arguments stay the same.

4.2 Patch packages (Python)
- New Python package in the repo, e.g. `docker/weasyprint/platen_weasyprint/` with two
  subpackages, `quality` and `prince`, installed into `/opt/weasyprint` by the Dockerfile,
  plus an entry script `weasyprint-platen` that strips `--platen-prince` from `argv`, applies
  `quality` (always) and `prince` (when the flag was given), then runs
  `weasyprint.__main__.main()`. `PLATEN_WEASYPRINT_BINARY` defaults to it.
- `apply()` asserts `weasyprint.__version__ == "70.0"` (and the pydyf/pyphen versions it
  relies on) and raises otherwise. Each fix is one module with an `apply()`; each
  monkeypatches the functions named in §5. Patches are process-global, which is fine: platen
  runs one WeasyPrint process per document.
- F5 is data: one fontconfig file for every render (quality), copied into the image.
- Escape hatch for operators: `PLATEN_WEASYPRINT_BINARY=weasyprint` restores stock
  WeasyPrint (no layers at all).

4.3 Order of work (suggested)
Quality layer first (it benefits every user and the Prince layer builds on it):
F11 → F2 → F3a → F5 → F6 → F7a → F9a → F4a → F8; then the Prince layer:
F1 → F3b → F4b → F7b → F9b → F10. F8 (justification) is the hardest.

4.4 Browser oracle for the quality layer
platen already runs Chromium (Gotenberg). Quality-layer tests can render the same fixture
through the Chromium engine and assert that WeasyPrint now agrees where the behaviour is
browser-defined (SVG fonts and `var()`, `line-height: normal`, superscript line growth,
fallback glyphs), within a tolerance.

## 5. Fixes

Each fix: what Prince does (measured), what WeasyPrint 70.0 does, where to patch, how to test.
Paths are inside the WeasyPrint 70.0 package (`/opt/weasyprint/lib/python3.13/site-packages/weasyprint/`)
unless they start with `src/` (platen PHP).

### F1. Engine routing for `javascript: true` (Prince layer)

- DocRaptor: `javascript: true` turns on Prince's JavaScript; layout is still Prince.
- platen: `src/Rendering/Renderer.php:43` sends every JavaScript request to Chromium, which
  cannot do running elements, so the certificates lose headers and footers.
- Change: use Chromium only when the document actually needs a browser:
  `runsJavaScript()` **and** the HTML contains a `<script` element (case-insensitive) or an
  `on…=` event-handler attribute. Otherwise WeasyPrint (with compat, per §4.1).
- Scope (design call): this rerouting happens only for requests that qualify for compat mode
  (§4.1). Every other request keeps today's routing, including `pipeline: "weasyprint"` +
  `javascript: true`, which stays on Chromium. The README must say that compat requests with
  `javascript: true` and no script render with WeasyPrint.
- `document_url` requests: the HTML is fetched after the engine is chosen today (the fetch
  headers depend on the engine). Either fetch first with WeasyPrint headers and re-decide, or
  keep today's behaviour for `document_url` + `javascript`; builder's call, document it.
- Test: E2E — `javascript: true` + no script → WeasyPrint (check PDF producer
  `WeasyPrint`); with `<script>` → Chromium.

### F2. Prince-only CSS: static flows and page regions (quality layer)

Accepted for every request: documents that do not use this syntax are unaffected.

Prince input used by the templates:
`#header { flow: static(header) }`, `@page { @top { content: flow(header) } @bottom { content: flow(footer) } }`.

- Translate declarations (patch `css/validation/__init__.py:114 preprocess_declarations`,
  or the tokens before it):
  - `flow: static(<ident>)` and `-prince-flow: static(<ident>)` → `position: running(<ident>)`.
  - `content: flow(<ident>)` → `content: element(<ident>)` (same `first` semantics observed:
    the first running element assigned on a page wins, else the last one assigned before).
- Page regions (patch `css/__init__.py:1664`, where unknown margin rules are rejected, and
  `layout/page.py:355 make_margin_boxes`):
  - Accept `@top`, `@bottom` (and, untested, `@left`, `@right`).
  - Measured Prince geometry, page A4 with margins 20mm (top/bottom) and 30mm (left/right):
    `@top` border box spans x 30.0–180.0mm, y 0.0–20.0mm; `@bottom` x 30.0–180.0mm,
    y 277.0–297.0mm. That is: horizontally from the left margin edge across the whole page
    border width (`page.border_width()`, already computed at `layout/page.py:413`), vertically
    the whole margin. Content is centred like `@top-center` by default. Free fonts
    (re-measured 2026-10-21, `layout`): the same rectangles, in Prince and platen. The
    region's own padding is inside that span (the templates rely on this: `padding-left:
    15mm; padding-right: 18mm` inside a 210mm region with 0 side margins).
  - Implement as a box that takes the full span instead of the three-box distribution of
    `compute_variable_dimension`. If both `@top` and `@top-left/center/right` exist on a page,
    pick a rule and document it (not observed in the fixtures).
- Tests: running header/footer present on every page of a 3-page fixture; region geometry
  vector above (fill `#ff0000`, measure the filled rectangle).

### F3. Page colour space and neutral colours

Layers: **F3a (quality)** recognise `-prince-pdf-page-colorspace` and give pages that ask for
`cmyk` the CMYK group (the author asked for it). **F3b (Prince)** add the RGB group to every
other page and write neutral colours as DeviceGray.

Measured Prince output (same document, two named pages):
- Every page gets `/Group <</S /Transparency /CS /DeviceRGB>>`.
- Pages whose `@page` style has `-prince-pdf-page-colorspace: cmyk` get
  `/Group <</S /Transparency /CS /DeviceCMYK>>` instead. Named pages are honoured per page.
- Colours with r = g = b are written as DeviceGray (`0 g`, `0.5020 g`, `1.0000 g`); others as
  `rg` unchanged. Content is **not** converted to CMYK.
- On screen, the CMYK group dulls colours: CSS `#053384` shows as `#253a7f`, `#b7cef9` as
  `#bbcdea`, black text (`0 g`) as `#231f20`.
- Free fonts (re-measured 2026-10-21, `layout`): of 8 pages only the two with transparency
  (`rgba(0,0,0,0.5)`) have a `/Group`: `/DeviceCMYK` on the `cmyk` page, `/DeviceRGB` on the
  other; the six opaque pages have none (the "every page" above was measured on pages that
  all had transparency). Neutrals `0 g`, `0.5020 g`, `1.0000 g`, others `rg`. platen with the
  Prince layer writes the same groups and operators (with more decimals: `0.501961 g`).

WeasyPrint 70.0: no page group; every colour `r g b rg`.

- Recognise the property: register `-prince-pdf-page-colorspace` (`auto | rgb | cmyk`,
  initial `auto`) as a valid page property so it survives cascade into the page style.
- Page group: patch the page dictionary built at `pdf/__init__.py:203`
  (`pydyf.Dictionary({'Type': '/Page', …})`) to add `Group` from that page's computed style.
- Neutral colours: patch `pdf/stream.py:90 Stream.set_color` so an sRGB colour with equal
  channels emits `g`/`G` (fill/stroke) instead of `rg`/`RG`. Keep the `set_alpha` behaviour.
- Verified by post-processing a WeasyPrint PDF this way: the dark cell renders `#253a7f`, text
  `#231f20`, IdentyFi pixel diff 0.04–0.34% on 6 of 7 pages (1.05% on the seventh), mean
  absolute pixel difference on page 3 2.58 → 1.10.
- Tests: page `Group` per page incl. a named CMYK page; operator set of a page with
  `#000`, `#808080`, `#fff`, `#ff0000`, `#053384`.

### F4. Font weight matching for `@font-face` families

Layers: **F4a (quality)** implement the current spec, CSS Fonts Level 4 §5.2: desired 400–500
inclusive: weights ≥ desired ascending up to 500, then < desired descending, then > 500
ascending; desired < 400: ≤ desired descending, then above ascending; desired > 500: ≥ desired
ascending, then below descending. **F4b (Prince)** the Level 3 rule below. They differ for
400/500 requests, e.g. italic {200, 700} at 500: Level 4 → 200, Prince → 700.

Prince implements **CSS Fonts Level 3** weight matching (§5.2 step 4). All 54 measured cases
fit it (3 Adobe Fonts kits × weights 100–900 × normal/italic):

- desired < 400: available weights below desired, descending, then above, ascending;
- desired > 500: above, ascending, then below, descending;
- desired 400: 500 first, then the < 400 rule; desired 500: 400 first, then the > 500 rule.

WeasyPrint registers each `@font-face` with fontconfig (`text/fonts.py:114 add_font_face`,
weight at `:206`) and fontconfig picks the "closest" weight on its non-linear scale. That
differs where the rules disagree. Measured case that matters here: italic faces {200, 700},
request 500 → Prince 700, WeasyPrint 200 (IdentyFi `.dimension-value`).

- Patch: keep a registry `family → style → {weights}` filled in `add_font_face`; where the
  Pango font description gets its weight (`text/fonts.py:337
  pango_font_description_set_weight`) map the requested weight through the CSS3 algorithm
  when the family is in the registry. Leave system fonts to fontconfig.
- Test vectors (face sets → Prince's choice for 100…900):
  - normal {200,300,400,500,700,900}: 100→200, 600→700, 800→900, others exact.
  - normal {200,300,500,700}: 100→200, 400→500, 600→700, 800→700, 900→700.
  - normal {200,400,500,700}: 100→200, 300→200, 600→700, 800→700, 900→700.
  - italic {200,700}: 100,300,400→200; 500,600,800,900→700.
- Free fonts (re-measured 2026-10-21, `tools/prince-calibration` `weights_a`/`b`/`c`: each
  weight a different DejaVu / Liberation file renamed to one family): Prince picks the same
  face in all 54 cases above. A family without italic faces sets italic text in the
  normal faces with the same choices (`weights_b`, `weights_c`). platen with the Prince
  layer picks the same face in all 54 cases.

### F5. Generic families, default font, glyph fallback (fontconfig) (quality layer)

Browsers also default to a serif font and map the generics to Times New Roman, Arial and
Courier New (or their platform equivalents), so this mapping is the quality default.

Measured on DocRaptor vs platen today:

| Request | Prince (DocRaptor) | platen today |
|---|---|---|
| unknown family, or missing glyph with no generic in the stack | Times New Roman | Noto Sans |
| `serif` | Times New Roman | Noto Serif |
| `sans-serif` | Arial | Noto Sans |
| `monospace` | Courier New | DejaVu Sans Mono |
| `cursive`, `fantasy` | Comic Sans MS | Noto Sans |
| `system-ui` | Times New Roman | Noto Sans |
| `Arial` / `Times New Roman` / `Courier New` | themselves | Liberation Sans / Serif / Mono |
| `'Segoe UI'`, `Georgia`, `Verdana` | themselves | Noto Sans / Noto Serif / Noto Sans |

Free fonts (re-measured 2026-10-21, `families`): every row above repeats (`Helvetica` comes
out as an embedded `Helvetica-Bold`, `Times` and `Courier` as `Times-Roman` and `Courier`).
č ć š đ ž in `platen-test.woff` (printable ASCII only) fall back to Times New Roman with no
generic or with `serif`, Arial with `sans-serif`, Courier New with `monospace`, Segoe UI
with the system stack (`-apple-system, …, 'Segoe UI', Roboto, sans-serif`). platen with
the quality layer: Liberation Serif / Sans / Mono in the same places, Liberation Sans for
the system stack.

- Fontconfig file for every WeasyPrint render (§4.2):
  - `serif` → Liberation Serif, `sans-serif` → Liberation Sans, `monospace` → Liberation Mono
    (metric twins of Times New Roman, Arial, Courier New).
  - Families with no generic get `serif` appended (Prince's default), replacing the stock
    `49-sansserif.conf` behaviour.
  - Optional, unverified: Selawik for `Segoe UI` (Microsoft's metric-compatible fallback),
    Gelasio for `Georgia`. No free metric twin is known for Comic Sans MS or Verdana.
- Why it matters here: the IdentyFi kit has no č ć š đ ž (see §7); Prince sets those glyphs
  in Arial, so platen must set them in a font with Arial's advances (Liberation Sans).
- Test: per-family PostScript name of a rendered word; fallback face for `čćšđž` in a
  font without those glyphs, with and without a generic.

### F6. Line metrics: `normal`, content area, fallback glyphs (quality layer)

Browsers include the line gap in `normal` and size inline boxes from the first available
font, like Prince; WeasyPrint is the outlier.

Measured:

| Font (tables) | Prince `line-height: normal` | WeasyPrint today |
|---|---|---|
| DejaVu Sans (hhea 1901/−483/0, upm 2048, no USE_TYPO_METRICS) | 1.1641em | 1.1640em |
| Liberation Sans (hhea 1854/−434/67, typo 1491/−431/307, no USE_TYPO_METRICS) | 1.1499em | 1.1172em |
| FF Real Head Pro (hhea 1036/−353/0, typo 800/−200/389, USE_TYPO_METRICS) | 1.389em | 1.000em |
| DejaVu Sans with USE_TYPO_METRICS (typo 1556/−492/410, OS/2 version 4; free-font stand-in for FF Real Head Pro) | 1.2002em | 1.000em |

Free fonts (re-measured 2026-10-21, `sup2`, fonts embedded as `@font-face`): DejaVu Sans
1.1641em and Liberation Sans 1.1499em again; platen with the quality layer gives the same
three values.

Rule (fits all four): pick ascender A, descender D and line gap G from OS/2 typo when
`USE_TYPO_METRICS` is set, otherwise from hhea; `normal = A + D + G`. WeasyPrint/Pango drops G.

Prince also builds inline boxes per CSS 2.1 §10.8.1 with content area A + D (same table) and
half-leading split around it. WeasyPrint uses a 1em content area (`layout/inline.py:685
_adjust_line_height`: `box.height = font_size`) and Pango logical extents.

Fallback glyphs: in Prince a line's height does not change when some glyphs come from a
fallback font (IdentyFi: constant 12.8pt pitch at 8pt/1.6). In WeasyPrint lines containing
fallback glyphs grow to 13.2–13.4pt, which drifts text down the page and moves page breaks.
The inline box uses the element's first available font only.

- Patch points: `text/line_break.py:531 strut` (the `normal` branch and the baseline), `:13
  line_size` / `:29 first_line_metrics` (text box height and baseline from mixed-font Pango
  runs), `layout/inline.py:685 _adjust_line_height`. Read A/D/G from the primary font's
  tables through HarfBuzz (`pango_font_get_hb_font` → `hb_ot_metrics_get_position`, or the
  raw `hhea`/`OS/2` tables when you need hhea specifically).
- Tests: the three `normal` values above; a paragraph with one fallback glyph per line keeps
  the same pitch as one without.

### F7. `vertical-align: super/sub`, superscript size, `smaller`/`larger`

Layers: **F7a (quality)** `sup`/`sub` inherit line-height (drop WeasyPrint's UA
`line-height: normal`; the HTML specification's UA stylesheet has no such rule) and line
boxes follow CSS 2.1 with F6's content area, keeping WeasyPrint's raise/drop and sizes.
**F7b (Prince)** Prince's raise/drop, `sup`/`sub` at 83%, `smaller`/`larger` ÷/×1.2. The
vectors below are Prince's, i.e. F7a + F7b together.

Measured rules:
- Raise for `super` = 0.5 × A × parent font size; drop for `sub` = 0.5 × D × parent font size
  (A, D as in F6, of the **parent's** font). Independent of the sup's own size and of
  line-height. Vectors: FF Real Head Pro P=10/20/30 → raise 4.0/8.0/12.0, drop 1.0/2.0/3.0;
  Liberation Sans P=20 → raise 9.053; DejaVu Sans P=20 → raise 9.282. Free fonts
  (re-measured 2026-10-21, `sup2`): Liberation Sans P=10/20/30 → raise 4.526/9.053/13.579,
  drop 1.060/2.119/3.179, for every sup/sub size and line-height.
- Line box growth follows CSS 2.1 with F6's content area. For inline box of font size f,
  line-height L: top = baseline − (L + (a − d)·f)/2, bottom = baseline + (L − (a − d)·f)/2,
  with a = A/upm, d = D/upm, baseline shifted by the raise/drop. The line box is the
  min/max over the strut and all inline boxes.
- Default size of `sup`/`sub` is 83% (P=10 → 8.3, P=30 → 24.9). `font-size: smaller` is ÷1.2
  (10 → 8.33, 20 → 16.67); `larger` ×1.2 (10 → 12, 20 → 24).

WeasyPrint 70.0: raise/drop = ±0.5 × the element's own size (`css/computed_values.py:720`);
`smaller`/`larger` use a keyword table (`:579`), e.g. smaller 10 → 9.0, larger 10 → 10.67; and
line boxes mostly do not grow for raised boxes (0.00 where Prince grows 5.59). The main
reason is its UA stylesheet: `css/html5_ua.css:90 sub, sup { line-height: normal }` gives the
superscript a small inline box, while Prince's superscripts inherit the paragraph's
line-height (every vector below assumes inheritance). Also `:89 small, sub, sup { font-size:
smaller }` — Prince uses 83% for `sub`/`sup`.

Test vectors — growth above the line for `<sup>` (FF Real Head Pro, a = 0.8, d = 0.2;
P parent size, s sup size, values in pt; Prince = formula = measurement to ±0.01):

| P | s | fixed L (any) | L = 1.2 | L = 1.5 | normal |
|---|---|---|---|---|---|
| 10 | 8.3 | 3.49 | 2.47 | 2.21 | 2.31 |
| 10 | 6.6 | 2.98 | 0.94 | 0.43 | 0.62 |
| 10 | 5.0 | 2.50 | 0 | 0* | 0 |
| 20 | 16.6 | 6.98 | 4.94 | — | 4.62 |
| 20 | 13.2 | 5.96 | 1.88 | 0.86 | 1.24 |
| 30 | 24.9 | 10.47 | 7.41 | 6.65 | 6.93 |
| 30 | 19.8 | 8.94 | — | 1.29 | 1.86 |
| 30 | 20 (BasicCheck cover, L = 36pt) | 9.00 | | | |

`*` predicted by the formula, not measured (page break in the calibration document); `—` not
measured. Liberation Sans P=20, s=10, L=24pt: 5.59; DejaVu Sans: 5.82 (WeasyPrint: 0.00 for
both).
Subscript growth below, FF Real Head Pro: fixed L: 0.1P + 0.3(P − s) (P=10: 1.51 / 2.02 / 2.50
for s = 8.3 / 6.6 / 5.0); L = n × f: 0.1P − (P − s)(n − 0.6)/2, floored at 0.

Free fonts (re-measured 2026-10-21, `sup2`): growth above the line for `<sup>`, Liberation
Sans (a = 0.9053, d = 0.2119), same layout as the table above:

| P | s | fixed L (any) | L = 1.2 | L = 1.5 | normal |
|---|---|---|---|---|---|
| 10 | 8.3 | 3.94 | 2.92 | 2.66 | 2.96 |
| 10 | 6.6 | 3.35 | 1.31 | 0.80 | 1.39 |
| 10 | 5.0 | 2.79 | 0 | — | 0 |
| 20 | 16.6 | 7.87 | 5.83 | 5.32 | 5.92 |
| 20 | 13.2 | 6.70 | 2.62 | 1.60 | 2.79 |
| 20 | 10.0 | 5.59 | 0 | 0 | 0 |
| 30 | 24.9 | 11.81 | 8.75 | 7.99 | 8.88 |
| 30 | 19.8 | 10.04 | 3.92 | 2.39 | 4.18 |
| 30 | 15.0 | 8.38 | 0 | 0 | 0 |

`—` page break in the calibration document. Every value fits the formula: fixed L:
raise − (a − d)(P − s)/2; L = n × f: raise − (n + a − d)(P − s)/2, floored at 0, `normal`
as n = A + D + G (1.1499). Subscript growth below, Liberation Sans: fixed L: 0.5dP + (a −
d)(P − s)/2 (P=10: 1.65 / 2.24 / 2.79 for s = 8.3 / 6.6 / 5.0); L = n × f: 0.5dP − (P −
s)(n − (a − d))/2, floored at 0. Sizes: `sup`/`sub` 8.3 / 16.6 / 24.9 for P = 10 / 20 / 30;
`smaller` 8.333 / 16.667 and `larger` 12 / 24 for P = 10 / 20. platen with the Prince layer
gives every value above (±0.01pt).

- Patch points: `css/computed_values.py:720 vertical_align` (needs the parent style's font
  metrics; it can return a length), `:579` font-size keywords, the UA rules at
  `css/html5_ua.css:89-90` (compat: `sub, sup { font-size: 83% }`, no `line-height` rule), and
  the line box code in `layout/inline.py:987-1133` (`line_box_verticality`,
  `inline_box_verticality`) together with F6.

### F8. Justification: compressible word spaces (quality layer, design call)

On for every request: shrinking word spaces gives tighter justified text (Prince, InDesign
and TeX do it; browsers do not). If this proves unwelcome for browser-authored documents,
move it to the Prince layer; nothing else depends on the choice.

Measured with ten 22pt words in boxes narrowed in 0.05pt steps:
- Prince lets word spaces shrink to **5/6 of their natural width** (threshold between
  0.8316 and 0.8341 at 10pt, between 0.8268 and 0.8365 at 20pt), then breaks greedily
  (first fit). Free fonts (re-measured 2026-10-21, `justify`, ten 22.25pt words in
  Liberation Sans): between 0.8320 and 0.8340 at 10pt, between 0.8320 and 0.8400 at 20pt
  (0.1pt steps); platen the same.
- Non-last lines are then justified to the full width (stretched or compressed).
- A last line that fits only when compressed stays on one line, compressed exactly to fit
  (gaps 2.254…1.976pt in the vector); otherwise it keeps natural spacing.
- Check on real text: this rule reproduces Prince's line composition for 14 of 16 justified
  IdentyFi paragraphs exactly; the other paragraph (same in both scenarios) contains spaces set in the
  fallback font (wider), which the simulation did not model. That case disappears once the
  font kit is fixed (§7, a prerequisite); re-check all 16 after the references are
  re-rendered.

WeasyPrint 70.0 never compresses: `layout/inline.py:1135 text_align` returns early when the
line is full, `justify_line`/`add_word_spacing` (`:1172`, `:1190`) only distribute positive
space, and line splitting (`text/line_break.py:242 split_first_line` via `layout/inline.py:924
split_text_box`) measures at natural spacing.

- Approach (builder to refine): when the block is `text-align: justify`, split lines with the
  word spacing reduced by 1/6 of the space advance (`justification_spacing` is already
  threaded through `create_layout`/`split_first_line`), then in `text_align` justify every
  non-last line to the available width (negative extra allowed) and, for the last line,
  restore natural spacing unless that overflows, in which case compress to fit.
- Interplay with hyphenation (does Prince try compression before hyphenating?) is not
  isolated by the calibration; confirm with the IdentyFi annex fixture (§9).
- Tests: the threshold vector (line 1 keeps 10 words up to 3.45pt over, wraps at 3.50pt at
  10pt); the last-line vector; the 16 IdentyFi paragraphs.

### F9. Hyphenation dictionaries and hyphen glyph

Layers:
- **F9a (quality)** pick the dictionary by language **and script**: `sr` text in Latin script
  → pyphen `sr_Latn`, in Cyrillic → `sr`; `en` → `en_US`; otherwise pyphen's own fallback.
  Hyphen glyph U+2010 when the font rendering the line has it, else U+002D.
- **F9b (Prince)** languages outside Prince's list → `en_US` patterns; hyphen always U+002D.

- Prince ships patterns for da, de, en-US, es, fi, fr, is, it, lt, pl, pt, ru, sl, sv (and Thai
  via LibThai). Measured: `lang="sr"` and `lang="en"` both hyphenate with **en-US** patterns.
  So: language in that list → its patterns; anything else → en-US.
- WeasyPrint picks pyphen's dictionary by `lang` (`text/line_break.py:367
  pyphen.language_fallback`). `sr` → pyphen's Cyrillic `sr` dictionary, which never breaks
  Latin text; `en` → pyphen's `en` entry, whose breaks differ from Prince's
  (Prince `international-ization`, `representa-tive`; platen `internationaliz-ation`,
  `representat-ive`).
- Map as Prince does before calling pyphen. Verified: with en-US patterns and justification
  removed, IdentyFi text and every hyphen position match Prince (0.0mm).
- Hyphen glyph: Prince always inserts U+002D, even in fonts that have U+2010 (tested: a font
  without U+2010, Arial, DejaVu Sans). WeasyPrint's default is U+2010
  (`css/properties.py:165`); set it to `-`. In the IdentyFi kit U+2010 does not exist and
  falls back to another font, changing glyph and line height.
- Free fonts (re-measured 2026-10-21, `layout`, `lang="en"`): U+002D in Liberation Sans
  (has U+2010), Arial, DejaVu Sans and `platen-test.woff` (no U+2010); platen with the
  Prince layer breaks every line at the same place with the same hyphen.
- Tests: break positions for an en-US word list under `lang` = `sr`, `en`, `hr`, `de`;
  hyphen code point.

### F10. List markers (Prince layer)

- Prince: the marker text has no trailing space (`•`, `1.`); the gap between marker and
  content is a **fixed 8pt** at every font size (tested 8, 12, 16pt). Outside markers: the
  marker's right edge is 8pt left of the content box. Inside markers: marker at the content
  edge, then 8pt, then the text.
- Free fonts (re-measured 2026-10-21, `layout`, Liberation Sans): 8.00pt for `disc` outside
  and inside, 8.01–8.02pt for `decimal` at 8, 12, 16pt; platen with the Prince layer 8.00pt.
- WeasyPrint: marker string with a trailing space, gap ≈ 0.21em (1.70 / 2.54 / 3.39pt).
- Patch points: `formatting_structure/build.py:325 marker_to_box` (marker text) and the
  outside-marker placement at `layout/block.py:749` (and `layout/absolute.py:70`).
- Tests: marker x-extents vs content x for disc/decimal, inside/outside, 8/12/16pt.

### F11. Inline SVG (quality layer)

- Prince: inline `<svg>` inherits the HTML font (the bell-curve digits render in the page's
  font, bold per their attribute); CSS custom properties in SVG `style` attributes resolve
  (`style="fill: var(--blue-light)"` → `#B7CEF9`).
- WeasyPrint: inline SVG text falls back to the default font (Noto Sans Bold seen); `var()`
  in SVG styles is not resolved and the fill renders black.
- Patch point: `html.py:293 handle_svg`, before `SVGImage(element, …)`:
  - add the element's computed `font-family` (and `font-style`, `font-weight`,
    `font-size`) as presentation attributes on the root `<svg>` when absent;
  - substitute `var(--name[, fallback])` in `style` and presentation attributes of the
    subtree with `box.style['__name']` (custom properties are stored with `-` → `_`, see
    `css/__init__.py:626 resolve_var`), serialised with `tinycss2.serialize`.
- Tests: SVG text font name; rect fill colour from a `:root` variable.

## 6. Everything platen needs, as a checklist

1. `src/Rendering/Renderer.php` — F1 routing; compute `princeCompat` (§4.1).
2. `src/Rendering/RenderJob.php` — `princeCompat` flag.
3. `src/Rendering/WeasyPrint/WeasyPrintEngine.php` — pass `--platen-prince` when compat.
4. `src/Config/PlatenConfig.php`, `.env` — `PLATEN_PRINCE_COMPAT`; default
   `PLATEN_WEASYPRINT_BINARY=weasyprint-platen`.
5. `docker/weasyprint/platen_weasyprint/quality/` — F2, F3a, F4a, F6, F7a, F8, F9a, F11.
6. `docker/weasyprint/platen_weasyprint/prince/` — F3b, F4b, F7b, F9b, F10.
7. `docker/weasyprint/weasyprint-platen` — entry script.
8. `docker/fontconfig/platen.conf` — F5.
9. `Dockerfile` — install package, script and fontconfig file; optional Selawik/Gelasio.
10. Tests (§8), README sections "Rendering quality" and "Prince compatibility", release
    notes listing every quality-layer behaviour change.

## 7. Prerequisite outside platen: the IdentyFi font kit

The IdentyFi Adobe Fonts kit (loaded by `templates/results/base.html.twig` in
`career-assessment-api`; kit IDs are kept in that private repository) has 216 glyphs and
**no č ć š đ ž**. The BasicCheck (548 glyphs) and WayFi (549) kits have them. Production
IdentyFi PDFs therefore show every Serbian diacritic in Arial today.

Decided by the owner (§12): the Serbian characters are added to the kit in Adobe Fonts
**before** the parity sign-off. Consequences:
- the DocRaptor references for the parity suite are rendered after the kit change (the kit
  loads live, so references rendered before it are stale);
- the Prince quirk of setting some spaces in the fallback font (§10) is out of scope;
- F5/F6 fallback behaviour is still implemented for other documents, but is tested only by
  platen's own generic fixtures (§8.1), not by the parity suite.

## 8. Test plan

8.1 Unit and end-to-end tests in platen, one group per fix, using free fonts only (DejaVu,
Liberation, or the OFL test font in `tests/Support/fonts`). The vectors in §5 that were
measured with FF Real Head Pro must be re-measured with a free font before they go into the
public repo; the formulas are font-independent.

8.2 Calibration tooling (generic, to regenerate vectors against DocRaptor's public
test-mode key — free, watermarked): the generators, renderer and PDF comparator written for
this analysis are in `tools/prince-calibration/` (see its README).
Switch their `<link>` to free fonts before committing; they currently load the client's
Adobe Fonts kits.

8.3 Private parity suite, in `career-assessment-api` (private GitLab) under
`tools/pdf-parity/` (decided by the owner, §12): scenario builder, comparator and batch
runner are committed; the six scenario HTMLs and DocRaptor reference PDFs are generated on
demand into a git-ignored folder (WayFi files are 3–5MB each; DocRaptor's test mode with
`pipeline: "10"` is free and pins Prince 15.1). Six scenario HTMLs from the real
`HtmlResultGenerator`, DocRaptor reference PDFs, and the comparator. Masks DocRaptor's test watermark (two hatched bands per page, recognisable by the
`docraptor.com/public-key-watermark` links) in pixel diffs only.

What exists today (built during this analysis):
- a scenario builder (PHP script run inside the `career-assessment-api` Docker image with
  the repo code mounted read-only and SQLite as database) that creates the six scenarios
  from the real entities with synthetic data: IdentyFi stanines 7/5/6/4/8/6 and the extremes
  1/9/2/3/9/1; WayFi with all 22 profession areas and full-page area images; BasicCheck with
  ten cognitive modules over positions 1–10, two practice modules, norm tables, male and
  female candidates, long names with diacritics;
- for each scenario `source.html` and `docraptor.pdf` (DocRaptor test mode, Prince 15.1);
- a batch runner over the comparator.

The HTML loads the Adobe Fonts kits live, so the references are only valid while the kits
are unchanged. If a kit changes (§7), render the references again.

Run mode (design call): manual sign-off gate before switching production, with the frozen
references. A CI job is optional later; it would need the platen image, network access to
`use.typekit.net`, and the frozen references.

## 9. Acceptance criteria

Original templates (with the fixed font kit, §7), unchanged client options, platen with
compat vs DocRaptor test mode, all six scenarios:
- same page count and page size (A4);
- identical word sequence; no word on a different page;
- word positions: p95 ≤ 0.2mm, max ≤ 0.5mm;
- header and footer text identical on every page;
- images: same count, pixel size, position within 0.2mm;
- page `Group` and neutral-colour operators as Prince (F3);
- pixel difference ≤ 0.5% per page outside the watermark bands.

Quality layer, every request:
- each fix has tests with spec vectors, plus a Chromium comparison where the behaviour is
  browser-defined (§4.4);
- requests without a pipeline number show no Prince-layer behaviour (F1, F3b, F4b, F7b, F9b,
  F10);
- existing platen tests pass, or each changed expectation is listed in the release notes.

## 10. Open risks and gaps

- `@left`/`@right` regions and mixing `@top` with `@top-*` boxes are not calibrated.
- Prince's justification interplay with hyphenation is not isolated (F8).
- pyphen's patterns for Prince's other languages may differ from Prince's own `.pat` files;
  only en-US was verified.
- Spaces next to fallback-font runs: Prince sets some in the fallback font (wider). Whether
  WeasyPrint/Pango does the same was not measured. Out of scope (§7); documents with missing
  glyphs may show a line-break difference here.
- `content` on non-pseudo elements and `counter-reset: page` on elements (Prince
  extensions) are used by the templates' unused base footer only; not covered.
- Comic Sans MS, Verdana: no metric-compatible free fonts.

## 11. Left to the builder

Module layout, how each monkeypatch is written, PHP plumbing details, test framework and
fixture format, the `document_url` routing choice (F1), and the precedence rule for `@top`
vs `@top-*` (F2).

## 12. Decisions

By the owner (2026-10-20):
- End goal: a very good PDF library. Two layers: quality fixes for every request, Prince
  quirks only for DocRaptor pipeline numbers (§4.0).
- The IdentyFi font kit gets the Serbian characters before parity sign-off (§7).
- The Prince layer triggers only on a DocRaptor pipeline number (§4.1).
- Design doc and generic calibration tools live in platen (tools switched to free fonts
  before commit); the parity suite lives in `career-assessment-api` `tools/pdf-parity/` (§8.3).

Design calls made during analysis (open to challenge):
- Patches as a pinned monkeypatch wrapper around WeasyPrint 70.0, no fork; mismatched
  versions fail loudly; `PLATEN_WEASYPRINT_BINARY=weasyprint` is the escape hatch (§4.2).
- Kill switch `PLATEN_PRINCE_COMPAT` default `true` for the Prince layer (§4.1).
- F1 rerouting only inside compat; a script is `<script` or an `on…=` attribute (F1).
- One fontconfig file for every render; default family serif (F5).
- Justification with shrinkable spaces is in the quality layer (F8).
- `en` hyphenates with `en_US` in the quality layer (F9a).
- Quality-layer tests compare against platen's own Chromium engine (§4.4).
- Parity suite is a manual sign-off gate; fixtures generated on demand, not committed (§8.3).
