"""F6: line metrics from the first available font, like browsers and Prince.

- ``line-height: normal`` is A + D + G of the first available font (fontmetrics), half of
  the line gap above and half below; WeasyPrint dropped the line gap.
- Inline boxes and text boxes get the content area of CSS 2.1 §10.8.1: A + D of the
  first available font, whatever fonts Pango uses for the text. A line with glyphs from a
  fallback font keeps the pitch of the others; WeasyPrint used Pango's extents of the
  run, which grow with a taller fallback font, and a 1em content area for inline boxes.

``strut`` is imported by name into css.units, css.computed_values and layout.inline,
so the patch replaces it there too.
"""

import weasyprint.css.computed_values
import weasyprint.css.units
import weasyprint.layout.inline
import weasyprint.text.line_break
from weasyprint.css.functions import check_math
from weasyprint.layout.percent import percentage
from weasyprint.text.line_break import _font_style_cache_key

from ..fontmetrics import primary_metrics

_STRUT_MODULES = (
    weasyprint.text.line_break,
    weasyprint.css.units,
    weasyprint.css.computed_values,
    weasyprint.layout.inline,
)


def _content_area(style) -> tuple[float, float]:
    """Height of the content area and baseline from its top: (A + D, A) × font size."""
    size = style["font_size"]
    if not size:
        return 0, 0
    metrics = primary_metrics(style)
    return (metrics.ascent + metrics.descent) * size, metrics.ascent * size


def strut(style):
    """Return a tuple of the used value of ``line-height`` and the baseline.

    The baseline is given from the top edge of line height.

    """
    if style["font_size"] == 0:
        return 0, 0

    key = _font_style_cache_key(style, include_size=True)
    if key in style.font_config.strut_layouts:
        return style.font_config.strut_layouts[key]

    text_height, baseline = _content_area(style)
    line_height = style["line_height"]
    if line_height == "normal":
        line_height = text_height + primary_metrics(style).line_gap * style["font_size"]
    elif check_math(line_height):
        line_height = percentage(line_height, style, style["font_size"])
    elif isinstance(line_height, float):
        line_height *= style["font_size"]
    else:
        line_height = line_height.value
    result = line_height, baseline + (line_height - text_height) / 2
    style.font_config.strut_layouts[key] = result
    return result


def apply() -> None:
    line_break = weasyprint.text.line_break
    inline = weasyprint.layout.inline
    original_first_line_metrics = line_break.first_line_metrics

    def first_line_metrics(first_line, text, layout, resume_at, space_collapse,
                           style, hyphenated=False, hyphenation_character=None):
        layout, length, resume_at, width, _, _ = original_first_line_metrics(
            first_line, text, layout, resume_at, space_collapse, style, hyphenated,
            hyphenation_character)
        height, baseline = _content_area(style)
        return layout, length, resume_at, width, height, baseline

    def _adjust_line_height(box):
        """Set margins to the half leading to respect line height.

        Also compensate for borders and padding, we want margin_height() == line_height.

        """
        line_height, box.baseline = strut(box.style)
        box.height, _ = _content_area(box.style)
        half_leading = (line_height - box.height) / 2
        box.margin_top = half_leading - box.border_top_width - box.padding_top
        box.margin_bottom = half_leading - box.border_bottom_width - box.padding_bottom

    line_break.first_line_metrics = first_line_metrics
    for module in _STRUT_MODULES:
        module.strut = strut
    inline._adjust_line_height = _adjust_line_height
