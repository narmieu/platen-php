# Prince calibration tools

Scripts that measure Prince 15.1 (via DocRaptor) against platen for
`docs/superpowers/specs/2026-10-20-pdf-quality-and-prince-compat-design.md` §5. They are
analysis tools, not part of platen's runtime.

The documents use free fonts only: DejaVu, Liberation and
`tests/Support/fonts/platen-test.woff` (Liberation Sans cut down to printable ASCII, so it
has no U+2010 and no č ć š đ ž). `calib_fonts.py` embeds them as `data:` URIs, so DocRaptor
and platen load the very same files.

Requirements: Python 3 with `fonttools` to generate the documents, plus `pymupdf`,
`pillow` and `numpy` for `compare.py` and `sheet.py`; the DejaVu and Liberation fonts in
`/usr/share/fonts/truetype/{dejavu,liberation}` (Debian's `fonts-dejavu-core` and
`fonts-liberation`; the platen dev container has both); a running platen.

| Script | What it does |
|---|---|
| `make_calib.py <dir> <word_pt> <space_pt>` | Writes `weights_a.html`, `weights_b.html`, `weights_c.html` (weight matching, one face set each, see below), `families.html` (generic families, glyph fallback), `layout.html` (sup/sub, lists, hyphen glyph, `@top`/`@bottom`, colours, page groups), `justify.html` (space compression; skipped when `word_pt` is 0). Word/space widths: advance of `nnnn` and of a space in Liberation Sans at 10pt, `22.246 2.778`. |
| `make_sup2.py <dir>` | Writes `sup2.html`: superscript/subscript line-box growth with reference paragraphs, `line-height: normal` (Liberation Sans, DejaVu Sans, DejaVu Sans with USE_TYPO_METRICS), `smaller`/`larger`. |
| `render_calib.py <dir> <name>...` | Renders `<name>.html` with `pipeline: "10"` on DocRaptor (public test key, test mode: free, watermarked) and on platen; writes `<name>.docraptor.pdf` and `<name>.platen.pdf`. platen's address and key come from `PLATEN_URL` (default `http://localhost:8080`) and `PLATEN_API_KEY`. |
| `compare.py <baseline.pdf> <candidate.pdf> <out_dir> [dpi]` | Compares two PDFs: pages, sizes, text sequence, page placement and positions of every word, fonts per word and glyph-width ratios, images, fill colours, colour space, metadata, links, pixel diff with side-by-side images. Masks DocRaptor's test watermark bands in pixel diffs. |
| `sheet.py <out.png> <dpi> <label=pdf>...` | Contact sheet of all pages of several PDFs side by side. |

Weight matching face sets (family `Calib W`; each weight is another DejaVu or Liberation
file, renamed to `Calib W` so that it is one family inside too, as in a web font kit; the
PostScript name `CalibW-<weight>` / `CalibW-Italic-<weight>` in the PDF tells which face
was picked):

| Document | normal faces | italic faces |
|---|---|---|
| `weights_a` | 200, 300, 400, 500, 700, 900 | 200, 700 |
| `weights_b` | 200, 300, 500, 700 | none |
| `weights_c` | 200, 400, 500, 700 | none |

Example, with platen from `compose.yaml` on port 8080:

```sh
python tools/prince-calibration/make_calib.py out 22.246 2.778
python tools/prince-calibration/make_sup2.py out
PLATEN_API_KEY=change-me python tools/prince-calibration/render_calib.py out \
    weights_a weights_b weights_c families layout sup2 justify
python tools/prince-calibration/compare.py out/layout.docraptor.pdf out/layout.platen.pdf out/layout-diff
```

The analysis snippets that turned these renders into the numbers in the design document
were run interactively; the design document records their results.
