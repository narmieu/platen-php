"""The quality layer against Chromium, where the behaviour is browser-defined: line-height:
normal, fallback glyphs, SVG fills with var() and SVG fonts."""

import io
import os
import urllib.request
import uuid

import pytest
from pypdf import PdfReader
from pytest import approx
from support import content, has_rgb_fill, pdf, pdf_fonts

PAPER = {"paperWidth": "8.27", "paperHeight": "11.69"}
MARGINS = {f"margin{side}": "0" for side in ("Top", "Bottom", "Left", "Right")}


def chromium_pdf(html: str) -> bytes:
    """The PDF that Gotenberg's Chromium makes of ``html`` (A4, no margins)."""
    boundary = uuid.uuid4().hex
    parts = [
        (f'Content-Disposition: form-data; name="{name}"\r\n\r\n{value}').encode()
        for name, value in {**PAPER, **MARGINS}.items()
    ]
    parts.append(
        b'Content-Disposition: form-data; name="files"; filename="index.html"\r\n'
        b"Content-Type: text/html\r\n\r\n" + html.encode()
    )
    body = b"".join(b"--" + boundary.encode() + b"\r\n" + part + b"\r\n" for part in parts)
    body += b"--" + boundary.encode() + b"--\r\n"
    request = urllib.request.Request(
        os.environ["GOTENBERG_URL"].rstrip("/") + "/forms/chromium/convert/html",
        data=body,
        headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
    )
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def baselines_pdf(data: bytes) -> list[float]:
    """The baseline of each line of text on the first page, in pt from the top, top to bottom.

    Read from the text positions in the content stream; lines are the distinct baselines.
    """
    page = PdfReader(io.BytesIO(data)).pages[0]
    height = float(page.mediabox.height)
    found = []

    def visit(text, cm, tm, font_dict, font_size):
        if text.strip():
            found.append(height - (tm[4] * cm[2] + tm[5] * cm[3] + cm[5]))

    page.extract_text(visitor_text=visit)
    lines = []
    for y in sorted(found):
        if not lines or y - lines[-1] > 0.05:
            lines.append(y)
    return lines


def pitch(baselines: list[float]) -> float:
    """The distance between consecutive baselines (their mean)."""
    assert len(baselines) >= 2, baselines
    return (baselines[-1] - baselines[0]) / (len(baselines) - 1)


@pytest.mark.parametrize("family", ["Liberation Sans", "DejaVu Sans"])
def test_line_height_normal_matches_chromium(family):
    html = f"<p style=\"margin:0; font: 20pt/normal '{family}'\">a<br>b<br>c</p>"
    assert pitch(baselines_pdf(pdf(html))) == approx(pitch(baselines_pdf(chromium_pdf(html))), abs=0.3)


def test_fallback_glyph_pitch_matches_chromium():
    html = "<p style=\"margin:0; font: 10pt/1.6 'Liberation Sans'\">a<br>b ☃<br>c</p>"
    assert pitch(baselines_pdf(pdf(html))) == approx(pitch(baselines_pdf(chromium_pdf(html))), abs=0.3)


def test_svg_var_fill_and_font_match_chromium():
    html = (
        "<style>:root{--c:#b7cef9}</style><div style=\"font-family:'Liberation Sans'\">"
        '<svg width="40" height="20"><rect width="20" height="20" style="fill:var(--c)"/>'
        '<text x="22" y="15">1</text></svg></div>'
    )
    assert has_rgb_fill(content(pdf(html)), (0.7176, 0.8078, 0.9765))
    assert "LiberationSans" in pdf_fonts(pdf(html)) and "LiberationSans" in pdf_fonts(chromium_pdf(html))
