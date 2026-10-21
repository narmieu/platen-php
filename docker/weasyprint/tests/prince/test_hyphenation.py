import pyphen
import pytest
from platen_weasyprint.prince.hyphenation import prince_dictionary_for
from support import all_text, lines, render


@pytest.mark.parametrize(
    "lang, expected",
    [
        ("sr", "en_US"),
        ("hr", "en_US"),
        ("en", "en_US"),
        ("en-GB", "en_US"),
        ("de", pyphen.language_fallback("de")),
        ("", None),
    ],
)
def test_prince_dictionaries(lang, expected):
    assert prince_dictionary_for(lang, "word") == expected


def test_serbian_breaks_like_prince():
    html = '<p lang="sr" style="hyphens: auto; width: 30pt; font: 10pt \'DejaVu Sans\'">evaluaciji evaluaciji</p>'
    lines_ = lines(render(html))
    assert any(line[-1].text == "eval-" for line in lines_)  # en-US allows eval-u-aci-ji; sr_Latn (eva-lu-a-ci-ji) does not


def test_prince_hyphen_is_always_hyphen_minus():
    narrow = '<p lang="en" style="hyphens: auto; width: 40pt; font: 10pt \'DejaVu Sans\'">representative representative</p>'
    assert "-" in all_text(render(narrow)) and "‐" not in all_text(render(narrow))
