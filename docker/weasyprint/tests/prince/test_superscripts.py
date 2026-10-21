import pytest
from pytest import approx
from support import computed_font_size, growth_above, sub_drop, sup_font_size, sup_raise


@pytest.mark.parametrize("family, raise_, growth", [("Liberation Sans", 9.053, 5.59), ("DejaVu Sans", 9.282, 5.82)])
def test_prince_superscript(family, raise_, growth):          # P = 20pt, sup 10pt, line-height 24pt
    assert sup_raise(family) == approx(raise_, abs=0.02) and growth_above(family, sup_size=10) == approx(growth, abs=0.05)


def test_sub_drop_uses_parent_descender():
    assert sub_drop("Liberation Sans") == approx(0.5 * 434 / 2048 * 20, abs=0.02)        # 2.119


@pytest.mark.parametrize("parent, keyword, expected", [(10, "smaller", 8.333), (20, "smaller", 16.667), (10, "larger", 12), (20, "larger", 24)])
def test_font_size_keywords(parent, keyword, expected):
    assert computed_font_size(parent, keyword) == approx(expected, abs=0.01)


def test_default_sup_size_is_83_percent():
    assert sup_font_size(parent=10) == approx(8.3, abs=0.01) and sup_font_size(parent=30) == approx(24.9, abs=0.01)
