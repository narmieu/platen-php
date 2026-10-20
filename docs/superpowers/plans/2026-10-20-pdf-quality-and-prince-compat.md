# PDF Quality Layer and Prince Compatibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make platen's WeasyPrint engine render closer to the CSS specs and browsers for every request (quality layer) and like Prince 15.1 for DocRaptor pipeline numbers (Prince layer).

**Architecture:** A Python package `platen-weasyprint` monkeypatches WeasyPrint 70.0 in-process and wraps its CLI as `weasyprint-platen`; the quality patches always apply, the Prince patches only with `--platen-prince`. PHP decides per request (pipeline number + `PLATEN_PRINCE_COMPAT`) and passes the flag. A fontconfig file sets the default fonts.

**Tech Stack:** PHP 8.4 / Symfony / PHPUnit 13; Python 3.13, WeasyPrint 70.0, pydyf 0.13.0, pyphen 0.18.1, fontTools, pytest, pypdf; fontconfig; Docker; GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-20-pdf-quality-and-prince-compat-design.md` (sections cited as §n, fixes as Fn).

## Global Constraints

- WeasyPrint exactly `70.0`, pydyf `0.13.0`, pyphen `0.18.1`; any other version → `RuntimeError("platen-weasyprint supports WeasyPrint 70.0, found <x>")` (same wording per package).
- Quality layer on every WeasyPrint render. Prince layer only when `pipeline` matches `^\d+(\.\d+)*$` after trimming **and** `PLATEN_PRINCE_COMPAT=true` (default `true`).
- Requests without a pipeline number never show Prince-layer behaviour (F1, F3b, F4b, F7b, F9b, F10).
- `PLATEN_WEASYPRINT_BINARY` defaults to `weasyprint-platen`; `PLATEN_WEASYPRINT_BINARY=weasyprint` restores stock WeasyPrint.
- Tests and committed tools use free fonts only (DejaVu, Liberation, `tests/Support/fonts/platen-test.woff`); no career-assessment templates, logos, texts or Adobe Fonts kit IDs in this repo.
- Breaking release: every quality-layer behaviour change gets one line in `docs/release-notes/next.md`.
- Font metric selection (F6): ascender A, descender D, line gap G from OS/2 typo when `fsSelection` bit 7 (`USE_TYPO_METRICS`) is set, else from hhea; `line-height: normal` = (A + D + G) × font-size.
- Prince constants (F7b, F8, F9b, F10): word spaces shrink to 5/6; super raise 0.5 × A(parent) × parent font-size; sub drop 0.5 × D(parent) × parent font-size; `sub`/`sup` 83%; `smaller` ÷1.2; `larger` ×1.2; marker gap 8pt; hyphen U+002D; Prince hyphenation languages: da, de, en-US, es, fi, fr, is, it, lt, pl, pt, ru, sl, sv, th.
- WeasyPrint lengths are CSS px; tests compare in pt (1px = 0.75pt).

## Review Focus

- `@font-face` faces with weight ranges (variable fonts) or `unicode-range` subsets of one weight: the right face must be chosen and every subset still used. Test in Task 10.
- `lang` values with region or script subtags, odd case or empty (`sr-Latn-RS`, `sr-Cyrl`, `SR`, `en-GB`, `lang=""`), and `hyphens: auto` without `lang`: explicit script wins, no `lang` means no hyphenation. Test in Task 9.
- Justified text containing `&nbsp;`, `letter-spacing` or bold spans, and `text-align-last: justify`: no line wider than its box; last line follows `text-align-last`. Test in Task 11.
- Inline SVG with undefined `var()` (with and without fallback), its own `<style>` element, or an explicit `font-family`: author values win, nothing renders black by accident. Test in Task 3.
- Page regions with `@page :first` overrides and named pages, and `@top` together with `@top-*`: overrides apply, `@top-*` boxes win. Test in Task 4.

---

### Task 1: `platen-weasyprint` package, wrapper CLI, test harness, image and CI

**Files:**
- Create: `docker/weasyprint/pyproject.toml`
- Create: `docker/weasyprint/platen_weasyprint/__init__.py`
- Create: `docker/weasyprint/platen_weasyprint/cli.py`
- Create: `docker/weasyprint/platen_weasyprint/quality/__init__.py`
- Create: `docker/weasyprint/platen_weasyprint/prince/__init__.py`
- Create: `docker/weasyprint/tests/support.py`
- Create: `docker/weasyprint/tests/fonts.conf`
- Create: `docker/weasyprint/tests/quality/conftest.py`, `docker/weasyprint/tests/prince/conftest.py`
- Create: `docker/weasyprint/tests/test_cli.py`
- Create: `docs/release-notes/next.md`
- Modify: `Dockerfile` (base stage, after the WeasyPrint install)
- Modify: `.github/workflows/ci.yml` (new job `weasyprint`; e2e job installs the package)

**Interfaces:**
- Produces:
  - `pyproject.toml`: project `platen-weasyprint` `0.1.0`, `requires-python >=3.11`, dependencies `weasyprint==70.0`, `pydyf==0.13.0`, `pyphen==0.18.1`; extra `test = ["pytest", "pypdf"]`; console script `weasyprint-platen = "platen_weasyprint.cli:main"`.
  - `platen_weasyprint.SUPPORTED: dict[str, str] = {"weasyprint": "70.0", "pydyf": "0.13.0", "pyphen": "0.18.1"}`; `platen_weasyprint.check_versions() -> None`.
  - `platen_weasyprint.quality.MODULES: list[ModuleType]`, `platen_weasyprint.quality.apply() -> None` (calls `check_versions()`, then each module's `apply()` once; idempotent). Same for `platen_weasyprint.prince`. Every fix module exposes `apply() -> None`.
  - `platen_weasyprint.cli.main(argv: list[str] | None = None) -> None`: removes `--platen-prince` from `argv`, applies `quality`, applies `prince` if the flag was present, then calls `weasyprint.__main__.main(argv)`.
  - `tests/support.py` (pt units): `render(html: str) -> weasyprint.Document`; `pdf(html: str) -> bytes`; `Run(text: str, x: float, width: float, baseline: float, font_size: float)`; `runs(doc, page=0) -> list[Run]` (text boxes in document order); `lines(doc, page=0) -> list[list[Run]]` (grouped by line box, top to bottom); `baselines(doc, page=0) -> list[float]`; `Box(x: float, y: float, width: float, height: float, content_x: float, content_width: float)`; `margin_boxes(doc, page=0) -> dict[str, Box]` (key = at-keyword); `pdf_fonts(data: bytes, page=0) -> set[str]` (PostScript name, name ID 6, read with fontTools from each embedded font program — WeasyPrint's `BaseFont` uses the CSS family, e.g. `Liberation-Sans`, so it cannot tell faces apart; fall back to `BaseFont` without the `ABCDEF+` tag only when the program has no name table); `FACE: str` (`@font-face` block for family `"Platen Test"` from `tests/Support/fonts/platen-test.woff`, which has no U+2010 and no `ž`); `page_group_cs(data: bytes, page=0) -> str | None`; `content(data: bytes, page=0) -> str` (decoded content stream); `font_url(file: str) -> str` (`file://` URL under `/usr/share/fonts/truetype/{dejavu,liberation}/` or `tests/Support/fonts/`).
  - `tests/fonts.conf`: includes `../../fontconfig/45-platen.conf` (Task 6, ignore if missing) **before** `/etc/fonts/conf.d`; `tests/*/conftest.py` sets `FONTCONFIG_FILE` to it before importing WeasyPrint; `tests/quality/conftest.py` calls `quality.apply()`, `tests/prince/conftest.py` calls `quality.apply()` then `prince.apply()`. The two folders always run in separate pytest processes.

- [ ] **Step 1: Write the failing tests** in `docker/weasyprint/tests/test_cli.py`

```python
def test_version_guard_names_the_supported_version(monkeypatch):
    monkeypatch.setattr(weasyprint, "__version__", "71.0")
    with pytest.raises(RuntimeError, match="supports WeasyPrint 70.0, found 71.0"):
        platen_weasyprint.check_versions()

def test_flag_is_stripped_and_prince_applied(monkeypatch, tmp_path):
    calls = []
    monkeypatch.setattr(platen_weasyprint.quality, "apply", lambda: calls.append("quality"))
    monkeypatch.setattr(platen_weasyprint.prince, "apply", lambda: calls.append("prince"))
    src = tmp_path / "in.html"; src.write_text("<p>x</p>")
    out = tmp_path / "out.pdf"
    cli.main(["--platen-prince", str(src), str(out)])
    assert calls == ["quality", "prince"] and out.read_bytes().startswith(b"%PDF")

def test_without_flag_only_quality_applies(monkeypatch, tmp_path):
    # same as above without the flag; assert calls == ["quality"]
```

- [ ] **Step 2: Run** `cd docker/weasyprint && pip install -e '.[test]' && pytest tests/test_cli.py -v` — Expected: FAIL (`ModuleNotFoundError: platen_weasyprint`).
- [ ] **Step 3: Implement** the package, `cli.main`, `check_versions`, empty `MODULES` lists, `tests/support.py`, `tests/fonts.conf`, both `conftest.py`. `support.lines()` walks `doc.pages[i]._page_box` (`LineBox` → `TextBox` descendants); `pdf_fonts`/`page_group_cs`/`content` use `pypdf.PdfReader`.
- [ ] **Step 4: Run** `pytest tests/test_cli.py -v` — Expected: PASS (3 tests).
- [ ] **Step 5: Wire the image and CI.** Dockerfile base stage: `COPY docker/weasyprint /opt/platen-weasyprint`, `/opt/weasyprint/bin/pip install --no-cache-dir /opt/platen-weasyprint`, `ln -s /opt/weasyprint/bin/weasyprint-platen /usr/local/bin/weasyprint-platen`, `weasyprint-platen --version`. CI: new job `weasyprint` (ubuntu-24.04; apt `libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz-subset0 fonts-dejavu-core fonts-liberation`; `pip install './docker/weasyprint[test]'`; `pytest docker/weasyprint/tests/test_cli.py docker/weasyprint/tests/quality` then `pytest docker/weasyprint/tests/prince` as separate commands). E2E job: replace `pipx install "weasyprint==${WEASYPRINT_VERSION}"` with `pipx install ./docker/weasyprint` (brings `weasyprint` and `weasyprint-platen`). Create `docs/release-notes/next.md` with heading `# Next release` and section `## Rendering changes (breaking)`.
- [ ] **Step 6: Verify** `docker build -t platen:dev . && docker run --rm --entrypoint weasyprint-platen platen:dev --version` — Expected: prints `WeasyPrint version 70.0`.
- [ ] **Step 7: Commit** `git add docker/weasyprint Dockerfile .github/workflows/ci.yml docs/release-notes && git commit -m "feat: platen-weasyprint wrapper package with version guard and test harness"`

### Task 2: Engine choice and the `--platen-prince` flag (PHP)

**Files:**
- Create: `src/Rendering/EngineChoice.php`
- Modify: `src/Rendering/RenderJob.php` (new last constructor parameter)
- Modify: `src/Rendering/Renderer.php:42-87`
- Modify: `src/Rendering/WeasyPrint/WeasyPrintEngine.php:61-104` (`arguments()` becomes public)
- Modify: `src/Config/PlatenConfig.php`, `tests/Support/TestConfig.php`, `.env`
- Modify: `README.md` (Configuration table; new section "Rendering quality and Prince compatibility")
- Test: `tests/Unit/Rendering/EngineChoiceTest.php`, `tests/Unit/Rendering/WeasyPrint/WeasyPrintEngineTest.php`

**Interfaces:**
- Consumes: `cli.main` flag `--platen-prince` (Task 1).
- Produces:
  - `final readonly class EngineChoice { public function __construct(public Engine $engine, public bool $princeCompat) {} public static function for(DocumentRequest $request, PlatenConfig $config): self }` — engine exactly as today (JavaScript → Chromium, else `Engine::fromPipeline`); `princeCompat = $config->princeCompat && $engine === Engine::WeasyPrint && preg_match('/^\d+(\.\d+)*$/', trim((string) $request->pipeline))`.
  - `RenderJob::$princeCompat` (`bool`, default `false`).
  - `PlatenConfig::$princeCompat` from env `bool:PLATEN_PRINCE_COMPAT`; `TestConfig::create(bool $princeCompat = true, string $weasyprintBinary = 'weasyprint-platen', ...)`.
  - `WeasyPrintEngine::arguments(RenderJob $job): list<string>` appends `--platen-prince` when `$job->princeCompat`.

- [ ] **Step 1: Write the failing tests.** `EngineChoiceTest` data provider (pipeline, javascript, config flag → engine, compat): `'10'`, no JS, on → WeasyPrint, true; `'10.1'` → true; `' 9 '` → true; `'1.2.3'` → true; `null` → WeasyPrint, false; `''` → false; `'weasyprint'` → false; `'chromium'` → Chromium, false; `'10a'` → false; `'10'` with flag off → false; `'10'` with JavaScript → Chromium, false (Task 13 changes this row). `WeasyPrintEngineTest`: `arguments()` contains `--platen-prince` iff `princeCompat`, and starts with the configured binary.
- [ ] **Step 2: Run** `composer test -- --filter 'EngineChoice|WeasyPrintEngine'` — Expected: FAIL (class `EngineChoice` not found).
- [ ] **Step 3: Implement** `EngineChoice`, the config/env (`PLATEN_PRINCE_COMPAT=true`, `PLATEN_WEASYPRINT_BINARY=weasyprint-platen` in `.env`), `RenderJob` field, `Renderer` using `EngineChoice::for()` and passing `princeCompat` into `RenderJob`, the flag in `arguments()`. README: both env vars in the Configuration table, plus a section describing the two layers (§4.0 table) and the escape hatch.
- [ ] **Step 4: Run** `composer check` — Expected: PASS (cs, phpstan, unit).
- [ ] **Step 5: Commit** `git commit -am "feat: choose Prince compatibility per request and pass it to the WeasyPrint wrapper"` (add the new files first).

### Task 3: F11 — inline SVG inherits the page font and resolves `var()` (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/svg.py` (register in `quality.MODULES`)
- Test: `docker/weasyprint/tests/quality/test_svg.py`

**Interfaces:**
- Consumes: `tests/support.py` (Task 1).
- Produces: `svg.apply()` replaces `weasyprint.html.HTML_HANDLERS['svg']` (registry entry, not just the module attribute) with a wrapper of `handle_svg(element, box, get_image_from_uri, base_url)` that, before `SVGImage(...)`: sets `font-family`, `font-style`, `font-weight`, `font-size` (px) on the root `<svg>` from `box.style` when the root has none of them (attribute or `style`); replaces `var(--name[, fallback])` in `style` and presentation attributes of the subtree with `tinycss2.serialize(box.style['__name'])`, else the fallback, else drops that declaration/attribute. Custom properties are stored as `__name` with `-` → `_` (`css/__init__.py:626`).

- [ ] **Step 1: Write the failing tests**

```python
def test_rect_fill_from_root_custom_property():
    data = pdf('<style>:root{--c:#b7cef9}</style><svg width="20" height="20"><rect width="20" height="20" style="fill: var(--c)"/></svg>')
    assert has_rgb_fill(content(data), (0.7176, 0.8078, 0.9765))  # helper: any "r g b rg" within 0.002

def test_var_fallback_when_property_missing():
    data = pdf('<svg width="20" height="20"><rect width="20" height="20" style="fill: var(--missing, #ff0000)"/></svg>')
    assert has_rgb_fill(content(data), (1, 0, 0))

def test_undefined_var_without_fallback_does_not_turn_black():
    data = pdf('<svg width="20" height="20"><g fill="#00ff00"><rect width="20" height="20" style="fill: var(--missing)"/></g></svg>')
    assert has_rgb_fill(content(data), (0, 1, 0))   # inherited fill, as browsers do

def test_svg_text_inherits_html_font():
    data = pdf('<div style="font-family: \'Liberation Sans\'"><svg width="40" height="20"><text y="15">12</text></svg></div>')
    assert "LiberationSans" in pdf_fonts(data)

def test_explicit_svg_font_family_wins():
    data = pdf('<div style="font-family: \'Liberation Sans\'"><svg width="40" height="20"><text y="15" font-family="DejaVu Sans">12</text></svg></div>')
    assert "DejaVuSans" in pdf_fonts(data) and "LiberationSans" not in pdf_fonts(data)

def test_svg_style_element_still_applies():
    data = pdf('<svg width="20" height="20"><style>rect{fill:#00ff00}</style><rect width="20" height="20"/></svg>')
    assert has_rgb_fill(content(data), (0, 1, 0))
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_svg.py -v` — Expected: FAIL (black fill / default font).
- [ ] **Step 3: Implement** `svg.py` as in Interfaces; add `has_rgb_fill(content: str, rgb: tuple[float, float, float], tol=0.002) -> bool` to `tests/support.py`.
- [ ] **Step 4: Run** the same command — Expected: PASS (6 tests).
- [ ] **Step 5: Release note** `- Inline SVG now inherits the page font and resolves CSS custom properties (var()), like browsers.`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): inline SVG inherits fonts and resolves var()"` (after `git add`).

### Task 4: F2 — Prince static flows and `@top`/`@bottom` regions (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/prince_syntax.py`
- Test: `docker/weasyprint/tests/quality/test_prince_syntax.py`

**Interfaces:**
- Produces:
  - Wrapper of `weasyprint.css.validation.preprocess_declarations(base_url, declarations, prelude=None)` that rewrites tinycss2 declarations first: `flow`/`-prince-flow` with `static(<ident>)` → `position: running(<ident>)`; `content` values with `flow(<ident>)` → `element(<ident>)`. Exposes `register_rename(name: str, target: str) -> None` so Task 5 can map `-prince-pdf-page-colorspace`.
  - `weasyprint.css.PAGE_MARGIN_BOXES` extended with `top`, `bottom`; wrapper of `weasyprint.layout.page.make_margin_boxes(context, page, state)` that lays out an `@top` (`@bottom`) rule with non-`none` content as one box with border box x = margin-left … margin-left + `page.border_width()`, y = 0 … margin-top (bottom: page height − margin-bottom … page height), content centred; skipped when any `@top-left`/`@top-center`/`@top-right` (resp. bottom) box has content.

- [ ] **Step 1: Write the failing tests**

```python
PAGE = "@page { size: A4; margin: 20mm 30mm; }"

def test_static_flow_header_on_every_page():
    html = f'<style>{PAGE} #h {{ flow: static(header) }} @page {{ @top {{ content: flow(header) }} }}</style><div id="h">HEADER</div>' + '<p style="break-after: page">x</p>' * 3
    doc = render(html)
    assert len(doc.pages) == 3
    for page in range(3):
        assert "HEADER" in texts(margin_boxes(doc, page)["top"])   # support helper: text inside a box
        assert all("HEADER" not in r.text for r in runs(doc, page) if r.baseline > 56.7)

def test_top_region_spans_between_side_margins():
    box = margin_boxes(render(f'<style>{PAGE} @page {{ @top {{ content: "TOP"; background: #f00 }} }}</style>x'))["top"]
    assert box.x == approx(85.04, abs=0.1) and box.width == approx(425.20, abs=0.1)
    assert box.y == approx(0, abs=0.1) and box.height == approx(56.69, abs=0.1)

def test_region_padding_is_inside_the_span():
    css = "@page { size: A4; margin: 0; margin-top: 35mm; @top { content: 'X'; padding-left: 15mm; padding-right: 18mm } }"
    box = margin_boxes(render(f"<style>{css}</style>x"))["top"]
    assert box.content_x == approx(42.52, abs=0.1) and box.content_width == approx(501.73, abs=0.1)

def test_top_dash_boxes_win_over_top():
    css = f"{PAGE} @page {{ @top {{ content: 'A' }} @top-center {{ content: 'B' }} }}"
    boxes = margin_boxes(render(f"<style>{css}</style>x"))
    assert "top" not in boxes and "B" in texts(boxes["top-center"])

def test_first_page_override_and_named_page():
    css = (f"{PAGE} @page {{ @bottom {{ content: 'ALL' }} }} @page :first {{ @bottom {{ content: 'FIRST' }} }}"
           " @page wide { @bottom { content: 'WIDE' } } .w { page: wide }")
    doc = render(f"<style>{css}</style><p>1</p><p style='break-before: page'>2</p><div class='w'>3</div>")
    assert [texts(margin_boxes(doc, p)["bottom"]) for p in range(3)] == ["FIRST", "ALL", "WIDE"]
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_prince_syntax.py -v` — Expected: FAIL (`KeyError: 'top'` / missing header).
- [ ] **Step 3: Implement** `prince_syntax.py`; add `texts(box) -> str` to `tests/support.py`.
- [ ] **Step 4: Run** — Expected: PASS (5 tests).
- [ ] **Step 5: Release note** `- Prince syntax is now understood: flow: static() / content: flow() running elements and the full-width @top / @bottom page regions.`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): accept Prince static flows and @top/@bottom regions"`

### Task 5: F3a — honour `-prince-pdf-page-colorspace: cmyk` (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/page_colorspace.py`
- Test: `docker/weasyprint/tests/quality/test_page_colorspace.py`

**Interfaces:**
- Consumes: `prince_syntax.register_rename()` (Task 4).
- Produces:
  - Page property stored in the page's computed style as `platen_pdf_page_colorspace` (`auto | rgb | cmyk`, initial `auto`), reachable through `-prince-pdf-page-colorspace` in `@page` rules (named pages and pseudo-classes included).
  - `page_colorspace.set_default_group(cs: str | None) -> None` (module default `None`; Task 14 sets `'/DeviceRGB'`).
  - Patches `weasyprint.document.Document.write_pdf` (remember the document being written) and `pydyf.PDF.add_page` (page n gets `Group << /S /Transparency /CS /DeviceCMYK >>` when its style says `cmyk`, else the default group when set).

- [ ] **Step 1: Write the failing tests**

```python
def test_cmyk_page_gets_cmyk_group():
    data = pdf("<style>@page { -prince-pdf-page-colorspace: cmyk }</style><p>x</p>")
    assert page_group_cs(data) == "/DeviceCMYK"

def test_named_page_only():
    css = "@page c { -prince-pdf-page-colorspace: cmyk } .c { page: c; break-before: page }"
    data = pdf(f"<style>{css}</style><p>rgb</p><div class='c'>cmyk</div>")
    assert [page_group_cs(data, 0), page_group_cs(data, 1)] == [None, "/DeviceCMYK"]

def test_no_property_no_group():
    assert page_group_cs(pdf("<p>x</p>")) is None
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_page_colorspace.py -v` — Expected: FAIL.
- [ ] **Step 3: Implement** `page_colorspace.py`.
- [ ] **Step 4: Run** — Expected: PASS (3 tests).
- [ ] **Step 5: Release note** `- Pages that ask for -prince-pdf-page-colorspace: cmyk get a CMYK transparency group, as in Prince.`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): honour -prince-pdf-page-colorspace"`

### Task 6: F5 — default and generic fonts (quality)

**Files:**
- Create: `docker/fontconfig/45-platen.conf`
- Modify: `Dockerfile` (`COPY docker/fontconfig/45-platen.conf /etc/fonts/conf.d/45-platen.conf`), `.github/workflows/ci.yml` (e2e job: `sudo cp docker/fontconfig/45-platen.conf /etc/fonts/conf.d/`)
- Test: `docker/weasyprint/tests/quality/test_fonts.py`

**Interfaces:**
- Produces: `45-platen.conf` (must sort before `49-sansserif.conf` and `60-latin.conf`):

```xml
<?xml version="1.0"?>
<!DOCTYPE fontconfig SYSTEM "fonts.dtd">
<fontconfig>
  <!-- No generic family requested: fall back to serif, as Prince and browsers do. -->
  <match target="pattern">
    <test qual="all" name="family" compare="not_eq"><string>sans-serif</string></test>
    <test qual="all" name="family" compare="not_eq"><string>serif</string></test>
    <test qual="all" name="family" compare="not_eq"><string>monospace</string></test>
    <edit name="family" mode="append_last"><string>serif</string></edit>
  </match>
  <alias><family>serif</family><prefer><family>Liberation Serif</family></prefer></alias>
  <alias><family>sans-serif</family><prefer><family>Liberation Sans</family></prefer></alias>
  <alias><family>monospace</family><prefer><family>Liberation Mono</family></prefer></alias>
</fontconfig>
```

- [ ] **Step 1: Write the failing tests** (`FACE` from `tests/support.py`: family `"Platen Test"`, lacks `ž`)

```python
@pytest.mark.parametrize("family, expected", [
    ("serif", "LiberationSerif"), ("sans-serif", "LiberationSans"), ("monospace", "LiberationMono"),
    ("NoSuchFont", "LiberationSerif"), ("Arial", "LiberationSans"), ("'Times New Roman'", "LiberationSerif")])
def test_generic_and_unknown_families(family, expected):
    assert expected in pdf_fonts(pdf(f'<p style="font-family: {family}">Hamburgefonstiv</p>'))

def test_missing_glyph_without_generic_falls_back_to_serif():
    assert "LiberationSerif" in pdf_fonts(pdf(FACE + '<p style="font-family: \'Platen Test\'">ab ž</p>'))

def test_missing_glyph_with_sans_serif_falls_back_to_sans():
    assert "LiberationSans" in pdf_fonts(pdf(FACE + '<p style="font-family: \'Platen Test\', sans-serif">ab ž</p>'))
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_fonts.py -v` — Expected: FAIL (`NotoSans`/`DejaVuSans` found).
- [ ] **Step 3: Create** `45-platen.conf`, the Dockerfile and CI lines; `tests/fonts.conf` already includes it (Task 1).
- [ ] **Step 4: Run** — Expected: PASS (8 tests). Also `docker build -t platen:dev . && docker run --rm --entrypoint fc-match platen:dev sans-serif` → `LiberationSans-Regular.ttf: "Liberation Sans" "Regular"`.
- [ ] **Step 5: Release note** `- Default fonts: serif when no family matches; serif / sans-serif / monospace map to Liberation Serif / Sans / Mono (metric twins of Times New Roman, Arial, Courier New).`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): browser-like default and generic fonts"`

### Task 7: F6 — line metrics: `normal`, content area, fallback glyphs (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/fontmetrics.py`
- Create: `docker/weasyprint/platen_weasyprint/quality/line_metrics.py`
- Test: `docker/weasyprint/tests/quality/test_line_metrics.py`

**Interfaces:**
- Produces:
  - `@dataclass(frozen=True) class FontMetrics: ascent: float; descent: float; line_gap: float` (fractions of the em, descent positive).
  - `fontmetrics.primary_metrics(style) -> FontMetrics`: first available font of `style` (Pango font for a space), tables via HarfBuzz `hb_face_reference_table`: `head` unitsPerEm at offset 18 (uint16); `OS/2` fsSelection at 62 (uint16), sTypoAscender 68, sTypoDescender 70, sTypoLineGap 72 (int16); `hhea` ascender 4, descender 6, lineGap 8 (int16). Typo when fsSelection bit 7 set, else hhea. Cached per font.
  - Patches: `weasyprint.text.line_break.strut(style)` → `normal` height (A + D + G) × size, baseline (A + G/2) × size; other line-heights baseline A × size + (L − (A + D) × size)/2. `weasyprint.text.line_break.first_line_metrics(...)` → text height (A + D) × size and baseline A × size of the primary font, whatever fonts Pango used for the run. `weasyprint.layout.inline._adjust_line_height(box)` → `box.height = (A + D) × font_size`.

- [ ] **Step 1: Write the failing tests** (two `<br>` lines at 20pt, compare consecutive baselines)

```python
@pytest.mark.parametrize("family, pitch", [("Liberation Sans", 22.998), ("DejaVu Sans", 23.281)])
def test_normal_line_height_includes_line_gap(family, pitch):
    b = baselines(render(f'<p style="font: 20pt/normal \'{family}\'">a<br>b<br>c</p>'))
    assert b[1] - b[0] == approx(pitch, abs=0.02) and b[2] - b[1] == approx(pitch, abs=0.02)

def test_use_typo_metrics_bit_selects_os2_typo(tmp_path):
    url = typo_dejavu(tmp_path)   # fontTools copy of DejaVuSans.ttf with fsSelection |= 1 << 7
    b = baselines(render(f'<style>@font-face {{ font-family: T; src: url({url}) }}</style><p style="font: 20pt/normal T">a<br>b</p>'))
    assert b[1] - b[0] == approx(24.004, abs=0.02)     # (1556 + 492 + 410) / 2048 * 20

def test_fallback_glyph_does_not_grow_the_line():
    b = baselines(render('<p style="font: 10pt/1.6 \'Liberation Sans\'">one<br>two ☃<br>three</p>'))
    assert [round(y2 - y1, 2) for y1, y2 in zip(b, b[1:])] == [16.0, 16.0]
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_line_metrics.py -v` — Expected: FAIL (Liberation pitch 22.34; ☃ line taller).
- [ ] **Step 3: Implement** `fontmetrics.py`, `line_metrics.py`; add `typo_dejavu(tmp_path) -> str` to `tests/support.py`.
- [ ] **Step 4: Run** the quality suite `pytest docker/weasyprint/tests/test_cli.py docker/weasyprint/tests/quality -v` — Expected: PASS (all earlier tests still pass).
- [ ] **Step 5: Release note** `- line-height: normal now includes the font's line gap, and glyphs from fallback fonts no longer make lines taller (CSS 2.1, browsers).`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): browser line metrics"`

### Task 8: F7a — `sup`/`sub` inherit line-height, CSS 2.1 inline boxes (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/superscripts.py`
- Test: `docker/weasyprint/tests/quality/test_superscripts.py`

**Interfaces:**
- Consumes: `fontmetrics.primary_metrics` (Task 7).
- Produces: wrapper of `weasyprint.HTML._ua_stylesheets(self, forms=False)` appending a user-agent `CSS(string="sub, sup { line-height: inherit }")`; inline box top/bottom = baseline ∓ (L ± (A − D) × size)/2 in `layout/inline.py` `inline_box_verticality`/`line_box_verticality` (987–1133) if the Task 7 patches do not already give it. Raise/drop stay WeasyPrint's (±0.5 × own font-size).

- [ ] **Step 1: Write the failing tests** (paragraphs R1, T, R2, R3 with `margin: 0`, 20pt Liberation Sans, `line-height: 24pt`; growth above = (T − R1) − (R3 − R2) on baselines)

```python
def test_sup_inherits_line_height():
    assert sup_style('<p style="line-height: 24pt">x<sup>2</sup></p>')["line_height"].value == 24   # helper returns computed style

@pytest.mark.parametrize("family, a_minus_d", [("Liberation Sans", 0.69336), ("DejaVu Sans", 0.69238)])
def test_superscript_grows_the_line_per_css21(family, a_minus_d):
    assert growth_above(family, sup_size=10) == approx(5.0 - a_minus_d * 5, abs=0.05)   # 1.533 / 1.538

def test_small_superscript_does_not_grow():
    assert growth_above("Liberation Sans", sup_size=5) == approx(0, abs=0.02)

def test_nested_sup_in_span_lines_do_not_overlap():
    doc = render('<p style="font: 20pt/24pt \'Liberation Sans\'; width: 60pt">aa <span style="line-height: 40pt">b<sup>2</sup></span> cc dd ee</p>')
    tops_bottoms = line_extents(doc)              # helper: (top, bottom) per line box, pt
    assert all(b2_top >= b1_bottom - 0.01 for (_, b1_bottom), (b2_top, _) in zip(tops_bottoms, tops_bottoms[1:]))
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_superscripts.py -v` — Expected: FAIL (growth 0.00).
- [ ] **Step 3: Implement** `superscripts.py`; add `sup_style()`, `growth_above(family, sup_size)`, `line_extents()` to `tests/support.py`.
- [ ] **Step 4: Run** the quality suite — Expected: PASS.
- [ ] **Step 5: Release note** `- sup and sub inherit the paragraph's line-height and enlarge the line when they stick out (HTML user-agent stylesheet, CSS 2.1).`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): CSS 2.1 line boxes for superscripts"`

### Task 9: F9a — hyphenation dictionary by language and script; hyphen glyph (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/hyphenation.py`
- Test: `docker/weasyprint/tests/quality/test_hyphenation.py`

**Interfaces:**
- Consumes: `fontmetrics` font lookup (Task 7) for glyph coverage.
- Produces:
  - `hyphenation.dictionary_for(lang: str | None, word: str) -> str | None`: normalise (`-` → `_`, case-insensitive); empty → `None`; explicit `Latn`/`Cyrl` subtag picks that script; `sr` without script → `sr_Latn` if the word has a Latin letter else `sr`; `en` without region → `en_US`; otherwise `pyphen.language_fallback(lang)`.
  - `hyphenation.set_resolver(fn: Callable[[str | None, str], str | None]) -> None` and `hyphenation.set_hyphen(policy: Literal["font", "hyphen-minus"]) -> None` (defaults: `dictionary_for`, `"font"`), used by Task 17.
  - Patch: replace `weasyprint.text.line_break.pyphen` with a shim whose `language_fallback(lang)` returns `lang` and whose `Pyphen(lang=..., left=..., right=...)` returns an object resolving the real dictionary per word through the resolver in `iterate()`/`positions()`/`inserted()`.
  - Hyphen glyph: when `hyphenate-character` is the initial value, use U+2010 if the text's primary font has it, else U+002D (`"font"` policy); always U+002D under `"hyphen-minus"`.

- [ ] **Step 1: Write the failing tests**

```python
@pytest.mark.parametrize("lang, word, expected", [
    ("sr", "samoprocenu", "sr_Latn"), ("sr", "самопроцену", "sr"), ("sr-Latn-RS", "x", "sr_Latn"),
    ("SR", "abc", "sr_Latn"), ("sr-Cyrl", "abc", "sr"), ("en", "x", "en_US"), ("en-GB", "x", "en_GB"),
    ("", "x", None), (None, "x", None)])
def test_dictionary_for(lang, word, expected):
    assert hyphenation.dictionary_for(lang, word) == expected

def test_serbian_latin_text_hyphenates():
    lines_ = lines(render('<p lang="sr" style="hyphens: auto; width: 40pt; font: 10pt \'DejaVu Sans\'">samoprocenu samoprocenu</p>'))
    assert any(l[-1].text.rstrip("‐-") in {"sa", "samo", "samopro", "samoproce"} for l in lines_)

def test_no_lang_no_hyphenation():
    text = "".join(r.text for r in runs(render('<p style="hyphens: auto; width: 40pt">samoprocenu samoprocenu</p>')))
    assert "‐" not in text and "-" not in text

def test_hyphen_glyph_follows_font():
    narrow = '<p lang="en" style="hyphens: auto; width: 40pt; font: 10pt {f}">representative representative</p>'
    assert "‐" in all_text(render(narrow.format(f="'DejaVu Sans'")))
    assert "-" in all_text(render(FACE + narrow.format(f="'Platen Test'"))) and "‐" not in all_text(render(FACE + narrow.format(f="'Platen Test'")))
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_hyphenation.py -v` — Expected: FAIL.
- [ ] **Step 3: Implement** `hyphenation.py`; add `all_text(doc) -> str` to `tests/support.py`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Release note** `- Hyphenation picks the dictionary by language and script (Serbian Latin uses sr_Latn; "en" uses en_US); the hyphen is U+2010 only when the font has it, otherwise "-".`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): script-aware hyphenation and hyphen glyph fallback"`

### Task 10: F4a — CSS Fonts 4 weight matching for `@font-face` (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/font_matching.py`
- Test: `docker/weasyprint/tests/quality/test_font_matching.py`

**Interfaces:**
- Produces:
  - `font_matching.css4_match(desired: int, available: Iterable[int | tuple[int, int]]) -> int` (ranges match when they contain `desired`; otherwise their nearer endpoint competes). Spec rule: 400–500 inclusive → ≥ desired ascending up to 500, then < desired descending, then > 500 ascending; < 400 → ≤ desired descending, then above ascending; > 500 → ≥ desired ascending, then below descending.
  - `font_matching.set_rule(fn: Callable[[int, Iterable], int]) -> None` (default `css4_match`; Task 15 sets `css3_match`).
  - Registry `FACES: dict[tuple[str, str], list[int | tuple[int, int]]]` (lower-cased family, style) filled by a wrapper of `weasyprint.text.fonts.FontConfiguration.add_font_face(self, rule_descriptors, url_fetcher)`; wrapper of `weasyprint.text.fonts.get_font_description(style)` sets the Pango weight to `rule(desired, FACES[...])` for registered families.

- [ ] **Step 1: Write the failing tests**

```python
@pytest.mark.parametrize("desired, available, expected", [
    (500, {200, 700}, 200), (400, {200, 300, 500, 700}, 500), (300, {200, 400, 500, 700}, 200),
    (600, {200, 300, 400, 500, 700, 900}, 700), (800, {200, 300, 500, 700}, 700), (100, {200, 700}, 200),
    (450, {300, 500}, 500), (450, {300, 600}, 300), (500, {(300, 600), 700}, 500)])
def test_css4_match(desired, available, expected):
    assert font_matching.css4_match(desired, available) == expected

def test_italic_500_uses_the_200_face():
    faces = face("W", "italic", 200, "LiberationSans-Regular.ttf") + face("W", "italic", 700, "DejaVuSans-Bold.ttf")
    assert pdf_fonts(pdf(faces + '<i style="font: 500 10pt W">x</i>')) == {"LiberationSans"}

def test_unicode_range_subsets_of_one_weight_are_both_used():
    faces = (face("U", "normal", 400, "LiberationSans-Regular.ttf", "U+0000-00FF")
             + face("U", "normal", 400, "DejaVuSans.ttf", "U+0100-017F"))
    assert pdf_fonts(pdf(faces + '<p style="font: 400 10pt U">ab ž</p>')) == {"LiberationSans", "DejaVuSans"}
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_font_matching.py -v` — Expected: FAIL (`css4_match` missing).
- [ ] **Step 3: Implement** `font_matching.py`; add `face(family, style, weight, file, unicode_range=None) -> str` (an `@font-face` `<style>` block) to `tests/support.py`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Release note** `- @font-face weight matching follows CSS Fonts Level 4 instead of fontconfig's nearest weight.`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): CSS Fonts 4 weight matching"`

### Task 11: F8 — justification may shrink word spaces to 5/6 (quality)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/quality/justification.py`
- Test: `docker/weasyprint/tests/quality/test_justification.py`

**Interfaces:**
- Produces: `justification.MIN_SPACE = 5 / 6`. Patches `weasyprint.layout.inline.split_text_box` / `weasyprint.text.line_break.split_first_line(text, style, context, max_width, justification_spacing, is_line_start=True, minimum=False)` so that, in blocks with `text_align_all == 'justify'`, lines are broken with `justification_spacing = −(1 − MIN_SPACE) × space_width` (space advance of the run's font; greedy); and `weasyprint.layout.inline.text_align(context, line, available_width, last)` so that justified non-last lines are set to exactly `available_width` (negative extra allowed via `justify_line`/`add_word_spacing`), last lines use natural spacing when they fit and otherwise compress to fit; `text-align-last: justify` justifies last lines too.

- [ ] **Step 1: Write the failing tests** (`W`, `S` = width of `nnnn` and of a space in DejaVu Sans 10pt, measured in the test from a left-aligned paragraph; `natural = 10*W + 9*S`; `budget = 9*S/6`)

```python
def test_fits_when_shrink_is_within_one_sixth():
    assert words_on_first_line(width=natural - 0.99 * budget) == 10

def test_wraps_when_shrink_exceeds_one_sixth():
    assert words_on_first_line(width=natural - 1.01 * budget) == 9

def test_justified_lines_end_at_the_right_edge():
    assert all(l[-1].x + l[-1].width == approx(box_right, abs=0.01) for l in justified_lines[:-1])

def test_last_line_compressed_only_when_needed():
    assert single_line_gaps(width=natural - 0.5 * budget) == approx((natural - 0.5 * budget - 10 * W) / 9, abs=0.01)
    assert single_line_gaps(width=natural + 50) == approx(S, abs=0.01)

def test_left_aligned_text_is_unchanged():
    assert words_on_first_line(width=natural - 0.01, align="left") == 9

def test_nbsp_letter_spacing_and_spans_never_overflow():
    doc = render('<p style="text-align: justify; width: 120pt; letter-spacing: 0.3pt; font: 10pt \'DejaVu Sans\'">'
                 + "aa&nbsp;bb <b>cc dd</b> ee " * 12 + "</p>")
    assert all(l[-1].x + l[-1].width <= box_right_of(doc) + 0.01 for l in lines(doc))

def test_text_align_last_justify_fills_last_line():
    assert last_line_right(align_last="justify") == approx(box_right, abs=0.01)
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/quality/test_justification.py -v` — Expected: FAIL (wraps at any shrink).
- [ ] **Step 3: Implement** `justification.py`; helpers (`words_on_first_line`, `single_line_gaps`, `justified_lines`, `box_right`, `last_line_right`) live in the test module.
- [ ] **Step 4: Run** the full quality suite — Expected: PASS.
- [ ] **Step 5: Release note** `- Justified text may compress word spaces down to 5/6 of their width before wrapping (tighter justification, as Prince, InDesign and TeX do).`
- [ ] **Step 6: Commit** `git commit -m "feat(quality): compressible spaces in justified text"`

### Task 12: Browser oracle for the quality layer (§4.4)

**Files:**
- Create: `docker/weasyprint/tests/oracle/test_against_chromium.py`, `docker/weasyprint/tests/oracle/conftest.py`
- Modify: `.github/workflows/ci.yml` (e2e job, after Gotenberg starts: install `./docker/weasyprint[test]` in a venv, `GOTENBERG_URL=http://127.0.0.1:3000 pytest docker/weasyprint/tests/oracle`)

**Interfaces:**
- Consumes: quality layer (Tasks 3–11), `tests/support.py`.
- Produces: `chromium_pdf(html: str) -> bytes` (POST `multipart/form-data` with `files=index.html` to `$GOTENBERG_URL/forms/chromium/convert/html`, `paperWidth=8.27`, `paperHeight=11.69`, margins 0); tests skip when `GOTENBERG_URL` is unset; `baselines_pdf(data) -> list[float]` from pypdf text positions.

- [ ] **Step 1: Write the tests** (they pass only with Tasks 3–11 in place; run them first against stock `weasyprint` to see them fail)

```python
@pytest.mark.parametrize("family", ["Liberation Sans", "DejaVu Sans"])
def test_line_height_normal_matches_chromium(family):
    html = f'<p style="margin:0; font: 20pt/normal \'{family}\'">a<br>b<br>c</p>'
    assert pitch(baselines_pdf(pdf(html))) == approx(pitch(baselines_pdf(chromium_pdf(html))), abs=0.3)

def test_fallback_glyph_pitch_matches_chromium():   # same with '☃' on line 2, Liberation Sans 10pt/1.6

def test_svg_var_fill_and_font_match_chromium():
    html = '<style>:root{--c:#b7cef9}</style><div style="font-family:\'Liberation Sans\'"><svg width="40" height="20"><rect width="20" height="20" style="fill:var(--c)"/><text x="22" y="15">1</text></svg></div>'
    assert has_rgb_fill(content(pdf(html)), (0.7176, 0.8078, 0.9765))
    assert "LiberationSans" in pdf_fonts(pdf(html)) and "LiberationSans" in pdf_fonts(chromium_pdf(html))
```

- [ ] **Step 2: Run** `PLATEN_ORACLE_STOCK=1 GOTENBERG_URL=http://127.0.0.1:3000 pytest docker/weasyprint/tests/oracle -v` (`conftest.py` skips `quality.apply()` when `PLATEN_ORACLE_STOCK` is set) — Expected: FAIL on pitch and SVG.
- [ ] **Step 3: Run** the same without `PLATEN_ORACLE_STOCK` — Expected: PASS.
- [ ] **Step 4: Commit** `git commit -m "test(quality): compare quality-layer behaviour with Chromium"`

### Task 13: F1 — JavaScript requests without scripts use WeasyPrint under compat (Prince)

**Files:**
- Create: `src/Rendering/Source/ScriptDetector.php`
- Modify: `src/Rendering/EngineChoice.php` (Task 2), `README.md` (JavaScript paragraph)
- Test: `tests/Unit/Rendering/Source/ScriptDetectorTest.php`, `tests/Unit/Rendering/EngineChoiceTest.php`, `tests/EndToEnd/PrinceCompatTest.php`

**Interfaces:**
- Consumes: `EngineChoice::for()` (Task 2).
- Produces: `final class ScriptDetector { public static function needsBrowser(string $html): bool }` — true for `/<script\b/i` or `/<[a-z][^>]*\son[a-z]+\s*=/i`. `EngineChoice::for()`: when JavaScript is requested, the pipeline is a number, `PLATEN_PRINCE_COMPAT` is on, `documentContent` is not null and `!ScriptDetector::needsBrowser($documentContent)` → `Engine::WeasyPrint` with `princeCompat = true`; `document_url` requests keep today's routing (design call §11).

- [ ] **Step 1: Write the failing tests.** ScriptDetector vectors: `<p>x</p>` → false; `<script>x()</script>` → true; `<SCRIPT src=a.js>` → true; `<body onload="f()">` → true; `<p>onboarding = fun</p>` → false; `<img src=x onerror=alert(1)>` → true. EngineChoice rows: `'10'` + JS + no script → WeasyPrint, true; `'10'` + JS + `<script>` → Chromium, false; no pipeline + JS → Chromium; `'weasyprint'` + JS → Chromium; `'10'` + JS + compat off → Chromium; `'10'` + JS + `document_url` → Chromium. E2E (`EndToEndTestCase::render`): `['pipeline' => '10', 'javascript' => true, 'document_content' => '<p>x</p>']` → header `x-platen-engine: weasyprint`; with `<script>document.body.append('y')</script>` → `chromium`.
- [ ] **Step 2: Run** `composer test -- --filter 'ScriptDetector|EngineChoice'` — Expected: FAIL.
- [ ] **Step 3: Implement** `ScriptDetector` and the `EngineChoice` branch; README: "With a DocRaptor pipeline number and Prince compatibility on, `javascript: true` documents without scripts render with WeasyPrint."
- [ ] **Step 4: Run** `composer check && composer test:e2e -- --filter PrinceCompat` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(prince): render script-free javascript documents with WeasyPrint"`

### Task 14: F3b — RGB page group everywhere, neutral colours as DeviceGray (Prince)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/prince/colors.py`
- Modify: `tests/Support/PdfFile.php` (add `pageGroupColorSpace(int $index = 0): ?string` from qpdf JSON)
- Test: `docker/weasyprint/tests/prince/test_colors.py`, `tests/EndToEnd/PrinceCompatTest.php`

**Interfaces:**
- Consumes: `page_colorspace.set_default_group()` (Task 5).
- Produces: `colors.apply()` calls `set_default_group('/DeviceRGB')` and wraps `weasyprint.pdf.stream.Stream.set_color(self, color, stroke=False)`: sRGB colours with r = g = b emit `<v> g` (fill) / `<v> G` (stroke) instead of `rg`/`RG`; alpha handling unchanged.

- [ ] **Step 1: Write the failing tests**

```python
HTML = ('<p style="color:#000">black</p><div style="background:#808080;height:5pt"></div>'
        '<div style="background:#ff0000;height:5pt"></div><div style="background:rgba(0,0,0,.5);height:5pt"></div>')

def test_neutral_colours_are_device_gray():
    ops = content(pdf(HTML))
    assert re.search(r"(^|\s)0 g(\s|$)", ops) and re.search(r"0\.50196\d* g", ops)
    assert "0 0 0 rg" not in ops and re.search(r"1 0 0 rg", ops)

def test_every_page_gets_an_rgb_group_unless_cmyk():
    css = "@page c { -prince-pdf-page-colorspace: cmyk } .c { page: c; break-before: page }"
    data = pdf(f"<style>{css}</style><p>a</p><div class='c'>b</div>")
    assert [page_group_cs(data, 0), page_group_cs(data, 1)] == ["/DeviceRGB", "/DeviceCMYK"]
```

E2E: `pipeline: '10'` → `PdfFile::pageGroupColorSpace()` is `/DeviceRGB`; no pipeline → `null`.
- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/prince/test_colors.py -v` — Expected: FAIL.
- [ ] **Step 3: Implement** `colors.py`; register in `prince.MODULES`; add the `PdfFile` helper.
- [ ] **Step 4: Run** `pytest docker/weasyprint/tests/prince -v && composer test:e2e -- --filter PrinceCompat` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(prince): RGB page groups and DeviceGray neutrals"`

### Task 15: F4b — CSS Fonts 3 weight matching (Prince)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/prince/font_matching.py`
- Test: `docker/weasyprint/tests/prince/test_font_matching.py`

**Interfaces:**
- Consumes: `quality.font_matching.set_rule()` (Task 10).
- Produces: `prince.font_matching.css3_match(desired: int, available: Iterable[int | tuple[int, int]]) -> int` — < 400: below descending, then above ascending; > 500: above ascending, then below descending; 400: 500 first, then the < 400 rule; 500: 400 first, then the > 500 rule. `apply()` calls `set_rule(css3_match)`.

- [ ] **Step 1: Write the failing tests** (Prince measurements, spec F4)

```python
CASES = {
    frozenset({200, 300, 400, 500, 700, 900}): {100: 200, 600: 700, 800: 900},
    frozenset({200, 300, 500, 700}): {100: 200, 400: 500, 600: 700, 800: 700, 900: 700},
    frozenset({200, 400, 500, 700}): {100: 200, 300: 200, 600: 700, 800: 700, 900: 700},
    frozenset({200, 700}): {100: 200, 300: 200, 400: 200, 500: 700, 600: 700, 800: 700, 900: 700},
}
@pytest.mark.parametrize("available, desired, expected", [(a, d, e) for a, m in CASES.items() for d, e in m.items()])
def test_css3_match_reproduces_prince(available, desired, expected):
    assert css3_match(desired, available) == expected

def test_italic_500_uses_the_700_face_under_prince():
    faces = face("W", "italic", 200, "LiberationSans-Regular.ttf") + face("W", "italic", 700, "DejaVuSans-Bold.ttf")
    assert pdf_fonts(pdf(faces + '<i style="font: 500 10pt W">x</i>')) == {"DejaVuSans-Bold"}
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/prince/test_font_matching.py -v` — Expected: FAIL.
- [ ] **Step 3: Implement**; register in `prince.MODULES`.
- [ ] **Step 4: Run** `pytest docker/weasyprint/tests/prince -v` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(prince): CSS Fonts 3 weight matching"`

### Task 16: F7b — Prince superscripts and font-size keywords (Prince)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/prince/superscripts.py`
- Test: `docker/weasyprint/tests/prince/test_superscripts.py`

**Interfaces:**
- Consumes: `fontmetrics.primary_metrics` (Task 7), the user-agent stylesheet hook of `quality.superscripts` (Task 8).
- Produces: replaces the registered computers for `vertical_align` (`super` → +0.5 × A(parent) × parent font-size; `sub` → −0.5 × D(parent) × parent font-size) and `font_size` (`smaller` → parent ÷ 1.2; `larger` → parent × 1.2) in `weasyprint.css.computed_values` (the `@register_computer` registry, not only the module attributes); adds user-agent `sub, sup { font-size: 83% }`.

- [ ] **Step 1: Write the failing tests** (Prince measurements, spec F7)

```python
@pytest.mark.parametrize("family, raise_, growth", [("Liberation Sans", 9.053, 5.59), ("DejaVu Sans", 9.282, 5.82)])
def test_prince_superscript(family, raise_, growth):          # P = 20pt, sup 10pt, line-height 24pt
    assert sup_raise(family) == approx(raise_, abs=0.02) and growth_above(family, sup_size=10) == approx(growth, abs=0.05)

def test_sub_drop_uses_parent_descender():
    assert sub_drop("Liberation Sans") == approx(0.5 * 434 / 2048 * 20, abs=0.02)        # 2.119

@pytest.mark.parametrize("parent, keyword, expected", [(10, "smaller", 8.333), (20, "smaller", 16.667), (10, "larger", 12), (20, "larger", 24)])
def test_font_size_keywords(parent, keyword, expected):
    assert computed_font_size(parent, keyword) == approx(expected, abs=0.01)

def test_default_sup_size_is_83_percent():
    assert sup_font_size(parent=10) == approx(8.3, abs=0.01) and sup_font_size(parent=30) == approx(24.9, abs=0.01)
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/prince/test_superscripts.py -v` — Expected: FAIL (raise 5.0, growth 1.53).
- [ ] **Step 3: Implement**; register in `prince.MODULES`; add `sup_raise(family)`, `sub_drop(family)` (20pt parent, 10pt sup/sub, `line-height: 24pt`), `computed_font_size(parent, keyword)`, `sup_font_size(parent)` to `tests/support.py`.
- [ ] **Step 4: Run** `pytest docker/weasyprint/tests/prince -v` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(prince): Prince superscripts and font-size keywords"`

### Task 17: F9b — Prince hyphenation dictionaries and hyphen (Prince)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/prince/hyphenation.py`
- Test: `docker/weasyprint/tests/prince/test_hyphenation.py`

**Interfaces:**
- Consumes: `quality.hyphenation.set_resolver()` and `set_hyphen()` (Task 9).
- Produces: `PRINCE_LANGUAGES = {"da", "de", "en_US", "es", "fi", "fr", "is", "it", "lt", "pl", "pt", "ru", "sl", "sv", "th"}`; `prince_dictionary_for(lang: str | None, word: str) -> str | None`: base language in the list → `pyphen.language_fallback(base)` (any English → `en_US`); any other non-empty language → `en_US`; empty → `None`. `apply()` calls `set_resolver(prince_dictionary_for)` and `set_hyphen("hyphen-minus")`.

- [ ] **Step 1: Write the failing tests** (Prince measurements, spec F9)

```python
@pytest.mark.parametrize("lang, expected", [("sr", "en_US"), ("hr", "en_US"), ("en", "en_US"), ("en-GB", "en_US"), ("de", pyphen.language_fallback("de")), ("", None)])
def test_prince_dictionaries(lang, expected):
    assert prince_dictionary_for(lang, "word") == expected

def test_serbian_breaks_like_prince():
    lines_ = lines(render('<p lang="sr" style="hyphens: auto; width: 34pt; font: 10pt \'DejaVu Sans\'">evaluaciji evaluaciji</p>'))
    assert any(l[-1].text == "eval-" for l in lines_)        # en-US allows eval-u-a-ci-ji; sr_Latn does not

def test_prince_hyphen_is_always_hyphen_minus():
    narrow = '<p lang="en" style="hyphens: auto; width: 40pt; font: 10pt \'DejaVu Sans\'">representative representative</p>'
    assert "-" in all_text(render(narrow)) and "‐" not in all_text(render(narrow))
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/prince/test_hyphenation.py -v` — Expected: FAIL.
- [ ] **Step 3: Implement**; register in `prince.MODULES`. Adjust the 34pt width in `test_serbian_breaks_like_prince` once if DejaVu metrics put the break elsewhere; the assertion stays "a break allowed only by en-US".
- [ ] **Step 4: Run** `pytest docker/weasyprint/tests/prince -v` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(prince): Prince hyphenation dictionaries and hyphen"`

### Task 18: F10 — list markers with a fixed 8pt gap (Prince)

**Files:**
- Create: `docker/weasyprint/platen_weasyprint/prince/markers.py`
- Test: `docker/weasyprint/tests/prince/test_markers.py`

**Interfaces:**
- Produces: wrapper of `weasyprint.formatting_structure.build.marker_to_box(...)` dropping the marker string's trailing whitespace; outside markers placed in `weasyprint.layout.block.block_container_layout` (`is_outside_marker` branch) so that the marker's right edge is the content box's left edge − 8pt; inside markers followed by an 8pt gap.

- [ ] **Step 1: Write the failing tests**

```python
@pytest.mark.parametrize("size", [8, 12, 16])
@pytest.mark.parametrize("kind, text", [("disc", "•"), ("decimal", "1.")])
def test_outside_marker_ends_8pt_before_content(size, kind, text):
    marker, item = marker_and_text(f'<ul style="list-style-type: {kind}; margin-left: 100pt; padding: 0; font: {size}pt \'DejaVu Sans\'"><li>item</li></ul>')
    assert marker.text == text and marker.x + marker.width == approx(item.x - 8, abs=0.01)

@pytest.mark.parametrize("size", [8, 12, 16])
def test_inside_marker_gap_is_8pt(size):
    marker, item = marker_and_text(f'<ul style="list-style: disc inside; padding: 0; font: {size}pt \'DejaVu Sans\'"><li>item</li></ul>')
    assert item.x == approx(marker.x + marker.width + 8, abs=0.01)
```

- [ ] **Step 2: Run** `pytest docker/weasyprint/tests/prince/test_markers.py -v` — Expected: FAIL (gap 1.70 / 2.54 / 3.39pt).
- [ ] **Step 3: Implement** `markers.py`; register in `prince.MODULES`; `marker_and_text()` in the test module.
- [ ] **Step 4: Run** `pytest docker/weasyprint/tests/prince -v` — Expected: PASS.
- [ ] **Step 5: Commit** `git commit -m "feat(prince): Prince list marker spacing"`

### Task 19: Generic calibration tools and release readiness

**Files:**
- Modify: `tools/prince-calibration/*.py` (replace `https://use.typekit.net/<kit>.css` links with `@font-face` rules for DejaVu / Liberation / `platen-test.woff`; `render_calib.py` reads `PLATEN_URL`, `PLATEN_API_KEY` from the environment)
- Modify: `tools/prince-calibration/README.md`, `docs/release-notes/next.md`, `README.md`
- Modify: `docs/superpowers/specs/2026-10-20-pdf-quality-and-prince-compat-design.md` §5 (free-font vectors next to the FF Real Head Pro ones)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Verify** `grep -rn "typekit" tools/ docs/ src/ tests/ docker/` — Expected: matches only in the spec's historical notes, none in `tools/`.
- [ ] **Step 2: Re-measure** with free fonts: `python tools/prince-calibration/make_calib.py out 0 0 && python tools/prince-calibration/render_calib.py out weights_<face set> families layout` (DocRaptor public test key) and record the Prince values in the spec §5 tables beside the existing ones.
- [ ] **Step 3: Run** everything — `composer check`, `composer test:e2e`, both pytest folders, `tests/Docker/smoke-test.sh` against a fresh `docker compose up --build` — Expected: all PASS.
- [ ] **Step 4: Release notes**: complete `docs/release-notes/next.md` (one line per quality change from Tasks 3–11, a "Prince compatibility" section for Tasks 13–18, the escape hatch `PLATEN_WEASYPRINT_BINARY=weasyprint`).
- [ ] **Step 5: Commit** `git commit -m "docs: release notes and free-font calibration tools"`
