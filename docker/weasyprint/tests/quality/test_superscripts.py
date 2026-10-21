import pytest
from pytest import approx
from support import growth_above, line_extents, render, sup_style


def test_sup_inherits_line_height():
    assert sup_style('<p style="line-height: 24pt">x<sup>2</sup></p>')["line_height"].value == 24   # helper returns computed style


def test_sub_inherits_line_height():
    assert sup_style('<p style="line-height: 24pt">x<sub>2</sub></p>')["line_height"].value == 24


@pytest.mark.parametrize("family, a_minus_d", [("Liberation Sans", 0.69336), ("DejaVu Sans", 0.69238)])
def test_superscript_grows_the_line_per_css21(family, a_minus_d):
    assert growth_above(family, sup_size=10) == approx(5.0 - a_minus_d * 5, abs=0.05)   # 1.533 / 1.538


def test_small_superscript_does_not_grow():
    assert growth_above("Liberation Sans", sup_size=5) == approx(0, abs=0.02)


def test_nested_sup_in_span_lines_do_not_overlap():
    doc = render('<p style="font: 20pt/24pt \'Liberation Sans\'; width: 60pt">aa <span style="line-height: 40pt">b<sup>2</sup></span> cc dd ee</p>')
    tops_bottoms = line_extents(doc)              # helper: (top, bottom) per line box, pt
    assert all(b2_top >= b1_bottom - 0.01 for (_, b1_bottom), (b2_top, _) in zip(tops_bottoms, tops_bottoms[1:]))
