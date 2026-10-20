"""Quality layer: fixes that bring every WeasyPrint render closer to the CSS specifications
and to browsers.

Each fix is a module with an ``apply() -> None`` function; MODULES lists them in the order
they are applied.
"""

from types import ModuleType

from .. import apply_modules

MODULES: list[ModuleType] = []

_applied: set[ModuleType] = set()


def apply() -> None:
    """Check the versions, then apply each module of MODULES once (idempotent)."""
    apply_modules(MODULES, _applied)
