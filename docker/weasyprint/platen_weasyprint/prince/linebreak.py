"""F8b: justified paragraphs break into lines as in Prince 15.1, which optimises the whole
paragraph (Knuth–Plass) where the quality layer breaks greedily (F8, first fit).

The model, measured on DocRaptor (TeX's formulas with Prince's constants):

- A word space (U+0020) is glue: its natural width w may stretch by w/2 and shrink by w/6.
  Letters, no-break spaces (U+00A0), inline margins, borders and padding, and
  ``text-indent`` are boxes of fixed width.
- A hyphenation point is a penalty of 50, flagged: a soft hyphen or a ``hyphens: auto``
  point of the active dictionary (as wide as the hyphen), or the break after an explicit
  "-" (no width).
- A line's badness is 100·|r|³, r being its adjustment over the stretch (or shrink) of its
  spaces, uncapped. A line is feasible up to badness 800 (spaces at most twice their
  width) and never shrinks beyond its shrink. Its demerits are (10 + b)² + p², plus 3000
  when the line before also ends with a hyphen; no other demerits.
- The last line costs nothing when it fits, also under ``text-align-last: justify``; it
  may shrink, and then has the shrink's badness. A forced break (``<br>``, a preserved
  newline) ends the paragraph for this purpose.
- When no feasible line reaches a break, the longest line that fits is forced, from the
  earlier break with the lowest total demerits: never an overfull line.

WeasyPrint lays out one line at a time, so the patch plans, then forces:

- Plan: get_next_linebox is wrapped. For a line box that justifies (``text-align:
  justify``, white space that collapses and wraps), the paragraph's content from the
  line's start becomes items (widths from Pango, one layout per text box), breaks() finds
  the optimal breaks, and the line ends are cached per line box, width and line start, so
  that the next lines and later layouts of the paragraph (page breaks, widows) follow the
  same plan.
- Force: while a planned line is laid out, split_text_box gives each of its text boxes
  its planned text: the boxes before the break whole, the box with the break up to it
  (with the hyphen), without letting Pango break. Their spaces are compressed further
  than the model allows, so that the line never overflows for WeasyPrint; text_align
  (F8) then restores the spaces and justifies the line to exactly the available width.
- Fallback: content the model does not cover keeps the quality layer's greedy breaking
  for the whole paragraph: atomic inlines, floats and other out-of-flow boxes,
  ``::first-line`` and ``::first-letter``, break opportunities other than spaces and
  hyphens, letter- or word-spacing, right-to-left text, spaces in white space other than
  ``normal`` and ``pre-line``, and a word wider than the line. So does a planned line
  whose actual end or width differs from the plan (a float beside it, a width Pango
  measures differently): that line is laid out again greedily, and the rest of the
  paragraph too.

A no-break space keeps its natural width in justified and compressed lines, as in Prince:
Layout.set_text and count_expandable_spaces leave it out of the justification spacing
(``word-spacing`` still applies to it).
"""

import bisect
import re
from dataclasses import dataclass, field

import weasyprint.layout.inline
import weasyprint.text.line_break
from weasyprint.formatting_structure import boxes
from weasyprint.layout.percent import resolve_percentages
from weasyprint.text.ffi import FROM_UNITS, TO_UNITS, ffi, pango
from weasyprint.text.line_break import (
    Layout, _font_style_cache_key, create_layout, get_log_attrs, line_size)

from ..quality import hyphenation, justification

STRETCH = 1 / 2       # stretchability of a word space, × its width
SHRINK = 1 / 6        # shrinkability of a word space (spaces stay >= 5/6), × its width
TOLERANCE = 800       # largest badness of a feasible line: stretch ratio <= 2
LINE_PENALTY = 10     # TeX's \linepenalty
HYPHEN_PENALTY = 50   # automatic, soft and explicit hyphens alike
DOUBLE_HYPHEN = 3000  # demerits for a hyphenated line after a hyphenated line
FINAL_HYPHEN = 0      # demerits for a hyphen on the second-to-last line (TeX: 5000)

# While a planned line is laid out its spaces are this fraction of their width, less than
# the 5/6 the model allows, so that measurement differences never make it overflow.
_FORCED_SPACE = 1 / 2

# Widths are measured at up to _MEASURE_SCALE times the font size, the text staying under
# _MEASURE_LIMIT px (a character is at most about an em wide; Pango's limit is 2**21 px).
_MEASURE_SCALE = 64
_MEASURE_LIMIT = 2 ** 20

_HYPHENS = ("-", "\u2010")
_SOFT_HYPHEN = "\u00ad"
_INF = float("inf")


@dataclass(frozen=True, slots=True)
class Box:
    """A fixed width: text, a no-break space, an inline box's decoration, text-indent."""

    width: float


@dataclass(frozen=True, slots=True)
class Glue:
    """A word space: stretches by STRETCH and shrinks by SHRINK of its width. A line may
    break at it (dropping it) when it follows a box and is ``breakable``."""

    width: float
    breakable: bool = True


@dataclass(frozen=True, slots=True)
class Penalty:
    """A hyphenation point: a line may end here, ``width`` wider (the hyphen)."""

    width: float
    penalty: float = HYPHEN_PENALTY
    flagged: bool = True


def badness(shortfall: float, stretch: float, shrink: float) -> float | None:
    """The badness of a line ``shortfall`` narrower than the measure (negative: wider),
    whose spaces can stretch by ``stretch`` and shrink by ``shrink``: 100·|r|³, uncapped;
    inf when it should stretch and cannot, None when it is overfull."""
    if shortfall >= 0:
        if shortfall == 0:
            return 0.0
        if stretch <= 0:
            return _INF
        return 100.0 * (shortfall / stretch) ** 3
    if -shortfall > shrink + 1e-9:
        return None
    return 100.0 * (-shortfall / shrink) ** 3 if shrink > 0 else 0.0


def demerits(line_badness: float, penalty: float = 0.0, double_hyphen: bool = False) -> float:
    """The demerits of a line of badness ``line_badness`` ending at a break of penalty
    ``penalty``: (10 + b)² + p², plus DOUBLE_HYPHEN for a second hyphenated line in a row."""
    result = (LINE_PENALTY + line_badness) ** 2 + (penalty * penalty if penalty > 0 else 0.0)
    return result + DOUBLE_HYPHEN if double_hyphen else result


class _Node:
    """A break of a partial solution: item ``position``, the next line starting at item
    ``start``, the total demerits up to it, and whether it ends with a hyphen."""

    __slots__ = ("position", "start", "total", "previous", "flagged", "last_fit")

    def __init__(self, position, start, total, previous, flagged):
        self.position, self.start, self.total = position, start, total
        self.previous, self.flagged, self.last_fit = previous, flagged, None


def breaks(items: list, width: float) -> list[int]:
    """The optimal breaks of ``items`` set in lines ``width`` wide, as Prince chooses them:
    the index of each Glue or Penalty a line ends at, then len(items) (the end).

    Ties go to the later predecessor, as in TeX. When every line to a break is overfull
    and no feasible line reaches it, the line to the previous legal break is forced from
    the dying node with the lowest total demerits, with its real (uncapped) badness; a
    single item wider than the line gets a line of its own.
    """
    n = len(items)
    # Running sums of width, stretch and shrink before each item.
    sum_width, sum_stretch, sum_shrink = [0.0], [0.0], [0.0]
    for item in items:
        w = y = z = 0.0
        if isinstance(item, Box):
            w = item.width
        elif isinstance(item, Glue):
            w, y, z = item.width, item.width * STRETCH, item.width * SHRINK
        sum_width.append(sum_width[-1] + w)
        sum_stretch.append(sum_stretch[-1] + y)
        sum_shrink.append(sum_shrink[-1] + z)

    def legal(k):
        if k == n:
            return True
        item = items[k]
        if isinstance(item, Glue):
            return item.breakable and k > 0 and isinstance(items[k - 1], Box)
        return isinstance(item, Penalty)

    def after(k):
        """The item the next line starts at after a break at ``k``: discarded glue skipped."""
        j = k + 1 if k < n else k
        while j < n and isinstance(items[j], Glue):
            j += 1
        return j

    def measure(start, k):
        w = sum_width[k] - sum_width[start]
        if k < n and isinstance(items[k], Penalty):
            w += items[k].width
        return w, sum_stretch[k] - sum_stretch[start], sum_shrink[k] - sum_shrink[start]

    def line_cost(node, k, b):
        item = items[k] if k < n else None
        is_penalty = isinstance(item, Penalty)
        flagged = is_penalty and item.flagged
        cost = demerits(b, item.penalty if is_penalty else 0.0, flagged and node.flagged)
        if k == n and node.flagged:
            cost += FINAL_HYPHEN
        return cost, flagged

    active = [_Node(-1, 0, 0.0, None, False)]
    k = 0
    while k <= n:
        if not legal(k):
            k += 1
            continue
        best = None
        dying = []
        for node in active:
            if node.start > k:
                continue
            w, y, z = measure(node.start, k)
            if k == n and w <= width:
                b = 0.0  # the last line: TeX's \parfillskip, never loose
            else:
                b = badness(width - w, y, z)
            if b is None:  # overfull even when shrunk: never feasible again
                dying.append(node)
                continue
            node.last_fit = k
            if b > TOLERANCE:
                continue
            cost, flagged = line_cost(node, k, b)
            total = node.total + cost
            if best is None or total <= best.total + 1e-6:  # ties: later predecessor wins
                best = _Node(k, after(k), total, node, flagged)
        for node in dying:
            active.remove(node)
        if best is None and k == n and active:
            # Every line to the end is too loose: force the end from the best node.
            for node in active:
                w, y, z = measure(node.start, k)
                cost, flagged = line_cost(node, k, badness(width - w, y, z))
                if best is None or node.total + cost <= best.total + 1e-6:
                    best = _Node(k, n, node.total + cost, node, flagged)
        if best is None and not active:
            # Every node died at k. Break at the previous legal break, where those lines
            # still fitted, from the dying node with the lowest total demerits, counting
            # the forced line with its real (uncapped) badness.
            candidate = None
            for node in dying:
                p = node.last_fit
                if p is None:
                    continue
                w, y, z = measure(node.start, p)
                if p == n and w <= width:
                    b = 0.0
                else:
                    b = badness(width - w, y, z)
                    b = 0.0 if b is None else b
                cost, flagged = line_cost(node, p, b)
                if candidate is None or node.total + cost < candidate.total:
                    candidate = _Node(p, after(p), node.total + cost, node, flagged)
            if candidate is None:  # an item wider than the line: break right after it
                node = max(dying, key=lambda dead: dead.start)
                candidate = _Node(k, after(k), node.total, node, False)
            active = [candidate]
            if candidate.position >= n:
                break
            k = candidate.position + 1
            continue
        if best is not None:
            active.append(best)
        if k == n:
            break
        k += 1
    end = min((node for node in active if node.position == n), key=lambda node: node.total)
    found = []
    while end.previous is not None:
        found.append(end.position)
        end = end.previous
    return found[::-1]


# Paragraph items from WeasyPrint's boxes


class _Unsupported(Exception):
    """The paragraph has content the model does not cover: keep greedy breaking."""


@dataclass(eq=False)
class _Atom:
    """A text box of the paragraph, from byte ``start`` of its text on; ``path`` holds the
    child indices from the line box down to it."""

    box: boxes.TextBox
    path: tuple[int, ...]
    start: int
    text: str = ""

    def __post_init__(self):
        self.text = self.box.text.encode()[self.start:].decode()


@dataclass(frozen=True, slots=True)
class _Break:
    """Where a line ending at an item ends in the text box of ``atom``: its text up to
    byte ``end`` (plus the hyphen when ``hyphen``), the next line resuming at ``resume``."""

    atom: _Atom
    end: int
    resume: int
    hyphen: bool = False


def _content(linebox, start, containing_block) -> list:
    """The in-flow content of ``linebox`` from the skip stack ``start``, in order: an
    _Atom per text box and the width of each inline box's start and end decoration."""
    content = []

    def walk(box, stack, path):
        index, child_stack = (0, None) if stack is None else next(iter(stack.items()))
        for i in range(index, len(box.children)):
            child = box.children[i]
            child_stack_i = child_stack if i == index else None
            if not child.is_in_normal_flow():
                raise _Unsupported
            if isinstance(child, boxes.TextBox):
                content.append(_Atom(child, path + (i,), next(iter(child_stack_i or {0: None}))))
            elif isinstance(child, boxes.InlineBox) and not child.is_leader:
                if child.style["direction"] == "rtl":
                    raise _Unsupported
                resolve_percentages(child, containing_block)
                left = _side(child, "left")
                right = _side(child, "right")
                if (left or right) and child.style["box_decoration_break"] == "clone":
                    raise _Unsupported
                if child_stack_i is None:
                    content.append(left)
                walk(child, child_stack_i, path + (i,))
                content.append(right)
            else:
                raise _Unsupported

    walk(linebox, start, ())
    return content


def _side(box, side) -> float:
    margin = getattr(box, f"margin_{side}")
    margin = 0 if margin == "auto" else margin
    return margin + getattr(box, f"padding_{side}") + getattr(box, f"border_{side}_width")


def _check(atom) -> bool:
    """Whether the atom's text wraps; raise _Unsupported for text the model does not cover."""
    style = atom.box.style
    wraps = style["white_space"] in ("normal", "pre-line")
    if (style["direction"] == "rtl" or style["letter_spacing"] != "normal"
            or style["word_spacing"] or style["word_break"] != "normal" or not style["font_size"]
            or "\t" in atom.text or ("\n" in atom.text and style["white_space"] != "pre-line")
            or (not wraps and " " in atom.text)):
        raise _Unsupported
    return wraps


def _scaled(style, length: int):
    """(style at a larger font size, the factor) for measuring ``length`` characters.

    Pango rounds glyph advances to its units (1/1024 px): measured at a larger size and
    divided, the widths are those of the font, as Prince uses them, without the rounding
    that adds up over a line. The factor keeps the text's width far from Pango's integer
    limits."""
    size = style["font_size"]
    factor = max(1, min(_MEASURE_SCALE, int(_MEASURE_LIMIT / (max(length, 1) * size))))
    scaled = style.copy()
    scaled["font_size"] = size * factor
    return scaled, factor


class _Positions:
    """The x of byte offsets of an atom's text, at natural spacing, in px: Pango's glyph
    advances in one layout per line of the text."""

    def __init__(self, context, atom):
        offsets, xs = [], []
        base = atom.start
        style, factor = _scaled(atom.box.style, len(atom.text))
        for piece in atom.text.split("\n"):
            encoded = piece.encode()
            if piece:
                layout = create_layout(piece, style, context, None, 0)
                line = pango.pango_layout_get_line_readonly(layout.layout, 0)
                x = 0
                runs = line.runs
                while runs != ffi.NULL:
                    run = runs.data
                    if run.item.analysis.level % 2:
                        layout.deactivate()
                        raise _Unsupported  # right-to-left text in the paragraph
                    glyphs = run.glyphs
                    for i in range(glyphs.num_glyphs):
                        cluster = base + run.item.offset + glyphs.log_clusters[i]
                        if not offsets or cluster > offsets[-1]:
                            offsets.append(cluster)
                            xs.append(x)
                        x += glyphs.glyphs[i].geometry.width
                    runs = runs.next
                layout.deactivate()
            else:
                x = 0
            offsets.append(base + len(encoded))
            xs.append(x)
            base += len(encoded) + 1
        self._offsets = offsets
        self._xs = [x * FROM_UNITS / factor for x in xs]

    def __call__(self, offset: int) -> float:
        """The x of byte ``offset``; inside a cluster (a ligature), the x after it."""
        return self._xs[bisect.bisect_left(self._offsets, offset)]


def _hyphen(style) -> str:
    """The hyphen that split_first_line adds for ``style``."""
    hyphenation._use_auto_hyphen(style)
    return style["hyphenate_character"]


def _hyphen_width(context, style) -> float:
    """The width of the hyphen of ``style``, in px; cached per font configuration."""
    hyphen = _hyphen(style)
    by_style = vars(style.font_config).setdefault("_platen_hyphen_width", {})
    key = _font_style_cache_key(style), style["font_size"], str(hyphen)
    if key not in by_style:
        scaled, factor = _scaled(style, len(hyphen))
        layout = create_layout(hyphen, scaled, context, None, 0)
        line, _ = layout.get_first_line()
        width, _ = line_size(line, scaled)
        by_style[key] = width / factor
        layout.deactivate()
    return by_style[key]


def _space_width(style) -> float:
    """The advance of a word space in ``style``'s font, without spacing, in px."""
    return justification._space_shrink(style) / (1 - justification.MIN_SPACE)


def _hyphenation_points(context, atom, attrs, position) -> set[int]:
    """The byte offsets in the atom's box text where ``hyphens: auto`` may hyphenate: the
    points of the active dictionary in each word (Pango's word boundaries) of at least
    ``hyphenate-limit-chars`` characters without a soft hyphen."""
    style = atom.box.style
    if style["hyphens"] != "auto" or not style["lang"]:
        return set()
    shim = weasyprint.text.line_break.pyphen
    lang = shim.language_fallback(style["lang"])
    if not lang:
        return set()
    total, left, right = style["hyphenate_limit_chars"]
    key = lang, left, right, total
    if key not in context.dictionaries:
        context.dictionaries[key] = shim.Pyphen(lang=lang, left=left, right=right)
    dictionary = context.dictionaries[key]
    points = set()
    word_start = None
    for i in range(len(atom.text) + 1):
        attr = attrs[position + i]
        if attr.is_word_end and word_start is not None and i > word_start:
            word = atom.text[word_start:i]
            if len(word) >= total and _SOFT_HYPHEN not in word:
                for point in dictionary.positions(word):
                    points.add(atom.start + len(atom.text[:word_start + point].encode()))
            word_start = None
        if attr.is_word_start and i < len(atom.text):
            word_start = i
    return points


def _segments(context, linebox, content, indent) -> list[tuple[list, list, _Break | None]]:
    """The items of the paragraph, split at forced breaks: (items, sources, end) for each
    segment. ``sources[i]`` is the _Break of a Glue or Penalty, the _Atom of a Box of text,
    None for other boxes; ``end`` is the _Break of the forced break ending the segment, or
    None at the end of the paragraph."""
    atoms = [entry for entry in content if isinstance(entry, _Atom)]
    text = "".join(atom.text for atom in atoms)
    attrs = get_log_attrs(text, linebox.style["lang"])
    segments = []
    items, sources = [], []
    if indent:
        items.append(Box(indent))
        sources.append(None)
    position = 0  # index in ``text`` of the atom's first character
    previous = None

    def add_box(atom, x, start, end):
        if end > start:
            items.append(Box(x(end) - x(start)))
            sources.append(atom)

    for entry in content:
        if not isinstance(entry, _Atom):
            if entry:
                items.append(Box(entry))
                sources.append(None)
            continue
        atom = entry
        style = atom.box.style
        wraps = _check(atom)  # text that does not wrap (a list marker) is one box
        x = _Positions(context, atom)
        points = _hyphenation_points(context, atom, attrs, position) if wraps else set()
        run_start = byte = atom.start
        for i, char in enumerate(atom.text):
            g = position + i
            size = len(char.encode())
            if char == " ":
                add_box(atom, x, run_start, byte)
                breakable = attrs[g + 1].is_line_break and text[g + 1:g + 2] != " "
                items.append(Glue(x(byte + 1) - x(byte), breakable))
                sources.append(_Break(atom, byte, byte + 1))
                run_start = byte + 1
            elif char == "\n":
                add_box(atom, x, run_start, byte)
                segments.append((items, sources, _Break(atom, byte, byte + 1)))
                items, sources = [], []
                run_start = byte + 1
            elif wraps and g > 0 and attrs[g].is_line_break and text[g - 1] not in " \n":
                mark = text[g - 1]
                if mark not in _HYPHENS and mark != _SOFT_HYPHEN:
                    raise _Unsupported  # a break opportunity Prince was not measured for
                owner = atom if i else previous
                hyphen = mark == _SOFT_HYPHEN
                if not hyphen or owner.box.style["hyphens"] != "none":
                    add_box(atom, x, run_start, byte)
                    end = byte if i else len(owner.box.text.encode())
                    width = _hyphen_width(context, owner.box.style) if hyphen else 0.0
                    items.append(Penalty(width))
                    sources.append(_Break(owner, end, end, hyphen))
                    run_start = byte
            elif byte in points:
                add_box(atom, x, run_start, byte)
                items.append(Penalty(_hyphen_width(context, style)))
                sources.append(_Break(atom, byte, byte, True))
                run_start = byte
            byte += size
        add_box(atom, x, run_start, byte)
        position += len(atom.text)
        previous = atom
    segments.append((items, sources, None))
    # Spaces at the start and the end of a segment are removed from the lines.
    trimmed = []
    for items, sources, end in segments:
        first = 0
        while first < len(items) and isinstance(items[first], Glue):
            first += 1
        last = len(items)
        while last > first and isinstance(items[last - 1], Glue):
            last -= 1
        trimmed.append((items[first:last], sources[first:last], end))
    return trimmed


# Plans


_END = "end"


@dataclass
class _LinePlan:
    """A planned line: the text boxes it lays out (id → None for the whole remaining text,
    else (end, resume, hyphen)) and the key of the next line's start (_END at the end)."""

    boxes: dict
    next: object


@dataclass(eq=False)
class _Paragraph:
    """The plan of a line box at a width: its lines by the key of their start."""

    linebox: boxes.LineBox
    width: float
    lines: dict = field(default_factory=dict)
    greedy: bool = False


@dataclass(eq=False)
class _Forcing:
    """The line being laid out to plan."""

    boxes: dict
    width: float
    diverged: bool = False


def _key(stack):
    """A skip stack as a hashable value."""
    if stack is None:
        return None
    (index, child), = stack.items()
    return index, _key(child)


def _stack(path, offset):
    stack = {offset: None}
    for index in reversed(path):
        stack = {index: stack}
    return stack


def _next_key(linebox, resume_at):
    """The key of the start of the line after one that resumes at ``resume_at``: the
    removable white space skipped, _END when nothing is left."""
    if resume_at is None:
        return _END
    stack = weasyprint.layout.inline.skip_first_whitespace(linebox, resume_at)
    return _END if stack == "continue" else _key(stack)


def _fits(items, start, end, width) -> bool:
    """Whether the line of items[start:end] fits ``width`` with its spaces shrunk."""
    natural = shrink = 0.0
    for item in items[start:end]:
        if isinstance(item, (Box, Glue)):
            natural += item.width
        if isinstance(item, Glue):
            shrink += item.width * SHRINK
    if end < len(items) and isinstance(items[end], Penalty):
        natural += items[end].width
    return natural - shrink <= width + 1e-6


def _plan(context, linebox, start, containing_block, width) -> dict:
    """The lines of the paragraph from the skip stack ``start``, by the key of their start."""
    content = _content(linebox, start, containing_block)
    if not any(isinstance(entry, _Atom) for entry in content):
        raise _Unsupported
    lines = {}
    key = _key(start)
    indent = linebox.text_indent
    for items, sources, end in _segments(context, linebox, content, indent):
        line_start = 0
        for k in breaks(items, width):
            if not _fits(items, line_start, k, width):
                raise _Unsupported  # a word wider than the line
            planned = {}
            for source in sources[line_start:k]:
                atom = source.atom if isinstance(source, _Break) else source
                if atom is not None:
                    planned[id(atom.box)] = None
            if k < len(items):
                cut = sources[k]
                planned[id(cut.atom.box)] = cut.end, cut.resume, cut.hyphen
                resume_at = _stack(cut.atom.path, cut.resume)
            elif end is not None:
                planned[id(end.atom.box)] = None  # split_text_box breaks at the newline
                resume_at = _stack(end.atom.path, end.resume)
            else:
                resume_at = None
            lines[key] = _LinePlan(planned, _next_key(linebox, resume_at))
            key = lines[key].next
            line_start = k + 1
            while line_start < len(items) and isinstance(items[line_start], Glue):
                line_start += 1
    return lines


def _planned_line(context, linebox, skip_stack, containing_block, first_letter_style,
                  first_line_style):
    """(paragraph, line plan) for the line of ``linebox`` starting at ``skip_stack``, or
    None when the line keeps greedy breaking."""
    style = linebox.style
    if not justification._compressible(style) or style["direction"] == "rtl":
        return None
    width = containing_block.width
    paragraphs = vars(context).setdefault("_platen_paragraphs", {})
    paragraph = paragraphs.get((id(linebox), width))
    if paragraph is None or paragraph.linebox is not linebox:
        paragraph = paragraphs[id(linebox), width] = _Paragraph(linebox, width)
    if first_letter_style or first_line_style:
        paragraph.greedy = True
    if paragraph.greedy:
        return None
    start = weasyprint.layout.inline.skip_first_whitespace(linebox, skip_stack)
    if start == "continue":
        return None
    line = paragraph.lines.get(_key(start))
    if line is None:
        try:
            paragraph.lines.update(_plan(context, linebox, start, containing_block, width))
        except _Unsupported:
            paragraph.greedy = True
            return None
        line = paragraph.lines[_key(start)]
    return paragraph, line


def _set_no_break_spaces(layout) -> None:
    """Give the no-break spaces of ``layout`` their spacing without justification, as
    Layout.set_text computes it."""
    style = layout.style
    letter_spacing = style["letter_spacing"]
    letter_spacing = 0 if letter_spacing == "normal" else int(letter_spacing * TO_UNITS)
    spacing = int(style["word_spacing"] * TO_UNITS + letter_spacing)
    bytestring = layout.text.encode()
    boundaries = (0, len(bytestring) - 1)
    attr_list = pango.pango_layout_get_attributes(layout.layout)
    for match in re.finditer("\u00a0".encode(), bytestring):
        factor = 1 + (match.start() in boundaries)
        attr = pango.pango_attr_letter_spacing_new(factor * spacing)
        attr.start_index, attr.end_index = match.start(), match.end()
        pango.pango_attr_list_change(attr_list, attr)
    pango.pango_layout_set_attributes(layout.layout, attr_list)


def count_expandable_spaces(box) -> int:
    """Count the spaces that justification stretches and compresses: word spaces (U+0020),
    not no-break spaces."""
    if isinstance(box, boxes.TextBox):
        return box.text.count(" ")
    if isinstance(box, (boxes.LineBox, boxes.InlineBox)):
        return sum(count_expandable_spaces(child) for child in box.children)
    return 0


def apply() -> None:
    inline = weasyprint.layout.inline
    original_get_next_linebox = inline.get_next_linebox
    previous_split_text_box = inline.split_text_box
    previous_text_align = inline.text_align
    previous_set_text = Layout.set_text

    def set_text(self, text, justify=False):
        previous_set_text(self, text, justify)
        spacing = self.justification_spacing
        if spacing and (justify or spacing < 0) and "\u00a0" in self.text:
            _set_no_break_spaces(self)

    def get_next_linebox(context, linebox, position_y, bottom_space, skip_stack,
                         containing_block, absolute_boxes, fixed_boxes, first_letter_style,
                         first_line_style):
        arguments = (
            context, linebox, position_y, bottom_space, skip_stack, containing_block,
            absolute_boxes, fixed_boxes, first_letter_style, first_line_style)
        planned = _planned_line(
            context, linebox, skip_stack, containing_block, first_letter_style,
            first_line_style)
        if planned is None:
            return original_get_next_linebox(*arguments)
        paragraph, line_plan = planned
        forcing = _Forcing(line_plan.boxes, paragraph.width)
        outer = vars(context).get("_platen_forcing")
        context._platen_forcing = forcing
        try:
            line, resume_at = original_get_next_linebox(*arguments)
        finally:
            context._platen_forcing = outer
        if not forcing.diverged and _next_key(linebox, resume_at) == line_plan.next:
            return line, resume_at
        # Not as planned: this line and the rest of the paragraph break greedily.
        paragraph.greedy = True
        return original_get_next_linebox(*arguments)

    def split_text_box(context, box, available_width, skip, is_line_start=True):
        forcing = vars(context).get("_platen_forcing")
        if forcing is None or forcing.diverged or id(box) not in forcing.boxes:
            return previous_split_text_box(context, box, available_width, skip, is_line_start)
        planned = forcing.boxes[id(box)]
        skip = skip or 0
        if planned is None:
            source, resume_index = box, None
        else:
            end, resume_index, hyphen = planned
            text = box.text.encode()[:end].decode()
            if end < skip or (end == skip and not hyphen):
                return None, resume_index, False
            source = box.copy_with_text(text + _hyphen(box.style) if hyphen else text)
        spacing = -(1 - _FORCED_SPACE) * _space_width(box.style)
        previous_spacing = source.justification_spacing
        source.justification_spacing = spacing
        try:
            new_box, index, preserved_line_break = previous_split_text_box(
                context, source, None, skip, is_line_start)
        finally:
            source.justification_spacing = previous_spacing
        if planned is not None:
            index, preserved_line_break = resume_index, False
        if (available_width is not None and new_box is not None
                and new_box.width > available_width + 1e-6):
            forcing.diverged = True  # narrower than planned: a float, a measurement error
            return previous_split_text_box(context, box, available_width, skip, is_line_start)
        return new_box, index, preserved_line_break

    def text_align(context, line, available_width, last):
        forcing = vars(context).get("_platen_forcing")
        if forcing is not None and abs(available_width - forcing.width) > 1e-6:
            forcing.diverged = True
        return previous_text_align(context, line, available_width, last)

    Layout.set_text = set_text
    inline.get_next_linebox = get_next_linebox
    inline.split_text_box = split_text_box
    inline.text_align = text_align
    inline.count_expandable_spaces = count_expandable_spaces
