"""Helpers for the platen-weasyprint tests.

Every length is in pt (1 CSS px = 0.75 pt). Positions are measured from the top-left
corner of the page, y growing downwards, as in the CSS layout. Pages count from 0.

Layout helpers (render, runs, lines, baselines, margin_boxes, texts) read WeasyPrint's box tree;
PDF helpers (pdf_fonts, page_group_cs, content) read the written PDF with pypdf.
"""

import io
import os
import re
from collections.abc import Iterator
from dataclasses import dataclass, field
from pathlib import Path

import weasyprint
from fontTools.ttLib import TTFont, TTLibError
from pypdf import PdfReader
from pypdf.generic import ArrayObject, ContentStream, DictionaryObject, IndirectObject, NameObject
from weasyprint.formatting_structure import boxes
from weasyprint.text.ffi import ffi, fontconfig

TESTS = Path(__file__).resolve().parent
REPO = TESTS.parents[2]

PT_PER_PX = 0.75

FONT_DIRS = (
    Path("/usr/share/fonts/truetype/dejavu"),
    Path("/usr/share/fonts/truetype/liberation"),
    REPO / "tests" / "Support" / "fonts",
)

# An @font-face block for the family "Platen Test": tests/Support/fonts/platen-test.woff,
# Liberation Sans cut down to printable ASCII. It has no U+2010 hyphen and no "ž", so
# text in it needs fallback fonts for those.
FACE = (
    '<style>@font-face { font-family: "Platen Test"; src: url("'
    + (REPO / "tests" / "Support" / "fonts" / "platen-test.woff").as_uri()
    + '") }</style>'
)


def font_url(file: str) -> str:
    """The file:// URL of a font file of the DejaVu or Liberation system fonts or of
    tests/Support/fonts, for @font-face rules."""
    for directory in FONT_DIRS:
        if (directory / file).is_file():
            return (directory / file).as_uri()
    raise FileNotFoundError(f"{file} is in none of {', '.join(map(str, FONT_DIRS))}")


def typo_dejavu(tmp_path: Path) -> str:
    """The file:// URL of a copy of DejaVu Sans, written to ``tmp_path``, with
    USE_TYPO_METRICS (OS/2 fsSelection bit 7) set, for @font-face rules.

    Its line metrics are then the OS/2 typo values (ascender 1556, descender -492, line
    gap 410, of 2048 units per em) instead of the hhea values (1901, -483, 0).
    """
    font = TTFont(font_url("DejaVuSans.ttf").removeprefix("file://"))
    font["OS/2"].fsSelection |= 1 << 7
    path = tmp_path / "DejaVuSans-typo.ttf"
    font.save(path)
    return path.as_uri()


def render(html: str) -> weasyprint.Document:
    """Lay out ``html`` with WeasyPrint's default options."""
    return weasyprint.HTML(string=html).render()


def pdf(html: str) -> bytes:
    """The PDF WeasyPrint writes for ``html``."""
    return render(html).write_pdf()


# Layout


@dataclass(frozen=True)
class Run:
    """A text box: its text, left edge, advance width (with any letter, word and
    justification spacing), baseline position and font size."""

    text: str
    x: float
    width: float
    baseline: float
    font_size: float


@dataclass(frozen=True)
class Box:
    """The border box (x, y, width, height) and the content box's left edge and width.

    ``source`` is the WeasyPrint box they were measured on, for texts().
    """

    x: float
    y: float
    width: float
    height: float
    content_x: float
    content_width: float
    source: boxes.Box | None = field(default=None, repr=False, compare=False)


def runs(doc: weasyprint.Document, page: int = 0) -> list[Run]:
    """Every text box of the page in document order: page content, footnotes, then
    margin boxes. Absolutely positioned and fixed boxes are included."""
    return [_run(box) for box in _boxes(doc, page) if isinstance(box, boxes.TextBox)]


def lines(doc: weasyprint.Document, page: int = 0) -> list[list[Run]]:
    """The text boxes of each line box of the page, for lines with text, top to bottom.

    Lines with the same top keep document order. The text of an inline-block or of
    another atomic inline belongs to that box's own lines, not to the line around it.
    """
    return [line_runs for _, line_runs in _lines(doc, page)]


def baselines(doc: weasyprint.Document, page: int = 0) -> list[float]:
    """The baseline position of each line box of lines(), in the same order."""
    return [(line.position_y + line.baseline) * PT_PER_PX for line, _ in _lines(doc, page)]


def margin_boxes(doc: weasyprint.Document, page: int = 0) -> dict[str, Box]:
    """The page's generated margin boxes, keyed by at-keyword without "@" ("top-center")."""
    return {
        box.at_keyword.removeprefix("@"): Box(
            x=box.border_box_x() * PT_PER_PX,
            y=box.border_box_y() * PT_PER_PX,
            width=box.border_width() * PT_PER_PX,
            height=box.border_height() * PT_PER_PX,
            content_x=box.content_box_x() * PT_PER_PX,
            content_width=box.width * PT_PER_PX,
            source=box,
        )
        for box in doc.pages[page]._page_box.children
        if isinstance(box, boxes.MarginBox)
    }


def texts(box: Box) -> str:
    """The text of the text boxes inside ``box`` (from margin_boxes()), in document order,
    concatenated."""
    return "".join(
        child.text
        for child in box.source.descendants(placeholders=True)
        if isinstance(child, boxes.TextBox)
    )


def _boxes(doc, page):
    # With placeholders=True the walk enters absolutely positioned and fixed boxes too.
    return doc.pages[page]._page_box.descendants(placeholders=True)


def _run(box) -> Run:
    return Run(
        text=box.text,
        x=box.position_x * PT_PER_PX,
        width=box.width * PT_PER_PX,
        baseline=(box.position_y + box.baseline) * PT_PER_PX,
        font_size=box.style["font_size"] * PT_PER_PX,
    )


def _lines(doc, page):
    found = []
    for box in _boxes(doc, page):
        if isinstance(box, boxes.LineBox):
            line_runs = [_run(text_box) for text_box in _text_boxes_of_line(box)]
            if line_runs:
                found.append((box, line_runs))
    return sorted(found, key=lambda line_and_runs: line_and_runs[0].position_y)


def _text_boxes_of_line(box):
    for child in box.children:
        if isinstance(child, boxes.TextBox):
            yield child
        elif isinstance(child, boxes.InlineBox):
            yield from _text_boxes_of_line(child)


# PDF


def pdf_fonts(data: bytes, page: int = 0) -> set[str]:
    """PostScript names (name ID 6) of the fonts that the page's text uses.

    The names come from the embedded font programs, read with fontTools: WeasyPrint
    names a font after its CSS family (BaseFont "/ABCDEF+Liberation-Sans" for every face
    of Liberation Sans), the PostScript name tells the faces apart ("LiberationSans-Bold").
    A font whose program has no name table gives its BaseFont without the subset tag.
    Text in the form XObjects and tiling patterns that the page paints counts too.
    """
    names = set()
    for _, operations, resources in _painted_streams(data, page):
        fonts = _get(resources, "/Font")
        for operands, operator in operations:
            if operator == b"Tf" and fonts is not None and operands[0] in fonts:
                names.add(_postscript_name(fonts[operands[0]]))
    return names


def page_group_cs(data: bytes, page: int = 0) -> str | None:
    """The colour space of the page's transparency group (/Group /CS), e.g. "/DeviceRGB",
    or None without a group or without a group colour space. For an array colour space,
    its family, e.g. "/ICCBased"."""
    group = _get(PdfReader(io.BytesIO(data)).pages[page], "/Group")
    colour_space = None if group is None else _get(group, "/CS")
    if isinstance(colour_space, ArrayObject):
        colour_space = colour_space[0].get_object()
    return None if colour_space is None else str(colour_space)


def content(data: bytes, page: int = 0) -> str:
    """The page's decoded content stream, followed by the content streams of the form
    XObjects (opacity groups, for example) and tiling patterns it paints, recursively.

    Decoded as Latin-1, so that each byte is one character.
    """
    return "\n".join(stream.decode("latin-1") for stream, _, _ in _painted_streams(data, page))


def fontconfig_files() -> list[str]:
    """The fontconfig files and directories that WeasyPrint's renders load, in load order,
    with "." and ".." resolved (symbolic links are kept)."""
    config = ffi.gc(fontconfig.FcInitLoadConfigAndFonts(), fontconfig.FcConfigDestroy)
    files = fontconfig.FcConfigGetConfigFiles(config)
    loaded = []
    while (file := fontconfig.FcStrListNext(files)) != ffi.NULL:
        loaded.append(os.path.normpath(ffi.string(file).decode()))
    return loaded


def _painted_streams(data: bytes, page: int) -> Iterator[tuple[bytes, list, DictionaryObject]]:
    """Yield (decoded data, operations, resources) of the page's content stream, then of
    each form XObject and tiling pattern painted from it, recursively, once each.

    WeasyPrint gives all pages one shared resource dictionary, so what a page uses can
    only be told from its content.
    """
    reader = PdfReader(io.BytesIO(data))
    pdf_page = reader.pages[page]
    if "/Contents" not in pdf_page:
        return
    pending = [(pdf_page["/Contents"], _get(pdf_page, "/Resources") or DictionaryObject())]
    seen = set()
    while pending:
        stream, resources = pending.pop(0)
        parsed = ContentStream(stream, reader)
        operations = parsed.operations
        yield parsed.get_data(), operations, resources
        for operands, operator in operations:
            if operator == b"Do":
                category = "/XObject"
            elif operator in (b"scn", b"SCN") and operands and isinstance(operands[-1], NameObject):
                category = "/Pattern"
            else:
                continue
            named = _get(resources, category)
            if named is None or operands[-1] not in named:
                continue
            reference = named.raw_get(operands[-1])
            painted = reference.get_object()
            key = reference.idnum if isinstance(reference, IndirectObject) else id(painted)
            if key in seen:
                continue
            seen.add(key)
            if category == "/XObject" and painted.get("/Subtype") != "/Form":
                continue  # an image
            if category == "/Pattern" and painted.get("/PatternType") != 1:
                continue  # a shading pattern
            pending.append((painted, _get(painted, "/Resources") or resources))


def _postscript_name(font: DictionaryObject) -> str:
    descendant = font["/DescendantFonts"][0].get_object() if "/DescendantFonts" in font else font
    descriptor = _get(descendant, "/FontDescriptor")
    for key in ("/FontFile2", "/FontFile3"):
        program = None if descriptor is None else _get(descriptor, key)
        if program is None:
            continue
        try:
            names = TTFont(io.BytesIO(program.get_data()), lazy=True).get("name")
        except TTLibError:  # not an sfnt, e.g. a bare CFF program
            names = None
        if names is not None and (name := names.getDebugName(6)):
            return name
    return re.sub(r"^/([A-Z]{6}\+)?", "", str(font.get("/BaseFont", "")))


def _get(dictionary, key):
    """The value of ``key`` in a PDF dictionary, resolved, or None."""
    value = dictionary.get(key)
    return None if value is None else value.get_object()


# Test processes

_layer = None


def use_layer(name: str) -> None:
    """Record the patch layer that this pytest process tests (tests/*/conftest.py).

    The layers patch WeasyPrint for the whole process and the Prince layer comes on top
    of the quality layer, so tests/quality and tests/prince need separate processes.
    """
    global _layer
    if _layer not in (None, name):
        raise RuntimeError(
            f"tests/{_layer} and tests/{name} need separate pytest processes: "
            "their patches apply to the whole process"
        )
    _layer = name


def has_rgb_fill(stream: str, rgb: tuple[float, float, float], tol: float = 0.002) -> bool:
    """Whether the content stream sets a non-stroking RGB colour ("r g b rg") within
    ``tol`` of ``rgb`` on each channel."""
    number = r"(-?\d*\.?\d+)"
    for match in re.finditer(rf"{number}\s+{number}\s+{number}\s+rg\b", stream):
        if all(abs(float(m) - e) <= tol for m, e in zip(match.groups(), rgb)):
            return True
    return False
