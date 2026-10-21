import pytest
from platen_weasyprint.prince.font_matching import css3_match
from support import face, pdf, pdf_fonts

CASES = {
    frozenset({200, 300, 400, 500, 700, 900}): {100: 200, 600: 700, 800: 900},
    frozenset({200, 300, 500, 700}): {100: 200, 400: 500, 600: 700, 800: 700, 900: 700},
    frozenset({200, 400, 500, 700}): {100: 200, 300: 200, 600: 700, 800: 700, 900: 700},
    frozenset({200, 700}): {
        100: 200, 300: 200, 400: 200, 500: 700, 600: 700, 800: 700, 900: 700},
}


@pytest.mark.parametrize(
    "available, desired, expected",
    [(a, d, e) for a, m in CASES.items() for d, e in m.items()])
def test_css3_match_reproduces_prince(available, desired, expected):
    assert css3_match(desired, available) == expected


def test_a_range_containing_the_weight_matches_it():
    assert css3_match(500, [200, (300, 600)]) == 500


def test_a_range_competes_with_its_nearer_endpoint():
    assert css3_match(500, [200, (600, 900)]) == 600
    assert css3_match(300, [(600, 900), 700]) == 600


def test_italic_500_uses_the_700_face_under_prince():
    faces = (face("W", "italic", 200, "LiberationSans-Regular.ttf")
             + face("W", "italic", 700, "DejaVuSans-Bold.ttf"))
    assert pdf_fonts(pdf(faces + '<i style="font: italic 500 10pt W">x</i>')) == {
        "DejaVuSans-Bold"}
