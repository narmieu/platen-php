import pytest
from platen_weasyprint.quality import page_colorspace
from support import page_group_cs, pdf

CMYK = "-prince-pdf-page-colorspace: cmyk"
ALPHA = '<p style="color: rgba(0,0,0,.5)">x</p>'


def test_cmyk_page_gets_cmyk_group():
    data = pdf(f"<style>@page {{ {CMYK} }}</style>{ALPHA}")
    assert page_group_cs(data) == "/DeviceCMYK"


def test_named_page_only():
    css = f"@page c {{ {CMYK} }} .c {{ page: c; break-before: page }}"
    data = pdf(f"<style>{css}</style>{ALPHA}<div class='c'>{ALPHA}</div>")
    assert [page_group_cs(data, 0), page_group_cs(data, 1)] == [None, "/DeviceCMYK"]


def test_no_property_no_group():
    assert page_group_cs(pdf(ALPHA)) is None


def test_cmyk_page_without_transparency_has_no_group():
    assert page_group_cs(pdf(f"<style>@page {{ {CMYK} }}</style><p>x</p>")) is None


def test_group_only_on_transparent_pages():
    css = f"@page {{ {CMYK} }} .b {{ break-before: page }}"
    data = pdf(f"<style>{css}</style>{ALPHA}<p class='b'>x</p><div class='b'>{ALPHA}</div>")
    assert [page_group_cs(data, n) for n in range(3)] == ["/DeviceCMYK", None, "/DeviceCMYK"]


def test_transparency_inside_opacity_group():
    data = pdf(f"<style>@page {{ {CMYK} }}</style><p style='opacity: .5'>x</p>")
    assert page_group_cs(data) == "/DeviceCMYK"


@pytest.fixture
def rgb_default():
    page_colorspace.set_default_group("/DeviceRGB")
    yield
    page_colorspace.set_default_group(None)


def test_default_group_on_transparent_pages(rgb_default):
    data = pdf(f"{ALPHA}<p style='break-before: page'>x</p>")
    assert [page_group_cs(data, 0), page_group_cs(data, 1)] == ["/DeviceRGB", None]
