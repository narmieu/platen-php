"""F3b: Prince writes pages with transparency in an RGB group and neutral colours as DeviceGray.

- ``page_colorspace.set_default_group('/DeviceRGB')``: transparent pages that do not ask for
  CMYK get ``/Group << /S /Transparency /CS /DeviceRGB >>``.
- sRGB colours with r = g = b are written ``<v> g`` / ``<v> G`` instead of ``r g b rg`` /
  ``RG``. Every sRGB fill and stroke goes through ``Stream.set_color_rgb`` (called by
  ``Stream.set_color``, which handles the alpha and the colour cache), so wrapping it leaves
  the alpha handling unchanged.
"""

import pydyf
import weasyprint.pdf.stream

from ..quality import page_colorspace


def apply() -> None:
    page_colorspace.set_default_group("/DeviceRGB")

    Stream = weasyprint.pdf.stream.Stream
    original = Stream.set_color_rgb

    def set_color_rgb(self, r, g, b, stroke=False):
        if r == g == b:
            self.stream.append(b" ".join((pydyf._to_bytes(r), b"G" if stroke else b"g")))
        else:
            original(self, r, g, b, stroke)

    Stream.set_color_rgb = set_color_rgb
