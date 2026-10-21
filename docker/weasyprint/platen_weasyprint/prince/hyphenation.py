"""F9b: Prince hyphenates with the patterns it ships for da, de, en-US, es, fi, fr, is, it,
lt, pl, pt, ru, sl, sv and th; every other language, ``sr`` included, gets en-US. The
hyphen is always U+002D, also where the font has U+2010; a ``hyphenate-character`` string
of the author stays as written.
"""

import pyphen

from ..quality.hyphenation import set_hyphen, set_resolver

PRINCE_LANGUAGES = {"da", "de", "en_US", "es", "fi", "fr", "is", "it", "lt", "pl", "pt", "ru", "sl", "sv", "th"}


def prince_dictionary_for(lang: str | None, word: str) -> str | None:
    """The pyphen dictionary Prince's patterns correspond to for ``lang``, None when empty."""
    if not lang:
        return None
    base = lang.replace("-", "_").lower().split("_")[0]
    if base == "en":
        return "en_US"
    if base in PRINCE_LANGUAGES:
        return pyphen.language_fallback(base)
    return "en_US"


def apply() -> None:
    set_resolver(prince_dictionary_for)
    set_hyphen("hyphen-minus")
