"""F11: inline SVG inherits the page font and resolves CSS custom properties.

WeasyPrint draws an inline <svg> with its own parser: the text gets the default font
instead of the one of the HTML around it, and var() in style and presentation attributes
is not resolved (the fill falls back to black). Before WeasyPrint reads the element, this
module sets the font properties of the root <svg> from the style of its box and replaces
each var() of the subtree with the value of the custom property, else its fallback, else
drops the declaration or attribute (the value is then inherited, as in browsers).
"""

import weasyprint.html
import tinycss2

SVG_TAG = "{http://www.w3.org/2000/svg}svg"

_FONT_PROPERTIES = ("font-family", "font-style", "font-weight", "font-size")


class _Unresolved(Exception):
    """A var() without a value and without a fallback."""


_GENERIC_FAMILIES = {
    "serif", "sans-serif", "monospace", "cursive", "fantasy", "system-ui", "emoji", "math",
}


def _quote(family: str) -> str:
    if family in _GENERIC_FAMILIES:
        return family
    return '"' + family.replace("\\", "\\\\").replace('"', '\\"') + '"'


def _font_values(style) -> dict[str, str]:
    families = ", ".join(_quote(family) for family in style["font_family"])
    return {
        "font-family": families,
        "font-style": str(style["font_style"]),
        "font-weight": str(style["font_weight"]),
        "font-size": f"{style['font_size']:g}px",
    }


def _resolve(tokens, style, depth=0):
    """Return the tokens with every var() replaced; raise _Unresolved if one has no value."""
    if depth > 16:
        raise _Unresolved
    resolved = []
    for token in tokens:
        if token.type == "function" and token.lower_name == "var":
            name = None
            fallback = None
            arguments = token.arguments
            for index, argument in enumerate(arguments):
                if argument.type == "whitespace" or argument.type == "comment":
                    continue
                if argument.type == "ident":
                    name = argument.value
                    for rest in range(index + 1, len(arguments)):
                        if arguments[rest].type == "literal" and arguments[rest].value == ",":
                            fallback = arguments[rest + 1 :]
                            break
                break
            if name is None or not name.startswith("--"):
                raise _Unresolved
            value = style["__" + name[2:].replace("-", "_")]  # computed on access
            if value:
                resolved.extend(_resolve(list(value), style, depth + 1))
            elif fallback is not None:
                resolved.extend(_resolve(fallback, style, depth + 1))
            else:
                raise _Unresolved
        elif token.type == "function":
            token.arguments = _resolve(token.arguments, style, depth)
            resolved.append(token)
        elif token.type in ("() block", "[] block", "{} block"):
            token.content = _resolve(token.content, style, depth)
            resolved.append(token)
        else:
            resolved.append(token)
    return resolved


def _resolve_text(text, style):
    """The text with its var() resolved, or None if one cannot be."""
    if "var(" not in text:
        return text
    try:
        return tinycss2.serialize(
            _resolve(tinycss2.parse_component_value_list(text), style)
        ).strip()
    except _Unresolved:
        return None


def _resolve_style_attribute(value, style):
    if "var(" not in value:
        return value
    kept = []
    for declaration in tinycss2.parse_blocks_contents(value, skip_comments=True, skip_whitespace=True):
        if declaration.type != "declaration":
            continue
        text = tinycss2.serialize(declaration.value)
        resolved = _resolve_text(text, style)
        if resolved is None:
            continue
        important = " !important" if declaration.important else ""
        kept.append(f"{declaration.name}: {resolved}{important}")
    return "; ".join(kept)


def _resolve_tree(root, style):
    for element in root.iter():
        if not isinstance(element.tag, str):
            continue
        for name, value in list(element.attrib.items()):
            if "var(" not in value:
                continue
            if name == "style":
                element.set(name, _resolve_style_attribute(value, style))
                continue
            resolved = _resolve_text(value, style)
            if resolved is None:
                del element.attrib[name]
            else:
                element.set(name, resolved)


def _inherit_fonts(root, style):
    declared = set()
    for name in _FONT_PROPERTIES:
        if root.get(name) is not None:
            declared.add(name)
    for declaration in tinycss2.parse_blocks_contents(
        root.get("style", ""), skip_comments=True, skip_whitespace=True
    ):
        if declaration.type == "declaration":
            declared.add(declaration.lower_name)
    missing = {n: v for n, v in _font_values(style).items() if n not in declared}
    if missing:
        existing = root.get("style", "").strip().rstrip(";")
        added = "; ".join(f"{n}: {v}" for n, v in missing.items())
        root.set("style", f"{existing}; {added}" if existing else added)


def apply() -> None:
    original = weasyprint.html.HTML_HANDLERS[SVG_TAG]

    def handle_svg(element, box, get_image_from_uri, base_url):
        _resolve_tree(element, box.style)
        _inherit_fonts(element, box.style)
        return original(element, box, get_image_from_uri, base_url)

    weasyprint.html.HTML_HANDLERS[SVG_TAG] = handle_svg
