"""Browser-oracle tests: WeasyPrint with the quality layer against platen's Chromium engine.

Chromium is Gotenberg: the tests skip unless GOTENBERG_URL says where it runs. Set
PLATEN_ORACLE_STOCK to leave the quality layer out and see the tests fail on stock WeasyPrint.
They run in their own pytest process: the quality layer patches WeasyPrint for the whole process.
"""

import os
from pathlib import Path

import pytest

# fontconfig reads these when WeasyPrint loads its configuration: set them before the import.
TESTS = Path(__file__).resolve().parent.parent
os.environ["FONTCONFIG_FILE"] = str(TESTS / "fonts.conf")
os.environ["FONTCONFIG_PATH"] = str(TESTS)

import support  # noqa: E402
from platen_weasyprint import quality  # noqa: E402

support.use_layer("oracle")
if not os.environ.get("PLATEN_ORACLE_STOCK"):
    quality.apply()


def pytest_collection_modifyitems(config, items):
    if os.environ.get("GOTENBERG_URL"):
        return
    skip = pytest.mark.skip(reason="GOTENBERG_URL is not set")
    for item in items:
        if Path(item.fspath).parent == Path(__file__).parent:
            item.add_marker(skip)
