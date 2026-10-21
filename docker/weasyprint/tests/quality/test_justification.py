from pytest import approx
from support import PT_PER_PX, lines, render, runs
from weasyprint.formatting_structure import boxes

FONT = "font: 10pt 'DejaVu Sans'"
PAGE = "<style>@page { size: 600pt 800pt; margin: 0 } p { margin: 0 }</style>"


def _run_width(text: str) -> float:
    (run,) = runs(render(f'{PAGE}<p style="{FONT}">{text}</p>'))
    return run.width


# W and S: the width of "nnnn" and of a space, measured in a left-aligned paragraph.
W = _run_width("nnnn")
S = _run_width("nnnn nnnn") - 2 * W
natural = 10 * W + 9 * S          # ten words on one line, natural spaces
budget = 9 * S / 6                # what nine spaces may give up: 1/6 each


def paragraph(width: float, text: str, align: str = "justify", css: str = ""):
    return render(f'{PAGE}<p style="text-align: {align}; width: {width}pt; {FONT}; {css}">{text}</p>')


def words_on_first_line(width: float, align: str = "justify") -> int:
    return len("".join(run.text for run in lines(paragraph(width, " ".join(["nnnn"] * 20), align))[0]).split())


def single_line_gaps(width: float) -> float:
    """The width of each of the nine spaces of a paragraph of ten words that fits on one line."""
    (line,) = lines(paragraph(width, " ".join(["nnnn"] * 10)))
    (run,) = line
    return (run.width - 10 * W) / 9


def box_right_of(doc) -> float:
    """The right edge of the content box of the document's p element, in pt."""
    (p,) = (box for box in doc.pages[0]._page_box.descendants()
            if isinstance(box, boxes.BlockBox) and box.element_tag == "p")
    return (p.content_box_x() + p.width) * PT_PER_PX


TEXT = ("Kandidat je pokazao izuzetnu sposobnost razumevanja složenih tekstova, "
        "zaključivanja na osnovu ograničenih informacija i rešavanja problema "
        "u novim situacijama, sa visokim stepenom tačnosti i brzine rada. ") * 3 + "Rezultat je stabilan."

_justified = paragraph(180, TEXT)
justified_lines = lines(_justified)
box_right = box_right_of(_justified)


def last_line_right(align_last: str) -> float:
    last = lines(paragraph(180, TEXT, css=f"text-align-last: {align_last}"))[-1]
    assert " " in "".join(run.text for run in last)   # a last line that can be justified
    return last[-1].x + last[-1].width


def test_fits_when_shrink_is_within_one_sixth():
    assert words_on_first_line(width=natural - 0.99 * budget) == 10


def test_wraps_when_shrink_exceeds_one_sixth():
    assert words_on_first_line(width=natural - 1.01 * budget) == 9


def test_justified_lines_end_at_the_right_edge():
    assert len(justified_lines) > 3
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


def test_spaces_between_inline_elements_shrink_too():
    # Each space between two spans is a text box of its own.
    (line,) = lines(paragraph(natural - 0.5 * budget, " ".join(["<span>nnnn</span>"] * 10)))
    words = [run for run in line if run.text.strip()]
    gaps = [b.x - (a.x + a.width) for a, b in zip(words, words[1:])]
    assert gaps == approx([(natural - 0.5 * budget - 10 * W) / 9] * 9, abs=0.01)


def test_text_after_an_inline_element_never_overflows():
    # The text box after </i> starts with a space, which Pango breaks with twice its spacing.
    overflowing = []
    for tenth in range(600, 650):
        doc = paragraph(tenth / 10, "<i>ff</i> " + " ".join(["nnnn"] * 5))
        if any(l[-1].x + l[-1].width > box_right_of(doc) + 0.01 for l in lines(doc)):
            overflowing.append(tenth / 10)
    assert overflowing == []


def test_word_after_an_inline_element_moves_to_the_next_line_whole():
    # The text box after </span> starts with a space and its first word does not fit: the
    # line ends at that space, as at natural spacing.
    overflowing = []
    for tenth in range(1545, 1555):
        doc = paragraph(tenth / 10, '<span style="font-size: 14pt">extraordinary</span> information <b>of</b> x')
        if any(l[-1].x + l[-1].width > box_right_of(doc) + 0.01 for l in lines(doc)):
            overflowing.append(tenth / 10)
    assert overflowing == []


def test_text_after_an_inline_element_and_nbsp_never_overflows():
    # WeasyPrint doubles the spacing of a no-break space that starts a text box too.
    overflowing = []
    for tenth in range(1330, 1340):
        doc = paragraph(tenth / 10, "<b>ff</b>&nbsp;evaluation extraordinary <b>information</b> x")
        if any(l[-1].x + l[-1].width > box_right_of(doc) + 0.01 for l in lines(doc)):
            overflowing.append(tenth / 10)
    assert overflowing == []


def test_text_align_last_justify_fills_last_line():
    assert last_line_right(align_last="justify") == approx(box_right, abs=0.01)
