"""F3a: pages that ask for ``-prince-pdf-page-colorspace: cmyk`` get a CMYK group.

Prince puts such a page in ``/Group << /S /Transparency /CS /DeviceCMYK >>``, which makes
viewers composite its colours differently. The content is not converted to CMYK.

- The property is read as the page property ``platen-pdf-page-colorspace`` (``auto | rgb |
  cmyk``, initial ``auto``), stored in the page style as ``platen_pdf_page_colorspace``.
- ``Document.write_pdf`` remembers the document being written, so that ``pydyf.PDF.add_page``
  can read the style of the page it receives (pages are added in order).
- set_default_group() sets the group of the pages that do not ask for ``cmyk``.
"""

import pydyf
import weasyprint.css.properties
import weasyprint.css.tokens
import weasyprint.css.validation.properties
import weasyprint.document

from . import prince_syntax

PROPERTY = "platen-pdf-page-colorspace"

_default_group: str | None = None
_document = None


def set_default_group(cs: str | None) -> None:
    """Set the colour space (e.g. ``'/DeviceRGB'``) of the transparency group of pages
    that do not ask for ``cmyk``; None for no group."""
    global _default_group
    _default_group = cs


def _group(cs: str) -> pydyf.Dictionary:
    return pydyf.Dictionary({"S": "/Transparency", "CS": cs})


def apply() -> None:
    weasyprint.css.properties.INITIAL_VALUES["platen_pdf_page_colorspace"] = "auto"
    weasyprint.css.properties.KNOWN_PROPERTIES.add(PROPERTY)

    @weasyprint.css.validation.properties.property(PROPERTY)
    def platen_pdf_page_colorspace(tokens):
        keyword = weasyprint.css.tokens.get_single_keyword(tokens)
        if keyword in ("auto", "rgb", "cmyk"):
            return keyword

    prince_syntax.register_rename("-prince-pdf-page-colorspace", PROPERTY)

    original_write_pdf = weasyprint.document.Document.write_pdf

    def write_pdf(self, *args, **kwargs):
        global _document
        _document = self
        try:
            return original_write_pdf(self, *args, **kwargs)
        finally:
            _document = None

    weasyprint.document.Document.write_pdf = write_pdf

    original_add_page = pydyf.PDF.add_page

    def add_page(self, page):
        if _document is not None:
            index = self.pages["Count"]
            style = _document.pages[index]._page_box.style
            if style["platen_pdf_page_colorspace"] == "cmyk":
                page["Group"] = _group("/DeviceCMYK")
            elif _default_group is not None:
                page["Group"] = _group(_default_group)
        original_add_page(self, page)

    pydyf.PDF.add_page = add_page
