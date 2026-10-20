"""platen's patches for WeasyPrint, applied in-process by the weasyprint-platen command.

Two layers of fixes, each a subpackage with one module per fix:

- ``quality``: closer to the CSS specifications and to browsers, for every render;
- ``prince``: like Prince 15.1, for DocRaptor pipeline numbers, on top of ``quality``
  (``weasyprint-platen --platen-prince``).

The patches reach into the internals of WeasyPrint and of the libraries it uses, so they
only run against the exact versions in ``SUPPORTED``.
"""

import importlib
from types import ModuleType

SUPPORTED: dict[str, str] = {"weasyprint": "70.0", "pydyf": "0.13.0", "pyphen": "0.18.1"}

_DISPLAY_NAMES = {"weasyprint": "WeasyPrint"}


def check_versions() -> None:
    """Raise RuntimeError unless every package in SUPPORTED has the supported version."""
    problems = []
    for package, supported in SUPPORTED.items():
        found = getattr(importlib.import_module(package), "__version__", "an unknown version")
        if found != supported:
            name = _DISPLAY_NAMES.get(package, package)
            problems.append(f"platen-weasyprint supports {name} {supported}, found {found}")
    if problems:
        raise RuntimeError("; ".join(problems))


def apply_modules(modules: list[ModuleType], applied: set[ModuleType]) -> None:
    """Check the versions, then apply, in order, each fix module not in ``applied`` yet.

    Each layer keeps its own ``applied`` set, which makes its apply() idempotent.
    """
    check_versions()
    for module in modules:
        if module not in applied:
            module.apply()
            applied.add(module)
