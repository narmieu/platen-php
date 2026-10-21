"""F7b: superscripts, subscripts and relative font sizes as Prince sets them.

- ``vertical-align: super`` raises the baseline by half the ascender A of the parent's
  first available font times the parent's font size; ``sub`` lowers it by half the
  descender D (fontmetrics, as in F6). Neither depends on the element's own size or on
  line-height. WeasyPrint raises and lowers by half the element's own font size.
- ``sub`` and ``sup`` are 83% of the parent's font size; WeasyPrint's user-agent
  stylesheet makes them ``smaller``. The rule comes after the quality layer's
  ``sub, sup { line-height: inherit }`` (quality.superscripts).
- ``font-size: smaller`` is the parent's font size ÷ 1.2 and ``larger`` × 1.2; WeasyPrint
  steps to the neighbouring absolute-size keyword (smaller 10pt → 9pt, larger → 10.67pt).

Computed styles look the computers up in ``COMPUTER_FUNCTIONS``, the ``@register_computer``
registry of css.computed_values, so the patch replaces them there as well as the module
attributes. Other values keep WeasyPrint's computers.
"""

import weasyprint.css.computed_values as computed_values
from weasyprint.css.properties import INITIAL_VALUES

from ..fontmetrics import primary_metrics
from ..quality import superscripts

_SIZE = "sub, sup { font-size: 83% }"

_RELATIVE_SIZE_FACTOR = 1.2


def apply() -> None:
    original_font_size = computed_values.font_size
    original_vertical_align = computed_values.vertical_align

    def font_size(style, name, value):
        """Compute the ``font-size`` property, ``smaller`` and ``larger`` as Prince does."""
        if value not in ("smaller", "larger"):
            return original_font_size(style, name, value)
        if style.parent_style is None:
            parent_font_size = INITIAL_VALUES["font_size"]
        else:
            parent_font_size = style.parent_style["font_size"]
        if value == "smaller":
            return parent_font_size / _RELATIVE_SIZE_FACTOR
        return parent_font_size * _RELATIVE_SIZE_FACTOR

    def vertical_align(style, name, value):
        """Compute the ``vertical-align`` property, ``super`` and ``sub`` as Prince does:
        from the parent's font metrics and font size."""
        if value not in ("super", "sub"):
            return original_vertical_align(style, name, value)
        parent = style if style.parent_style is None else style.parent_style
        size = parent["font_size"]
        if not size:
            return 0
        metrics = primary_metrics(parent)
        if value == "super":
            return 0.5 * metrics.ascent * size
        return -0.5 * metrics.descent * size

    for function in (font_size, vertical_align):
        computed_values.COMPUTER_FUNCTIONS[function.__name__] = function
        setattr(computed_values, function.__name__, function)
    superscripts.add_user_agent_css(_SIZE)
