"""F9a: the hyphenation dictionary follows the language and the script; the hyphen glyph
follows the font.

- WeasyPrint picks one pyphen dictionary per ``lang`` (``pyphen.language_fallback``):
  ``sr`` gets pyphen's Cyrillic ``sr``, which never breaks Latin text, and ``en`` gets
  pyphen's ``en`` (British patterns). The patch swaps the ``pyphen`` module that
  ``text.line_break`` sees for a shim: ``language_fallback(lang)`` keeps the element's
  ``lang`` and ``Pyphen(lang=...)`` hyphenates each word with the dictionary the resolver
  picks for that language and word (dictionary_for() by default). WeasyPrint keeps
  caching one shim per language and ``hyphenate-limit-chars`` in its layout context; the
  shim reads the resolver on every word, so set_resolver() takes over at once.
- ``hyphenate-character: auto``, the initial value, was always U+2010, which a fallback
  font draws when the text's font lacks it. It now gives U+2010 only when the first
  available font of the text has that glyph, else U+002D; set_hyphen("hyphen-minus")
  makes it always U+002D. A ``hyphenate-character`` string (also ``-weasy-`` prefixed)
  stays as written.

``auto`` stays recognisable after inheritance as an _AutoHyphen string; split_first_line
stores the glyph it stands for in the style it lays out, so that drawing sees it too.
"""

import unicodedata
from collections.abc import Callable
from types import SimpleNamespace
from typing import Literal

import pyphen
import weasyprint.layout.inline
import weasyprint.layout.preferred
import weasyprint.text.line_break
from weasyprint.css.properties import INITIAL_VALUES
from weasyprint.css.tokens import get_single_keyword
from weasyprint.css.validation.properties import PROPERTIES

from ..fontmetrics import primary_has_char

HYPHEN = "‐"
HYPHEN_MINUS = "-"

_SCRIPTS = {"latn", "cyrl"}

# Modules that call split_first_line through their own name for it.
_SPLIT_MODULES = (
    weasyprint.text.line_break,
    weasyprint.layout.inline,
    weasyprint.layout.preferred,
)


def dictionary_for(lang: str | None, word: str) -> str | None:
    """The pyphen dictionary for ``word`` in an element of language ``lang``, or None.

    Subtags are case-insensitive and may be joined by "-" or "_". An explicit ``Latn`` or
    ``Cyrl`` script subtag picks that script; ``sr`` without one is Latin (``sr_Latn``)
    when the word has a Latin letter, else Cyrillic (``sr``). ``en`` without a region is
    ``en_US``. Everything else is pyphen's own fallback, None when pyphen has no
    dictionary for the language.
    """
    if not lang:
        return None
    subtags = lang.replace("-", "_").lower().split("_")
    if subtags[0] == "sr" and not _SCRIPTS & set(subtags[1:]):
        subtags.insert(1, "latn" if _has_latin_letter(word) else "cyrl")
    if [subtag for subtag in subtags if subtag not in _SCRIPTS] == ["en"]:
        return "en_US"
    return pyphen.language_fallback("_".join(subtags))


def _has_latin_letter(word: str) -> bool:
    return any(unicodedata.name(char, "").startswith("LATIN ") for char in word)


_resolver: Callable[[str | None, str], str | None] = dictionary_for
_hyphen: Literal["font", "hyphen-minus"] = "font"


def set_resolver(fn: Callable[[str | None, str], str | None]) -> None:
    """Pick dictionaries with ``fn(lang, word)`` (a pyphen dictionary name or None)
    instead of the current resolver, from the next word hyphenated on."""
    global _resolver
    _resolver = fn


def set_hyphen(policy: Literal["font", "hyphen-minus"]) -> None:
    """What ``hyphenate-character: auto`` gives: U+2010 when the text's font has it, else
    U+002D ("font"), or always U+002D ("hyphen-minus"), from the next line laid out on."""
    global _hyphen
    if policy not in ("font", "hyphen-minus"):
        raise ValueError(f"unknown hyphen policy {policy!r}")
    _hyphen = policy


class _Dictionary:
    """WeasyPrint's pyphen.Pyphen(lang=..., left=..., right=...): each word is hyphenated
    with the dictionary that the resolver picks for ``lang`` and that word."""

    def __init__(self, lang=None, left=2, right=2):
        self.lang, self.left, self.right = lang, left, right
        self._dictionaries: dict[str, pyphen.Pyphen] = {}

    def _for(self, word: str) -> pyphen.Pyphen | None:
        name = _resolver(self.lang, word)
        if name is None:
            return None
        if name not in self._dictionaries:
            self._dictionaries[name] = pyphen.Pyphen(lang=name, left=self.left, right=self.right)
        return self._dictionaries[name]

    def positions(self, word):
        dictionary = self._for(word)
        return [] if dictionary is None else dictionary.positions(word)

    def iterate(self, word):
        dictionary = self._for(word)
        return iter(()) if dictionary is None else dictionary.iterate(word)

    def inserted(self, word, hyphen="-"):
        dictionary = self._for(word)
        return word if dictionary is None else dictionary.inserted(word, hyphen)


def _language_fallback(lang):
    """The element's language as it is: the resolver reads it for each word."""
    return lang


_PYPHEN = SimpleNamespace(language_fallback=_language_fallback, Pyphen=_Dictionary)


class _AutoHyphen(str):
    """The glyph that ``hyphenate-character: auto`` stands for."""


_AUTO_HYPHEN = _AutoHyphen(HYPHEN)
_AUTO_HYPHEN_MINUS = _AutoHyphen(HYPHEN_MINUS)


def _use_auto_hyphen(style) -> None:
    """Replace ``hyphenate-character: auto`` in ``style`` by the glyph it gives there."""
    value = style["hyphenate_character"]
    if isinstance(value, _AutoHyphen):
        if _hyphen == "font" and primary_has_char(style, HYPHEN):
            glyph = _AUTO_HYPHEN
        else:
            glyph = _AUTO_HYPHEN_MINUS
        if value is not glyph:
            style["hyphenate_character"] = glyph


def apply() -> None:
    weasyprint.text.line_break.pyphen = _PYPHEN

    validate = PROPERTIES["hyphenate-character"]

    def hyphenate_character(tokens):
        value = validate(tokens)
        return _AUTO_HYPHEN if value is not None and get_single_keyword(tokens) == "auto" else value

    hyphenate_character.wants_base_url = validate.wants_base_url
    PROPERTIES["hyphenate-character"] = hyphenate_character
    INITIAL_VALUES["hyphenate_character"] = _AUTO_HYPHEN

    original_split_first_line = weasyprint.text.line_break.split_first_line

    def split_first_line(text, style, *args, **kwargs):
        _use_auto_hyphen(style)
        return original_split_first_line(text, style, *args, **kwargs)

    for module in _SPLIT_MODULES:
        module.split_first_line = split_first_line
