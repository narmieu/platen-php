import re

from support import content, page_group_cs, pdf

HTML = ('<p style="color:#000">black</p><div style="background:#808080;height:5pt"></div>'
        '<div style="background:#ff0000;height:5pt"></div>'
        '<div style="background:rgba(0,0,0,.5);height:5pt"></div>')
ALPHA = '<p style="color: rgba(0,0,0,.5)">x</p>'
CMYK = "@page c { -prince-pdf-page-colorspace: cmyk } .c { page: c; break-before: page }"


def test_neutral_colours_are_device_gray():
    ops = content(pdf(HTML))
    assert re.search(r"(^|\s)0 g(\s|$)", ops) and re.search(r"0\.50196\d* g", ops)
    assert "0 0 0 rg" not in ops and re.search(r"1 0 0 rg", ops)


def test_neutral_strokes_are_device_gray():
    ops = content(pdf('<div style="border:2pt dashed #808080;height:5pt"></div>'))
    assert re.search(r"0\.50196\d* G", ops)
    assert not re.search(r"0\.50196\d* 0\.50196\d* 0\.50196\d* RG", ops)


def test_every_transparent_page_gets_an_rgb_group_unless_cmyk():
    data = pdf(f"<style>{CMYK}</style>{ALPHA}<div class='c'>{ALPHA}</div>")
    assert [page_group_cs(data, 0), page_group_cs(data, 1)] == ["/DeviceRGB", "/DeviceCMYK"]


def test_opaque_page_has_no_group():
    assert page_group_cs(pdf("<p>x</p>")) is None
