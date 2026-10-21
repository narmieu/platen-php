import pytest
from pytest import approx
from support import Run, render, runs

FONT = "font: 12pt 'DejaVu Sans'"


def marker_and_text(html: str) -> tuple[Run, Run]:
    """The text boxes of a one-item list: its marker, then the item's text."""
    marker, item = runs(render(html))
    return marker, item


@pytest.mark.parametrize("size", [8, 12, 16])
@pytest.mark.parametrize("kind, text", [("disc", "•"), ("decimal", "1.")])
def test_outside_marker_ends_8pt_before_content(size, kind, text):
    marker, item = marker_and_text(f'<ul style="list-style-type: {kind}; margin-left: 100pt; padding: 0; font: {size}pt \'DejaVu Sans\'"><li>item</li></ul>')
    assert marker.text == text and marker.x + marker.width == approx(item.x - 8, abs=0.01)


@pytest.mark.parametrize("size", [8, 12, 16])
def test_inside_marker_gap_is_8pt(size):
    marker, item = marker_and_text(f'<ul style="list-style: disc inside; padding: 0; font: {size}pt \'DejaVu Sans\'"><li>item</li></ul>')
    assert item.x == approx(marker.x + marker.width + 8, abs=0.01)


@pytest.mark.parametrize("position", ["static", "relative"])  # relative: the item lays its marker out itself
def test_outside_marker_is_8pt_before_the_content_box(position):
    marker, item = marker_and_text(f'<ul style="margin-left: 100pt; padding: 0; {FONT}"><li style="padding-left: 20pt; border-left: 2pt solid; position: {position}">item</li></ul>')
    assert marker.x + marker.width == approx(item.x - 8, abs=0.01)


def test_author_marker_strings_keep_their_spaces():
    outside, item = marker_and_text(f'<ul style="list-style-type: \'→ \'; margin-left: 100pt; padding: 0; {FONT}"><li>item</li></ul>')
    assert outside.text == "→ " and outside.x + outside.width == approx(item.x - 8, abs=0.01)
    inside, item = marker_and_text(f'<style>li::marker {{ content: "x " }}</style><ul style="list-style-position: inside; padding: 0; {FONT}"><li>item</li></ul>')
    assert inside.text == "x " and item.x == approx(inside.x + inside.width + 8, abs=0.01)


def test_right_to_left_markers_mirror_the_gap():
    outside, item = marker_and_text(f'<ul dir="rtl" style="margin-right: 100pt; padding: 0; {FONT}"><li>item</li></ul>')
    assert outside.text == "•" and outside.x == approx(item.x + item.width + 8, abs=0.01)
    inside, item = marker_and_text(f'<ul dir="rtl" style="list-style-position: inside; padding: 0; {FONT}"><li>item</li></ul>')
    assert inside.x == approx(item.x + item.width + 8, abs=0.01)
