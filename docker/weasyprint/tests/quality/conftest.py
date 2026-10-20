"""Quality-layer tests: WeasyPrint with the quality layer, as platen renders every document.

They run in their own pytest process: tests/prince patches WeasyPrint further.
"""

import os
from pathlib import Path

# fontconfig reads these when WeasyPrint loads its configuration: set them before the
# import. FONTCONFIG_PATH is where fontconfig looks for the relative <include> of fonts.conf.
TESTS = Path(__file__).resolve().parent.parent
os.environ["FONTCONFIG_FILE"] = str(TESTS / "fonts.conf")
os.environ["FONTCONFIG_PATH"] = str(TESTS)

import support  # noqa: E402
from platen_weasyprint import quality  # noqa: E402

support.use_layer("quality")
quality.apply()
