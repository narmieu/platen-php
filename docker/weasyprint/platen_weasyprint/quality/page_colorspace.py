"""F3a: pages that ask for ``-prince-pdf-page-colorspace: cmyk`` get a CMYK group.

Prince puts such a page in ``/Group << /S /Transparency /CS /DeviceCMYK >>``, which makes
viewers composite its colours differently. The content is not converted to CMYK.

- The property is read as the page property ``platen-pdf-page-colorspace`` (``auto | rgb |
  cmyk``, initial ``auto``), stored in the page style as ``platen_pdf_page_colorspace``.
- Prince writes a page group only on pages whose own content uses transparency (constant
  alpha below 1, a soft mask, a non-Normal blend mode; also inside the groups, patterns
  and images the page draws). Stream methods that start any of these flag their stream;
  add_group / add_pattern / add_image link the nested streams and images to the stream that
  draws them. WeasyPrint paints a page after it calls ``PDF.add_page``, and shares resources
  between pages, so the groups are set later, in a finisher of ``Document.write_pdf``, from
  the flags of each page's own stream.
- ``Document.write_pdf`` remembers the document being written, so that ``pydyf.PDF.add_page``
  can read the style of the page it receives (pages are added in order).
- set_default_group() sets the group of the transparent pages that do not ask for ``cmyk``.
"""

import pydyf
import weasyprint.css.properties
import weasyprint.css.tokens
import weasyprint.css.validation.properties
import weasyprint.document
import weasyprint.pdf.stream

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


def _is_transparent_state(state) -> bool:
    return (
        "SMask" in state
        or state.get("ca", 1) < 1
        or state.get("CA", 1) < 1
        or state.get("BM", "/Normal") != "/Normal"
    )


def _uses_transparency(stream) -> bool:
    """Whether the stream, or a group, pattern or image it draws, uses transparency."""
    if getattr(stream, "_platen_transparent", False):
        return True
    for name in getattr(stream, "_platen_images", ()):
        x_object = stream._images[name]["x_object"]
        if x_object is not None and "SMask" in x_object.extra:
            return True
    return any(_uses_transparency(child) for child in getattr(stream, "_platen_children", ()))


def _patch_stream() -> None:
    Stream = weasyprint.pdf.stream.Stream
    original_set_alpha = Stream.set_alpha
    original_set_state = Stream.set_state

    def set_alpha(self, alpha, stroke=False, fill=None):
        if alpha < 1:
            self._platen_transparent = True
        return original_set_alpha(self, alpha, stroke, fill)

    def set_state(self, state):
        if _is_transparent_state(state):
            self._platen_transparent = True
        return original_set_state(self, state)

    Stream.set_alpha = set_alpha
    Stream.set_state = set_state

    for method in ("add_group", "add_pattern"):
        def linked(self, *args, _original=getattr(Stream, method)):
            child = _original(self, *args)
            self.__dict__.setdefault("_platen_children", []).append(child)
            return child

        setattr(Stream, method, linked)

    original_add_image = Stream.add_image

    def add_image(self, image, interpolate, ratio):
        name = original_add_image(self, image, interpolate, ratio)
        self.__dict__.setdefault("_platen_images", set()).add(name)
        return name

    Stream.add_image = add_image


def apply() -> None:
    weasyprint.css.properties.INITIAL_VALUES["platen_pdf_page_colorspace"] = "auto"
    weasyprint.css.properties.KNOWN_PROPERTIES.add(PROPERTY)

    @weasyprint.css.validation.properties.property(PROPERTY)
    def platen_pdf_page_colorspace(tokens):
        keyword = weasyprint.css.tokens.get_single_keyword(tokens)
        if keyword in ("auto", "rgb", "cmyk"):
            return keyword

    prince_syntax.register_rename("-prince-pdf-page-colorspace", PROPERTY)

    _patch_stream()

    original_write_pdf = weasyprint.document.Document.write_pdf

    def write_pdf(self, target=None, zoom=1, finisher=None, **options):
        global _document

        def add_groups(document, pdf):
            for page, stream, cs in getattr(pdf, "_platen_pages", ()):
                if cs is not None and _uses_transparency(stream):
                    page["Group"] = _group(cs)
            if finisher:
                finisher(document, pdf)

        previous, _document = _document, self
        try:
            return original_write_pdf(self, target, zoom, add_groups, **options)
        finally:
            _document = previous

    weasyprint.document.Document.write_pdf = write_pdf

    original_add_page = pydyf.PDF.add_page

    def add_page(self, page):
        if _document is not None:
            style = _document.pages[self.pages["Count"]]._page_box.style
            cs = "/DeviceCMYK" if style["platen_pdf_page_colorspace"] == "cmyk" else _default_group
            stream = self.objects[-1]
            if cs is not None and stream.reference == page["Contents"]:
                self.__dict__.setdefault("_platen_pages", []).append((page, stream, cs))
        original_add_page(self, page)

    pydyf.PDF.add_page = add_page
