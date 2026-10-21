import pytest
from platen_weasyprint.quality import hyphenation
from support import FACE, all_text, lines, render, runs


@pytest.mark.parametrize("lang, word, expected", [
    ("sr", "samoprocenu", "sr_Latn"), ("sr", "самопроцену", "sr"), ("sr-Latn-RS", "x", "sr_Latn"),
    ("SR", "abc", "sr_Latn"), ("sr-Cyrl", "abc", "sr"), ("en", "x", "en_US"), ("en-GB", "x", "en_GB"),
    ("", "x", None), (None, "x", None)])
def test_dictionary_for(lang, word, expected):
    assert hyphenation.dictionary_for(lang, word) == expected


def test_serbian_latin_text_hyphenates():
    lines_ = lines(render('<p lang="sr" style="hyphens: auto; width: 40pt; font: 10pt \'DejaVu Sans\'">samoprocenu samoprocenu</p>'))
    assert any(l[-1].text.rstrip("‐-") in {"sa", "samo", "samopro", "samoproce"} for l in lines_)


def test_no_lang_no_hyphenation():
    text = "".join(r.text for r in runs(render('<p style="hyphens: auto; width: 40pt">samoprocenu samoprocenu</p>')))
    assert "‐" not in text and "-" not in text


def test_hyphen_glyph_follows_font():
    narrow = '<p lang="en" style="hyphens: auto; width: 40pt; font: 10pt {f}">representative representative</p>'
    assert "‐" in all_text(render(narrow.format(f="'DejaVu Sans'")))
    assert "-" in all_text(render(FACE + narrow.format(f="'Platen Test'"))) and "‐" not in all_text(render(FACE + narrow.format(f="'Platen Test'")))


SERBIAN_LATIN = '<p{lang} style="hyphens: auto; width: 40pt; font: 10pt \'DejaVu Sans\'{css}">samoprocenu samoprocenu</p>'


@pytest.mark.parametrize("lang, hyphenated", [
    ("sr-Latn-RS", True), ("SR", True), ("sr-RS", True), ("sr-Cyrl", False), ("", False)])
def test_lang_subtags_pick_the_dictionary(lang, hyphenated):
    text = all_text(render(SERBIAN_LATIN.format(lang=f' lang="{lang}"', css="")))
    assert ("‐" in text) is hyphenated


def test_hyphenate_limit_chars_reach_the_dictionary():
    # sr_Latn breaks sa-mo-pro-ce-nu; 5 characters before and after leave no break.
    text = all_text(render(SERBIAN_LATIN.format(lang=' lang="sr"', css="; hyphenate-limit-chars: auto 5 5")))
    assert "‐" not in text and "-" not in text


@pytest.mark.parametrize("declaration, hyphen", [
    ("hyphenate-character: auto", "-"), ("-weasy-hyphenate-character: auto", "-"),
    ("hyphenate-character: '\\2010'", "‐"), ("-weasy-hyphenate-character: '='", "=")])
def test_explicit_hyphenate_character(declaration, hyphen):
    # Only auto (the initial value) follows the font: Platen Test has no U+2010.
    text = all_text(render(FACE + f'<p lang="en" style="hyphens: auto; width: 40pt; font: 10pt \'Platen Test\'; {declaration}">representative representative</p>'))
    others = {"‐", "-", "="} - {hyphen}
    assert hyphen in text and not others & set(text)


def test_resolver_and_hyphen_policy_take_over():
    html = '<p lang="xx" style="hyphens: auto; width: 40pt; font: 10pt \'DejaVu Sans\'">representative representative</p>'
    try:
        hyphenation.set_resolver(lambda lang, word: "en_US")
        hyphenation.set_hyphen("hyphen-minus")
        text = all_text(render(html))
    finally:
        hyphenation.set_resolver(hyphenation.dictionary_for)
        hyphenation.set_hyphen("font")
    assert "-" in text and "‐" not in text
