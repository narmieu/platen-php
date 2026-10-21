"""The weasyprint-platen command: WeasyPrint's command line with platen's patches.

It takes WeasyPrint's arguments, applies the quality layer, adds the Prince layer when the
environment sets ``PLATEN_WEASYPRINT_PRINCE=1`` or the arguments include
``--platen-prince``, and runs WeasyPrint's own main(). platen sets the variable: stock
WeasyPrint ignores it, while it would reject the flag. The flag is for manual runs.
"""

import os
import sys

from . import check_versions

PRINCE_FLAG = "--platen-prince"
PRINCE_VARIABLE = "PLATEN_WEASYPRINT_PRINCE"


def main(argv: list[str] | None = None) -> None:
    """Run WeasyPrint on ``argv`` (default: the command line) with the patch layers it asks for."""
    argv = list(sys.argv[1:] if argv is None else argv)
    with_prince = PRINCE_FLAG in argv or os.environ.get(PRINCE_VARIABLE) == "1"
    argv = [argument for argument in argv if argument != PRINCE_FLAG]

    # Before the layers are imported: they import private names of WeasyPrint, which other
    # versions may have moved, and only the version message would tell why that fails.
    check_versions()
    from . import prince, quality

    quality.apply()
    if with_prince:
        prince.apply()

    # Imported after the patches, so that the names it binds at import time see them too.
    from weasyprint.__main__ import main as weasyprint_main

    weasyprint_main(argv)
