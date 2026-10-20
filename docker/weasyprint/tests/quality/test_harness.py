"""The test helpers in tests/support.py: units, grouping and PDF inspection.

The expectations hold with and without the quality layer, so they keep checking the
helpers while the layer grows.
"""

import io
from itertools import pairwise

import pytest
from fontTools.ttLib import TTFont
from pypdf import PdfReader, PdfWriter
from pypdf.generic import DictionaryObject, NameObject
from pytest import approx

from support import (
    FACE,
    REPO,
    TESTS,
    baselines,
    content,
    font_url,
    fontconfig_files,
    lines,
    margin_boxes,
    page_group_cs,
    pdf,
    pdf_fonts,
    render,
    runs,
)

PAGE = "@page { size: 200pt 300pt; margin: 20pt 30pt } body { margin: 0 }"
PARAGRAPH = "<p style='margin: 0; font: 20px/30px \"DejaVu Sans\"'>ab <b>cd</b><br>ef gh<br>ij</p>"


def advance(file, text, font_size):
    """Sum of the glyph advances of ``text`` in pt, without kerning."""
    font = TTFont(font_url(file).removeprefix("file://"))
    cmap, metrics = font.getBestCmap(), font["hmtx"].metrics
    units = sum(metrics[cmap[ord(char)]][0] for char in text)
    return units / font["head"].unitsPerEm * font_size


def test_runs_are_text_boxes_in_document_order_in_pt():
    first, second, *_ = runs(render(f"<style>{PAGE}</style>{PARAGRAPH}"))
    assert (first.text, second.text) == ("ab ", "cd")
    assert first.x == approx(30) and first.font_size == approx(15)
    assert first.width == approx(advance("DejaVuSans.ttf", "ab ", 15), abs=0.01)
    assert second.x == approx(first.x + first.width)
    assert second.width == approx(advance("DejaVuSans-Bold.ttf", "cd", 15), abs=0.01)
    assert second.baseline == approx(first.baseline)


def test_lines_run_top_to_bottom_and_baselines_follow_line_height():
    css = f"{PAGE} @page {{ @top-center {{ content: 'H' }} @bottom-center {{ content: 'F' }} }}"
    doc = render(f"<style>{css}</style>{PARAGRAPH}")
    # The margin boxes follow the page content in the box tree; lines() sorts by position.
    assert [[run.text for run in line] for line in lines(doc)] == [
        ["H"], ["ab ", "cd"], ["ef gh"], ["ij"], ["F"]]
    body = baselines(doc)[1:4]
    assert [round(below - above, 6) for above, below in pairwise(body)] == [22.5, 22.5]
    assert body[0] == approx(lines(doc)[1][0].baseline)


def test_margin_boxes_are_keyed_by_at_keyword_with_border_and_content_box_in_pt():
    css = (f"{PAGE} @page {{ @top-center {{ content: 'T'; width: 100pt; padding: 0 5pt }}"
           " @bottom-right { content: 'B' } }")
    found = margin_boxes(render(f"<style>{css}</style>x"))
    assert set(found) == {"top-center", "bottom-right"}
    top = found["top-center"]
    assert (top.x, top.y, top.width, top.height) == approx((45, 0, 110, 20))
    assert (top.content_x, top.content_width) == approx((50, 100))
    assert (found["bottom-right"].y, found["bottom-right"].height) == approx((280, 20))


def test_pdf_fonts_are_postscript_names_of_the_fonts_each_page_uses():
    data = pdf(
        "<p style='font-family: \"Liberation Sans\"'>a</p>"
        "<p style='break-before: page; font: bold 10pt \"Liberation Sans\"'>b "
        "<span style='font-family: \"DejaVu Sans\"; font-weight: normal'>c</span></p>")
    assert pdf_fonts(data, 0) == {"LiberationSans"}
    assert pdf_fonts(data, 1) == {"LiberationSans-Bold", "DejaVuSans"}


def test_pdf_fonts_and_content_include_form_xobjects():
    data = pdf("<p style='opacity: .5; font-family: \"DejaVu Sans\"'>x</p>")
    assert pdf_fonts(data) == {"DejaVuSans"}
    operators = content(data)
    # The page stream paints the transparency group; the group's stream holds the text.
    assert " Do" in operators and operators.index(" Do") < operators.index(" Tf")


def test_face_is_the_ascii_only_platen_test_font():
    assert pdf_fonts(pdf(FACE + "<p style='font-family: \"Platen Test\"'>ab</p>")) == {
        "PlatenTest-Regular"}
    with_fallback = pdf_fonts(pdf(FACE + "<p style='font-family: \"Platen Test\"'>ab ž</p>"))
    assert "PlatenTest-Regular" in with_fallback and len(with_fallback) == 2


def test_font_url_finds_system_and_test_fonts():
    assert font_url("DejaVuSans.ttf") == "file:///usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
    assert font_url("LiberationSerif-Bold.ttf") == (
        "file:///usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf")
    assert font_url("platen-test.woff") == (REPO / "tests/Support/fonts/platen-test.woff").as_uri()
    with pytest.raises(FileNotFoundError, match="NoSuchFont.ttf"):
        font_url("NoSuchFont.ttf")
    serif = font_url("DejaVuSerif.ttf")
    face = f"<style>@font-face {{ font-family: X; src: url({serif}) }}</style>"
    assert pdf_fonts(pdf(face + "<p style='font-family: X'>x</p>")) == {"DejaVuSerif"}


def test_page_group_cs_reads_the_page_group_colour_space():
    plain = pdf("<p>x</p>")
    assert page_group_cs(plain) is None
    writer = PdfWriter(clone_from=PdfReader(io.BytesIO(plain)))
    writer.pages[0][NameObject("/Group")] = DictionaryObject({
        NameObject("/S"): NameObject("/Transparency"),
        NameObject("/CS"): NameObject("/DeviceCMYK"),
    })
    grouped = io.BytesIO()
    writer.write(grouped)
    assert page_group_cs(grouped.getvalue()) == "/DeviceCMYK"


def test_renders_use_the_test_fontconfig_file():
    files = fontconfig_files()
    assert files[0] == str(TESTS / "fonts.conf")
    platen_conf = REPO / "docker/fontconfig/45-platen.conf"
    if platen_conf.exists():
        assert files.index(str(platen_conf)) < files.index("/etc/fonts/conf.d")
