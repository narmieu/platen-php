import pytest
from pytest import approx
from support import baselines, render, typo_dejavu, zeroed_dejavu


@pytest.mark.parametrize("family, pitch", [("Liberation Sans", 22.998), ("DejaVu Sans", 23.281)])
def test_normal_line_height_includes_line_gap(family, pitch):
    b = baselines(render(f'<p style="font: 20pt/normal \'{family}\'">a<br>b<br>c</p>'))
    assert b[1] - b[0] == approx(pitch, abs=0.02) and b[2] - b[1] == approx(pitch, abs=0.02)


def test_use_typo_metrics_bit_selects_os2_typo(tmp_path):
    url = typo_dejavu(tmp_path)   # fontTools copy of DejaVuSans.ttf with fsSelection |= 1 << 7
    b = baselines(render(f'<style>@font-face {{ font-family: T; src: url({url}) }}</style><p style="font: 20pt/normal T">a<br>b</p>'))
    assert b[1] - b[0] == approx(24.004, abs=0.02)     # (1556 + 492 + 410) / 2048 * 20


@pytest.mark.parametrize("typo, pitch", [
    (False, 24.004),   # OS/2 typo: (1556 + 492 + 410) / 2048 * 20
    (True, 23.281),    # usWinAscent + usWinDescent, no line gap: (1901 + 483) / 2048 * 20
], ids=["typo", "win"])
def test_empty_hhea_falls_back_to_os2(tmp_path, typo, pitch):
    url = zeroed_dejavu(tmp_path, typo)   # hhea ascender, descender and line gap 0
    b = baselines(render(f'<style>@font-face {{ font-family: Z; src: url({url}) }}</style><p style="font: 20pt/normal Z">a<br>b</p>'))
    assert b[1] - b[0] == approx(pitch, abs=0.02)


def test_fallback_glyph_does_not_grow_the_line():
    b = baselines(render('<p style="font: 10pt/1.6 \'Liberation Sans\'">one<br>two ☃<br>three</p>'))
    assert [round(y2 - y1, 2) for y1, y2 in zip(b, b[1:])] == [16.0, 16.0]
