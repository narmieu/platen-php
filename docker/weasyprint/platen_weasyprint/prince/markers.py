"""F10: list markers as Prince sets them.

- A marker that a counter style generates has no trailing space: "•", "1." (WeasyPrint
  appends the counter style's suffix, "• ", "1. ").
- The gap between marker and content is a fixed 8pt at every font size. An outside
  marker's right edge is 8pt left of the list item's content box; WeasyPrint puts it at
  the list item's border box, the suffix's space making a gap of about 0.21em. An inside
  marker is followed by 8pt, then the text.
- Right-to-left list items mirror both: an outside marker's left edge is 8pt right of the
  content box, an inside marker has its 8pt on its left.
- Marker text that the author wrote keeps its spaces: a string ``list-style-type``
  ("→ ") and ``::marker { content: … }``. Its marker is placed with the same 8pt gap, as
  are image markers.

``block_container_layout`` is imported by name into layout.page and layout.table, so the
patch replaces it there too; layout.absolute, layout.float and layout.inline import it
from layout.block when they call it. Outside markers are placed only there: absolute
layout keeps their static position (``absolute_width`` aligns an outside marker's right
edge, or its left edge right-to-left, with it).
"""

import weasyprint.layout.block
import weasyprint.layout.page
import weasyprint.layout.table
from weasyprint.css.properties import Dimension
from weasyprint.css.units import LENGTHS_TO_PIXELS
from weasyprint.formatting_structure import boxes, build

GAP = 8 * LENGTHS_TO_PIXELS["pt"]

# Modules that call block_container_layout through their own name for it.
_LAYOUT_MODULES = (
    weasyprint.layout.block,
    weasyprint.layout.page,
    weasyprint.layout.table,
)


def apply() -> None:
    original_marker_to_box = build.marker_to_box
    original_block_container_layout = weasyprint.layout.block.block_container_layout

    def marker_to_box(element, state, parent_style, style_for, *args, **kwargs):
        """Yield the ::marker box, its counter text without trailing spaces and, inside,
        followed by the gap."""
        for marker_box in original_marker_to_box(
                element, state, parent_style, style_for, *args, **kwargs):
            style = style_for(element, "marker")
            if style is None:
                style = parent_style.anonymous_style
            if _has_counter_text(style, marker_box):
                text_box = marker_box.children[0]
                text_box.text = text_box.text.rstrip() or text_box.text
            if not marker_box.is_outside_marker:
                # The marker's style is shared with the boxes of its ::marker content.
                marker_box.style = marker_box.style.copy()
                side = "margin_left" if marker_box.style["direction"] == "rtl" else "margin_right"
                marker_box.style[side] = Dimension(GAP, "px")
            yield marker_box

    def block_container_layout(context, box, *args, **kwargs):
        """Lay out a block container, its outside marker GAP before its content box."""
        result = original_block_container_layout(context, box, *args, **kwargs)
        new_box = result[0]
        if new_box is not None:
            for child in new_box.children:
                if child.is_outside_marker:
                    _place_outside_marker(new_box, child)
        return result

    build.marker_to_box = marker_to_box
    for module in _LAYOUT_MODULES:
        module.block_container_layout = block_container_layout


def _has_counter_text(style, marker_box) -> bool:
    """Whether ``marker_box`` (with ::marker style ``style``) holds text that a counter
    style generated, not the author's ``content`` or string ``list-style-type``."""
    list_style_type = style["list_style_type"]
    author_string = isinstance(list_style_type, tuple) and list_style_type[0] == "string"
    return (
        style["content"] in ("normal", "inhibit") and not author_string
        and len(marker_box.children) == 1 and isinstance(marker_box.children[0], boxes.TextBox)
    )


def _place_outside_marker(box, placeholder) -> None:
    """Move the static position of ``box``'s outside marker GAP outside its content box.

    WeasyPrint set it to the border box's left edge, or right-to-left to
    ``border_box_x() + width + padding_right``; the marker may be laid out already when
    ``box`` is its containing block, so it is translated rather than set.
    """
    if placeholder.style["direction"] == "rtl":
        placed = box.border_box_x() + box.width + box.padding_right
        wanted = box.content_box_x() + box.width + GAP
    else:
        placed = box.border_box_x()
        wanted = box.content_box_x() - GAP
    placeholder.translate(dx=wanted - placed)
