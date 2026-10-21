import pytest
from support import FACE, pdf, pdf_fonts


@pytest.mark.parametrize("family, expected", [
    ("serif", "LiberationSerif"), ("sans-serif", "LiberationSans"), ("monospace", "LiberationMono"),
    ("NoSuchFont", "LiberationSerif"), ("Arial", "LiberationSans"), ("'Times New Roman'", "LiberationSerif")])
def test_generic_and_unknown_families(family, expected):
    assert expected in pdf_fonts(pdf(f'<p style="font-family: {family}">Hamburgefonstiv</p>'))


def test_missing_glyph_without_generic_falls_back_to_serif():
    assert "LiberationSerif" in pdf_fonts(pdf(FACE + '<p style="font-family: \'Platen Test\'">ab ž</p>'))


def test_missing_glyph_with_sans_serif_falls_back_to_sans():
    assert "LiberationSans" in pdf_fonts(pdf(FACE + '<p style="font-family: \'Platen Test\', sans-serif">ab ž</p>'))


def test_web_stack_with_unknown_system_names_falls_back_to_sans():
    # The font stack of the DocRaptor certificates, with FACE in place of the web font.
    stack = "'Platen Test', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fonts = pdf_fonts(pdf(FACE + f'<p style="font-family: {stack}">ab ž</p>'))
    assert "LiberationSans" in fonts
    assert not {f for f in fonts if "Roboto" in f or "Noto" in f or "DejaVu" in f}
