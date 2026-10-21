import json
import math
import time
from pathlib import Path

import pytest
from platen_weasyprint.prince import linebreak
from platen_weasyprint.prince.linebreak import Box, Glue, Penalty, badness, breaks, demerits
from pytest import approx
from support import PT_PER_PX, face, lines, render
from weasyprint.formatting_structure import boxes

FIXTURE = json.loads((Path(__file__).parent / "fixtures" / "prince_linebreak.json").read_text(encoding="utf-8"))

# The fixture's documents: DejaVu Sans as "LB" (bold for <b>), 10pt, line-height 1.2, lang en.
HEAD = (
    "<html lang='en'>"
    + face("LB", "normal", 400, "DejaVuSans.ttf") + face("LB", "normal", 700, "DejaVuSans-Bold.ttf")
    + "<style>@page { size: 1400pt 3000pt; margin: 20pt } body { margin: 0; font: 10pt/1.2 LB }"
    " p { margin: 0; text-align: justify } b { font-weight: 700 }</style>"
)


def line_texts(doc, page: int = 0) -> list[str]:
    """The text of each line of the page, soft hyphens dropped and spaces removed (Prince's
    PDF text splits some words, "conf i guration")."""
    return ["".join("".join(run.text for run in line).replace("\u00ad", "").split()) for line in lines(doc, page)]


def paragraph_lines(width: float, html: str, css: str = "") -> list[str]:
    return line_texts(render(f"{HEAD}<p style='width: {width}pt; {css}'>{html}</p>"))


def words_per_line(widths: list[float], space: float, width: float) -> list[int]:
    """Lines of breaks() for words of ``widths`` separated by spaces ``space`` wide, as
    numbers of words."""
    items = []
    for i, word in enumerate(widths):
        items.append(Box(word))
        if i < len(widths) - 1:
            items.append(Glue(space))
    counts, start = [], 0
    for k in breaks(items, width):
        counts.append(sum(isinstance(item, Box) for item in items[start:k]))
        start = k + 1
    return counts


# DejaVu Sans advances at 10pt (2048 units per em): n 1298, i 569, space 651.
NN, II, SPACE = 2 * 1298 * 10 / 2048, 2 * 569 * 10 / 2048, 651 * 10 / 2048


@pytest.mark.parametrize("shortfall, stretch, shrink, expected", [
    (1, 2, 1, 12.5),       # r = 0.5
    (-0.5, 2, 1, 12.5),    # r = -0.5
    (4, 2, 1, 800),        # r = 2: the loosest feasible line
    (8, 2, 1, 6400),       # not capped
    (-1, 2, 1, 100),       # spaces at 5/6
    (0, 0, 0, 0),
    (1, 0, 0, math.inf),   # nothing to stretch
    (-1.01, 2, 1, None),   # overfull
])
def test_badness(shortfall, stretch, shrink, expected):
    assert badness(shortfall, stretch, shrink) == (expected if expected is None else approx(expected))


def test_demerits():
    assert demerits(12.5) == approx(22.5 ** 2)
    assert demerits(0, penalty=50) == approx(10 ** 2 + 50 ** 2)
    assert demerits(0, penalty=50, double_hyphen=True) == approx(10 ** 2 + 50 ** 2 + 3000)


@pytest.mark.parametrize("width, expected", [(262.348, [17, 2]), (262.109, [16, 3])])
def test_worked_example(width, expected):
    # The measurement's worked example: 17 words compressed (r = -0.472, demerits 420) beat 16
    # stretched (r = 0.497, 497) at 262.348pt; at 262.109pt (506 against 466) they do not.
    # The last line costs nothing either way, however loose.
    assert words_per_line([NN] * 17 + [II, II], SPACE, width) == expected


def test_last_line_may_shrink():
    assert breaks([Box(50), Glue(6), Box(50)], 105.5) == [3]       # space at 5.5 of 6: one line
    assert breaks([Box(50), Glue(6), Box(50)], 104.9) == [1, 3]    # it would need 4.9: two lines


def test_two_hyphenated_lines_in_a_row_cost_3000(monkeypatch):
    # Line 1 ends at the only possible break, a hyphen. Line 2 can end at a second hyphen
    # (exactly full, demerits 100 + 2500) or one word earlier (r = 0.79, demerits 3517).
    items = [Box(95), Penalty(5)]
    for i in range(5):
        items += [Box(8.84)] + ([Glue(10)] if i < 4 else [])
    items += [Glue(10), Box(0.8), Penalty(5), Box(20)]
    assert breaks(items, 100) == [1, 11, 15]
    monkeypatch.setattr(linebreak, "DOUBLE_HYPHEN", 0)
    assert breaks(items, 100) == [1, 13, 15]


def test_without_a_feasible_line_the_longest_line_that_fits_is_forced():
    # Two words leave spaces 8 times too wide, three do not fit: never an overfull line.
    assert words_per_line([40] * 4, 4, 100) == [2, 2]


def test_no_break_spaces_are_fixed_width_boxes():
    # A measured paragraph: Prince keeps the no-break spaces at their width while breaking.
    html = "nnnnuin nnn&nbsp;imim&nbsp;mmm ne nnoi nmi iimneoo mn mnnme omi mmomnu"
    assert paragraph_lines(225.84, html) == ["nnnnuinnnnimimmmmnennoinmi", "iimneoomnmnnmeomimmomnu"]


def test_no_break_spaces_keep_their_width_in_justified_lines():
    text = "<span>aa</span>&nbsp;<span>bb</span> " + "cc dd ee ff gg hh " * 6
    first_line = lines(render(f"{HEAD}<p style='width: 300pt'>{text}</p>"))[0]
    (no_break_space,) = (run for run in first_line if run.text == "\u00a0")
    assert first_line[-1].x + first_line[-1].width == approx(20 + 300, abs=0.01)  # justified
    assert no_break_space.width == approx(SPACE, abs=0.001)  # as wide as a space in DejaVu Sans


def test_unsupported_content_keeps_greedy_breaking():
    # The worked example at 262.109pt: Prince sets 16 words on the first line, first fit 17.
    html = " ".join(["nn"] * 17 + ["ii", "ii"])
    inline_block = "<span style='display: inline-block'></span>"
    for content, words in [(html, 16), (html + inline_block, 17)]:
        first_line = lines(render(f"{HEAD}<p style='width: 262.109pt'>{content}</p>"))[0]
        assert len("".join(run.text for run in first_line).split()) == words


def test_plan_survives_page_breaks():
    # A measured paragraph of 20 lines, 15 of which first fit breaks differently, over 4 pages.
    vector = next(vector for vector in FIXTURE["vectors"] if vector["id"] == "L0")
    style = "<style>@page { size: 600pt 100pt; margin: 10pt }</style>"
    doc = render(f"{HEAD}{style}<p style='width: {vector['width_pt']}pt'>{vector['html']}</p>")
    assert len(doc.pages) == 4
    paged = [line for page in range(len(doc.pages)) for line in line_texts(doc, page)]
    assert paged == ["".join(line.split()) for line in vector["prince_lines"]]


def test_lines_beside_a_float_fall_back_and_fit():
    text = " ".join(["nnnn imim mm"] * 30)
    doc = render(f"{HEAD}<div style='width: 300pt'><div style='float: left; width: 80pt; height: 40pt'></div>"
                 f"<p>{text}</p></div>")
    (p,) = (box for box in doc.pages[0]._page_box.descendants()
            if isinstance(box, boxes.BlockBox) and box.element_tag == "p")
    right = (p.content_box_x() + p.width) * PT_PER_PX
    assert all(line[-1].x + line[-1].width <= right + 0.01 for line in lines(doc))
    assert any(line[0].x > 20 + 79 for line in lines(doc))   # some lines are beside the float


@pytest.mark.parametrize("vector", [
    pytest.param(vector, id=vector["id"], marks=[pytest.mark.xfail(reason=vector["xfail"], strict=True)] if "xfail" in vector else [])
    for vector in FIXTURE["vectors"]
])
def test_breaks_like_prince(vector):
    expected = ["".join(line.split()) for line in vector["prince_lines"]]
    assert paragraph_lines(vector["width_pt"], vector["html"], vector["css"]) == expected


def test_rendering_time(monkeypatch):
    # 16 justified paragraphs of a certificate page: 8pt, line-height 1.6, 470pt wide.
    words = ("assessment candidate demonstrated exceptional understanding of complex texts "
             "reasoning from limited information and solving problems in new situations with a "
             "high degree of accuracy and speed while the results remain stable across").split()
    paragraphs = "".join(
        f"<p>{' '.join(words[(i * 7 + j) % len(words)] for j in range(60 + 5 * (i % 4)))}.</p>" for i in range(16))
    html = (f"{HEAD}<style>p {{ width: 470pt; font-size: 8pt; line-height: 1.6; margin-bottom: 6pt }}"
            f"</style>{paragraphs}")

    def best_of(count):
        timings = []
        for _ in range(count):
            start = time.perf_counter()
            render(html)
            timings.append(time.perf_counter() - start)
        return min(timings)

    planned = best_of(5)
    monkeypatch.setattr(linebreak, "_planned_line", lambda *args: None)
    greedy = best_of(5)
    assert planned <= 1.2 * greedy + 0.01, (planned, greedy)
