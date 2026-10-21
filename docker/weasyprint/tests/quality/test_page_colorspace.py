from support import page_group_cs, pdf


def test_cmyk_page_gets_cmyk_group():
    data = pdf("<style>@page { -prince-pdf-page-colorspace: cmyk }</style><p>x</p>")
    assert page_group_cs(data) == "/DeviceCMYK"


def test_named_page_only():
    css = "@page c { -prince-pdf-page-colorspace: cmyk } .c { page: c; break-before: page }"
    data = pdf(f"<style>{css}</style><p>rgb</p><div class='c'>cmyk</div>")
    assert [page_group_cs(data, 0), page_group_cs(data, 1)] == [None, "/DeviceCMYK"]


def test_no_property_no_group():
    assert page_group_cs(pdf("<p>x</p>")) is None
