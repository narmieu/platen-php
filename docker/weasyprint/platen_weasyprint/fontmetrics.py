"""Line metrics and glyph coverage of the first available font of a style, read from the
font's own tables.

Browsers and Prince size lines from the ascender A, descender D and line gap G of the
element's first available font: from the OS/2 typo values when the font sets
USE_TYPO_METRICS (``fsSelection`` bit 7), otherwise from ``hhea`` (from OS/2 when ``hhea``
has neither ascender nor descender). Pango gives the extents of whichever fonts it used for
a run, and no line gap.

The metrics are fractions of the em, so they do not depend on the font size. They are
cached per font configuration: by the font-selecting properties of the style, and by font
(its HarfBuzz face), whose tables are read once.
"""

import struct
from dataclasses import dataclass

from weasyprint.text.ffi import ffi, harfbuzz, pango
from weasyprint.text.line_break import Layout, _font_style_cache_key

USE_TYPO_METRICS = 1 << 7


@dataclass(frozen=True)
class FontMetrics:
    """Ascender, descender (positive below the baseline) and line gap, in em."""

    ascent: float
    descent: float
    line_gap: float


# HarfBuzz's own metrics for a font without hhea, which Pango then uses as well.
_NO_TABLES = FontMetrics(0.8, 0.2, 0.0)


def primary_metrics(style) -> FontMetrics:
    """The metrics of the first available font of ``style``: the font Pango uses for a
    space with the style's font properties."""
    by_style = vars(style.font_config).setdefault("_platen_metrics_by_style", {})
    key = _font_style_cache_key(style)
    if key not in by_style:
        layout = Layout(style)
        layout.set_text(" ")
        line, _ = layout.get_first_line()
        if line.runs == ffi.NULL:
            by_style[key] = _NO_TABLES
        else:
            by_style[key] = _font_metrics(style.font_config, line.runs.data.item.analysis.font)
        layout.deactivate()
    return by_style[key]


def primary_has_char(style, char: str) -> bool:
    """Whether the first available font of ``style`` has a glyph for ``char``: Pango
    shapes ``char`` with the font it uses for a space, not with a fallback font.

    Cached per font configuration and character, like primary_metrics()."""
    by_style = vars(style.font_config).setdefault("_platen_coverage_by_style", {})
    key = _font_style_cache_key(style), char
    if key not in by_style:
        primary, shaped = Layout(style), Layout(style)
        primary.set_text(" ")
        shaped.set_text(char)
        primary_runs = primary.get_first_line()[0].runs
        shaped_runs = shaped.get_first_line()[0].runs
        by_style[key] = (
            ffi.NULL not in (primary_runs, shaped_runs)
            and _face_address(primary_runs.data.item.analysis.font)
            == _face_address(shaped_runs.data.item.analysis.font)
            and not shaped_runs.data.glyphs.glyphs[0].glyph & pango.PANGO_GLYPH_UNKNOWN_FLAG
        )
        primary.deactivate()
        shaped.deactivate()
    return by_style[key]


def _face_address(pango_font) -> int:
    hb_face = harfbuzz.hb_font_get_face(pango.pango_font_get_hb_font(pango_font))
    return int(ffi.cast("uintptr_t", hb_face))


def _font_metrics(font_config, pango_font) -> FontMetrics:
    hb_face = harfbuzz.hb_font_get_face(pango.pango_font_get_hb_font(pango_font))
    by_face = vars(font_config).setdefault("_platen_metrics_by_face", {})
    key = int(ffi.cast("uintptr_t", hb_face))
    if key not in by_face:
        # The cache holds a reference to the face, so that no other face gets its address.
        face = ffi.gc(harfbuzz.hb_face_reference(hb_face), harfbuzz.hb_face_destroy)
        by_face[key] = face, _read_metrics(hb_face)
    return by_face[key][1]


def _read_metrics(hb_face) -> FontMetrics:
    head, os2, hhea = (_table(hb_face, tag) for tag in (b"head", b"OS/2", b"hhea"))
    if len(head) < 20 or len(hhea) < 10:
        return _NO_TABLES
    (units_per_em,) = struct.unpack_from(">H", head, 18)
    if not units_per_em:
        return _NO_TABLES
    if len(os2) >= 74 and struct.unpack_from(">H", os2, 62)[0] & USE_TYPO_METRICS:
        ascender, descender, line_gap = struct.unpack_from(">hhh", os2, 68)
    else:
        ascender, descender, line_gap = struct.unpack_from(">hhh", hhea, 4)
        if ascender == descender == 0:
            # An empty hhea: like FreeType (and so Pango), the OS/2 typo values, else the
            # Windows ones (unsigned, the descender below the baseline, no line gap).
            if len(os2) >= 74 and any(struct.unpack_from(">hh", os2, 68)):
                ascender, descender, line_gap = struct.unpack_from(">hhh", os2, 68)
            elif len(os2) >= 78 and any(struct.unpack_from(">HH", os2, 74)):
                win_ascent, win_descent = struct.unpack_from(">HH", os2, 74)
                ascender, descender, line_gap = win_ascent, -win_descent, 0
            else:
                return _NO_TABLES
    return FontMetrics(ascender / units_per_em, -descender / units_per_em, line_gap / units_per_em)


def _table(hb_face, tag: bytes) -> bytes:
    """The table ``tag`` of the face, empty when the font has none."""
    blob = harfbuzz.hb_face_reference_table(hb_face, harfbuzz.hb_tag_from_string(tag, len(tag)))
    try:
        length = ffi.new("unsigned int *")
        data = harfbuzz.hb_blob_get_data(blob, length)
        return b"" if data == ffi.NULL else ffi.unpack(data, length[0])
    finally:
        harfbuzz.hb_blob_destroy(blob)
