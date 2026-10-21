"""F8: justified text may shrink word spaces to 5/6 of their width, like Prince (and
InDesign and TeX); WeasyPrint only ever stretched them.

- Line breaking: in blocks with ``text-align: justify`` (spaces that collapse and wrap),
  each text box is split with every space (and no-break space) MIN_SPACE of its natural
  width: ``justification_spacing`` is −(1 − MIN_SPACE) × the space advance of the box's
  font, and the box keeps it. WeasyPrint passed ``justification_spacing`` on but measured
  without it; Layout.set_text now measures with a negative one. Breaking stays greedy
  (first fit), and hyphenation tries the next word in the space left by the compressed
  line, that is compression comes before hyphenation.
- Alignment: a line with compressed boxes gets its natural spaces back, then a justified
  line is set to exactly the available width (``justify_line`` with negative extra space
  when it is too wide); a line aligned otherwise (the last line, unless
  ``text-align-last: justify``) keeps its natural spaces when it fits and is compressed to
  fit when it does not. A line too wide even when compressed stays compressed.

Preferred widths (min-content and max-content) keep natural spaces: only the line layout
of split_text_box compresses, and only the boxes it returns carry the negative spacing.
"""

import weasyprint.layout.inline
from weasyprint.formatting_structure import boxes
from weasyprint.text.line_break import Layout, _font_style_cache_key, line_size

MIN_SPACE = 5 / 6

_COLLAPSE = ("normal", "nowrap", "pre-line")
_COLLAPSE_AND_WRAP = ("normal", "pre-line")


def _compressible(style) -> bool:
    return style["text_align_all"] == "justify" and style["white_space"] in _COLLAPSE_AND_WRAP


def _space_shrink(style) -> float:
    """How much a word space in ``style`` may give up: (1 − MIN_SPACE) × the advance of a
    space in the style's font, without letter and word spacing, in px.

    Cached per font configuration, by the font-selecting properties and the font size."""
    by_style = vars(style.font_config).setdefault("_platen_space_shrink", {})
    key = _font_style_cache_key(style), style["font_size"]
    if key not in by_style:
        plain = style.copy()
        plain["letter_spacing"] = "normal"
        plain["word_spacing"] = 0
        layout = Layout(plain)
        layout.set_text(" ")
        line, _ = layout.get_first_line()
        width, _ = line_size(line, plain)
        layout.deactivate()
        by_style[key] = (1 - MIN_SPACE) * width
    return by_style[key]


def _has_compressed(box) -> bool:
    if isinstance(box, boxes.TextBox):
        return box.justification_spacing < 0
    if isinstance(box, (boxes.LineBox, boxes.InlineBox)):
        return any(_has_compressed(child) for child in box.children if child.is_in_normal_flow())
    return False


def _restore_spaces(box, x_advance: float) -> float:
    """Give the compressed text boxes of ``box`` their natural spaces back, moving the boxes
    after them; like WeasyPrint's add_word_spacing. Return the new ``x_advance``."""
    if isinstance(box, boxes.TextBox):
        box.position_x += x_advance
        if box.justification_spacing < 0:
            extra = -box.justification_spacing * weasyprint.layout.inline.count_expandable_spaces(box)
            box.justification_spacing = 0
            box.pango_layout.justification_spacing = 0
            box.width += extra
            x_advance += extra
    elif isinstance(box, (boxes.LineBox, boxes.InlineBox)):
        box.position_x += x_advance
        previous_x_advance = x_advance
        children = box.children
        if box.style["direction"] == "rtl":
            children = children[::-1]
        for child in children:
            if child.is_in_normal_flow():
                x_advance = _restore_spaces(child, x_advance)
        box.width += x_advance - previous_x_advance
    else:
        # Atomic inline-level box
        box.translate(x_advance, 0)
    return x_advance


def _justified(line, last: bool) -> bool:
    """Whether ``text_align`` justifies ``line``."""
    align = line.style["text_align_all"]
    if last and line.style["text_align_last"] != "auto":
        align = line.style["text_align_last"]
    return align == "justify" and line.style["white_space"] in _COLLAPSE


def apply() -> None:
    inline = weasyprint.layout.inline
    original_split_text_box = inline.split_text_box
    original_text_align = inline.text_align
    original_set_text = Layout.set_text

    def set_text(self, text, justify=False):
        # A negative justification spacing is a compressed line: measure with it.
        original_set_text(self, text, justify or self.justification_spacing < 0)

    def split_text_box(context, box, available_width, skip, is_line_start=True):
        spacing = box.justification_spacing
        if available_width is not None and spacing == 0 and _compressible(box.style):
            spacing = -_space_shrink(box.style)
        if spacing >= 0:
            return original_split_text_box(context, box, available_width, skip, is_line_start)
        text = box.text.encode()[skip:]
        # Pango spaces a lone space only with a zero-width space after it, which would
        # end up in the box's text: measure it at its natural width and subtract.
        lone_space = text == b" "
        previous_spacing = box.justification_spacing
        box.justification_spacing = 0 if lone_space else spacing
        try:
            new_box, resume_index, preserved_line_break = original_split_text_box(
                context, box, available_width, skip, is_line_start)
            if (text.startswith(b" ") and not lone_space and new_box is not None
                    and available_width is not None and new_box.width > available_width):
                # WeasyPrint doubles the spacing of a space that starts the text, as Pango
                # gives half of it at the start of a line, but Pango breaks lines with all
                # of it: this line may be up to one spacing too wide. Break again with one
                # spacing less, as Pango then sees the line as wide as it is.
                retry = original_split_text_box(
                    context, box, available_width + spacing, skip, is_line_start)
                if retry[0] is not None and retry[0].width <= available_width:
                    new_box, resume_index, preserved_line_break = retry
        finally:
            box.justification_spacing = previous_spacing
        if new_box is not None:
            if lone_space:
                new_box.width += spacing
            new_box.justification_spacing = spacing
            new_box.pango_layout.justification_spacing = spacing
        return new_box, resume_index, preserved_line_break

    def text_align(context, line, available_width, last):
        """Return the line horizontal offset according to ``text-align``."""
        if not _has_compressed(line):
            return original_text_align(context, line, available_width, last)
        if line.width > available_width:
            return 0
        _restore_spaces(line, 0)
        if not _justified(line, last) and line.width <= available_width:
            return original_text_align(context, line, available_width, last)
        inline.justify_line(context, line, available_width - line.width)
        return 0

    Layout.set_text = set_text
    inline.split_text_box = split_text_box
    inline.text_align = text_align
