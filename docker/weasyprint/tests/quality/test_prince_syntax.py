from pytest import approx

from support import margin_boxes, render, runs, texts

PAGE = "@page { size: A4; margin: 20mm 30mm; }"


def test_static_flow_header_on_every_page():
    html = f'<style>{PAGE} #h {{ flow: static(header) }} @page {{ @top {{ content: flow(header) }} }}</style><div id="h">HEADER</div>' + '<p style="break-after: page">x</p>' * 3
    doc = render(html)
    assert len(doc.pages) == 3
    for page in range(3):
        assert "HEADER" in texts(margin_boxes(doc, page)["top"])   # support helper: text inside a box
        assert all("HEADER" not in r.text for r in runs(doc, page) if r.baseline > 56.7)


def test_top_region_spans_between_side_margins():
    box = margin_boxes(render(f'<style>{PAGE} @page {{ @top {{ content: "TOP"; background: #f00 }} }}</style>x'))["top"]
    assert box.x == approx(85.04, abs=0.1) and box.width == approx(425.20, abs=0.1)
    assert box.y == approx(0, abs=0.1) and box.height == approx(56.69, abs=0.1)


def test_region_padding_is_inside_the_span():
    css = "@page { size: A4; margin: 0; margin-top: 35mm; @top { content: 'X'; padding-left: 15mm; padding-right: 18mm } }"
    box = margin_boxes(render(f"<style>{css}</style>x"))["top"]
    assert box.content_x == approx(42.52, abs=0.1) and box.content_width == approx(501.73, abs=0.1)


def test_top_dash_boxes_win_over_top():
    css = f"{PAGE} @page {{ @top {{ content: 'A' }} @top-center {{ content: 'B' }} }}"
    boxes = margin_boxes(render(f"<style>{css}</style>x"))
    assert "top" not in boxes and "B" in texts(boxes["top-center"])


def test_first_page_override_and_named_page():
    css = (f"{PAGE} @page {{ @bottom {{ content: 'ALL' }} }} @page :first {{ @bottom {{ content: 'FIRST' }} }}"
           " @page wide { @bottom { content: 'WIDE' } } .w { page: wide }")
    doc = render(f"<style>{css}</style><p>1</p><p style='break-before: page'>2</p><div class='w'>3</div>")
    assert [texts(margin_boxes(doc, p)["bottom"]) for p in range(3)] == ["FIRST", "ALL", "WIDE"]
