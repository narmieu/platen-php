from support import content, has_rgb_fill, pdf, pdf_fonts


def test_rect_fill_from_root_custom_property():
    data = pdf('<style>:root{--c:#b7cef9}</style><svg width="20" height="20"><rect width="20" height="20" style="fill: var(--c)"/></svg>')
    assert has_rgb_fill(content(data), (0.7176, 0.8078, 0.9765))


def test_var_fallback_when_property_missing():
    data = pdf('<svg width="20" height="20"><rect width="20" height="20" style="fill: var(--missing, #ff0000)"/></svg>')
    assert has_rgb_fill(content(data), (1, 0, 0))


def test_undefined_var_without_fallback_does_not_turn_black():
    data = pdf('<svg width="20" height="20"><g fill="#00ff00"><rect width="20" height="20" style="fill: var(--missing)"/></g></svg>')
    assert has_rgb_fill(content(data), (0, 1, 0))


def test_svg_text_inherits_html_font():
    data = pdf('<div style="font-family: \'Liberation Sans\'"><svg width="40" height="20"><text y="15">12</text></svg></div>')
    assert "LiberationSans" in pdf_fonts(data)


def test_explicit_svg_font_family_wins():
    data = pdf('<div style="font-family: \'Liberation Sans\'"><svg width="40" height="20"><text y="15" font-family="DejaVu Sans">12</text></svg></div>')
    assert "DejaVuSans" in pdf_fonts(data) and "LiberationSans" not in pdf_fonts(data)


def test_svg_style_element_still_applies():
    data = pdf('<svg width="20" height="20"><style>rect{fill:#00ff00}</style><rect width="20" height="20"/></svg>')
    assert has_rgb_fill(content(data), (0, 1, 0))


def test_svg_style_rule_font_family_wins_over_inherited():
    data = pdf('<div style="font-family: \'Liberation Sans\'"><svg width="40" height="20"><style>svg{font-family:"DejaVu Sans"}</style><text y="15">12</text></svg></div>')
    assert "DejaVuSans" in pdf_fonts(data) and "LiberationSans" not in pdf_fonts(data)


def test_var_in_svg_style_element_resolves_per_declaration():
    data = pdf('<style>:root{--c:#00ff00}</style><svg width="40" height="20"><style>rect{fill:var(--c)} circle{fill:#ff0000; stroke:var(--missing)}</style><rect width="20" height="20"/><circle cx="30" cy="10" r="5"/></svg>')
    stream = content(data)
    assert has_rgb_fill(stream, (0, 1, 0)) and has_rgb_fill(stream, (1, 0, 0))
