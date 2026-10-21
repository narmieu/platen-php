import pytest
from platen_weasyprint.quality import font_matching
from support import face, font_url, pdf, pdf_fonts


@pytest.mark.parametrize("desired, available, expected", [
    (500, {200, 700}, 200), (400, {200, 300, 500, 700}, 500), (300, {200, 400, 500, 700}, 200),
    (600, {200, 300, 400, 500, 700, 900}, 700), (800, {200, 300, 500, 700}, 700), (100, {200, 700}, 200),
    (450, {300, 500}, 500), (450, {300, 600}, 300), (500, {(300, 600), 700}, 500)])
def test_css4_match(desired, available, expected):
    assert font_matching.css4_match(desired, available) == expected


def test_italic_500_uses_the_200_face():
    faces = face("W", "italic", 200, "LiberationSans-Regular.ttf") + face("W", "italic", 700, "DejaVuSans-Bold.ttf")
    assert pdf_fonts(pdf(faces + '<i style="font: 500 10pt W">x</i>')) == {"LiberationSans"}


def test_unicode_range_subsets_of_one_weight_are_both_used():
    faces = (face("U", "normal", 400, "LiberationSans-Regular.ttf", "U+0000-00FF")
             + face("U", "normal", 400, "DejaVuSans.ttf", "U+0100-017F"))
    assert pdf_fonts(pdf(faces + '<p style="font: 400 10pt U">ab ž</p>')) == {"LiberationSans", "DejaVuSans"}


# fontconfig's own nearest weight differs here: 300 is 50 on its scale, 100 is 0, 400 is 80.
def test_300_uses_the_100_face_not_fontconfigs_nearest():
    faces = face("N", "normal", 100, "DejaVuSans.ttf") + face("N", "normal", 400, "LiberationSans-Regular.ttf")
    assert pdf_fonts(pdf(faces + '<p style="font: 300 10pt N">x</p>')) == {"DejaVuSans"}


# The files' own weights (700 and 400) contradict the declared ranges: the ranges decide.
@pytest.mark.parametrize("weight, expected", [(300, "DejaVuSans-Bold"), (700, "LiberationSans")])
def test_weight_ranges_choose_the_face(weight, expected):
    faces = face("R", "normal", (100, 400), "DejaVuSans-Bold.ttf") + face("R", "normal", (500, 900), "LiberationSans-Regular.ttf")
    assert pdf_fonts(pdf(faces + f'<p style="font: {weight} 10pt R">x</p>')) == {expected}


def test_decreasing_weight_range_is_swapped():
    faces = face("D", "normal", (400, 100), "DejaVuSans-Bold.ttf") + face("D", "normal", (500, 900), "LiberationSans-Regular.ttf")
    assert pdf_fonts(pdf(faces + '<p style="font: 100 10pt D">x</p>')) == {"DejaVuSans-Bold"}


# Without a font-weight descriptor a face keeps the file's own weight (DejaVu Sans: 400), and
# fontconfig picks among the faces of that style: 300 is nearer to 400 than to 100 there.
def test_face_without_weight_leaves_its_style_to_fontconfig():
    faces = (f'<style>@font-face {{ font-family: O; src: url("{font_url("DejaVuSans.ttf")}") }}</style>'
             + face("O", "normal", 100, "LiberationSans-Regular.ttf"))
    assert pdf_fonts(pdf(faces + '<p style="font: 300 10pt O">x</p>')) == {"DejaVuSans"}


def test_set_rule_takes_over_for_later_renders():
    faces = face("S", "normal", 100, "DejaVuSans.ttf") + face("S", "normal", 400, "LiberationSans-Regular.ttf")
    html = faces + '<p style="font: 300 10pt S">x</p>'
    assert pdf_fonts(pdf(html)) == {"DejaVuSans"}
    font_matching.set_rule(lambda desired, available: max(w for w in available))
    try:
        assert pdf_fonts(pdf(html)) == {"LiberationSans"}
    finally:
        font_matching.set_rule(font_matching.css4_match)
    assert pdf_fonts(pdf(html)) == {"DejaVuSans"}


def test_system_families_are_left_to_fontconfig():
    faces = face("W", "normal", 100, "DejaVuSans.ttf")
    assert pdf_fonts(pdf(faces + '<p style="font: 700 10pt \'Liberation Sans\', W">x</p>')) == {"LiberationSans-Bold"}
