"""The weasyprint-platen command: WeasyPrint's command line with platen's patches.

It takes WeasyPrint's arguments plus ``--platen-prince``, applies the quality layer, adds
the Prince layer when ``--platen-prince`` is given, and runs WeasyPrint's own main().
"""

import sys

from . import prince, quality

PRINCE_FLAG = "--platen-prince"


def main(argv: list[str] | None = None) -> None:
    """Run WeasyPrint on ``argv`` (default: the command line) with the patch layers it asks for."""
    argv = list(sys.argv[1:] if argv is None else argv)
    with_prince = PRINCE_FLAG in argv
    argv = [argument for argument in argv if argument != PRINCE_FLAG]

    quality.apply()
    if with_prince:
        prince.apply()

    # Imported after the patches, so that the names it binds at import time see them too.
    from weasyprint.__main__ import main as weasyprint_main

    weasyprint_main(argv)
