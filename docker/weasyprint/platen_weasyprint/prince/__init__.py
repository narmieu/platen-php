"""Prince layer: fixes that make WeasyPrint render like Prince 15.1, for DocRaptor pipeline
numbers. Applied on top of the quality layer (``weasyprint-platen --platen-prince``).

Each fix is a module with an ``apply() -> None`` function; MODULES lists them in the order
they are applied.
"""

from types import ModuleType

from .. import apply_modules
from . import colors, font_matching, hyphenation, superscripts

MODULES: list[ModuleType] = [colors, font_matching, hyphenation, superscripts]

_applied: set[ModuleType] = set()


def apply() -> None:
    """Check the versions, then apply each module of MODULES once (idempotent)."""
    apply_modules(MODULES, _applied)
