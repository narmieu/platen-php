"""F2: Prince's static flows and its full-width @top / @bottom page regions.

Documents written for DocRaptor (Prince) move their header and footer into the page
margins with ``#header { flow: static(header) }`` and
``@page { @top { content: flow(header) } }``. WeasyPrint knows neither: it drops the
declarations and the margin rules, so the header and footer stay in the body.

- Declarations are translated before WeasyPrint validates them: ``flow`` and
  ``-prince-flow`` with ``static(<ident>)`` become ``position: running(<ident>)``, and
  ``flow(<ident>)`` in ``content`` becomes ``element(<ident>)``. Other modules can rename
  more properties with register_rename().
- ``@top`` and ``@bottom`` are accepted as margin rules. Their box spans the whole page
  border box horizontally (from the left margin edge, ``page.border_width()`` wide) and
  the whole top (bottom) margin vertically; the region's own margins and padding are
  inside that span. Content is centred like ``@top-center`` (``text-align: center;
  vertical-align: middle``) unless the author says otherwise.
- When a page has an ``@top-left``, ``@top-center`` or ``@top-right`` box with content,
  that box wins and ``@top`` is not generated (the same for ``@bottom``).
"""

import copy

import tinycss2.ast
import weasyprint.css
import weasyprint.css.validation
import weasyprint.html
import weasyprint.layout
import weasyprint.layout.page
from weasyprint.formatting_structure import boxes, build
from weasyprint.layout.page import (
    _standardize_page_based_counters,
    compute_fixed_dimension,
    margin_box_content_layout,
)
from weasyprint.layout.percent import resolve_percentages

REGIONS = ("top", "bottom")

_SIDE_SUFFIXES = ("left", "center", "right")

_UA_REGIONS = """
@page {
  @top    { text-align: center; vertical-align: middle }
  @bottom { text-align: center; vertical-align: middle }
}
"""

_renames: dict[str, str] = {}


def register_rename(name: str, target: str) -> None:
    """Read declarations of the property ``name`` (case-insensitive) as ``target``, with
    the same value, before WeasyPrint validates them."""
    _renames[name.lower()] = target


def _without_whitespace(tokens):
    return [token for token in tokens if token.type not in ("whitespace", "comment")]


def _function_ident(tokens, function_name):
    """The ident of a value that is only ``function_name(<ident>)``, else None."""
    tokens = _without_whitespace(tokens)
    if len(tokens) != 1 or tokens[0].type != "function" or tokens[0].lower_name != function_name:
        return None
    arguments = _without_whitespace(tokens[0].arguments)
    if len(arguments) != 1 or arguments[0].type != "ident":
        return None
    return arguments[0]


def _declaration(original, name, value):
    return tinycss2.ast.Declaration(
        original.source_line, original.source_column, name, name.lower(), value,
        original.important)


def _translate(declaration):
    """The declaration with Prince syntax replaced by its WeasyPrint equivalent."""
    if declaration.type != "declaration":
        return declaration
    if declaration.lower_name in _renames:
        declaration = _declaration(
            declaration, _renames[declaration.lower_name], declaration.value)
    if declaration.lower_name in ("flow", "-prince-flow"):
        ident = _function_ident(declaration.value, "static")
        if ident is not None:
            running = tinycss2.ast.FunctionBlock(
                declaration.source_line, declaration.source_column, "running", [ident])
            return _declaration(declaration, "position", [running])
    elif declaration.lower_name == "content" and any(
        token.type == "function" and token.lower_name == "flow" for token in declaration.value
    ):
        value = [
            tinycss2.ast.FunctionBlock(
                token.source_line, token.source_column, "element", token.arguments)
            if token.type == "function" and token.lower_name == "flow" else token
            for token in declaration.value
        ]
        return _declaration(declaration, declaration.name, value)
    return declaration


def _region_box(context, page, state, region):
    """The laid-out box of the ``@top`` or ``@bottom`` region, or None without content.

    Mirrors make_box() of WeasyPrint's make_margin_boxes() for the content, then gives the
    box the full span instead of a third of it.
    """
    at_keyword = f"@{region}"
    style = context.style_for(page.page_type, at_keyword)
    if style is None or style["content"] in ("normal", "inhibit", "none"):
        return None
    _standardize_page_based_counters(style, at_keyword)
    box = boxes.MarginBox(at_keyword, style)
    box.is_generated = True
    # @margins mustn't manipulate page-context counters
    margin_state = copy.deepcopy(state)
    quote_depth, counter_values, counter_scopes, _page_groups = margin_state
    counter_scopes.append(set())
    build.update_counters(margin_state, box.style)
    box.children = build.content_to_boxes(
        box.style, box, quote_depth, counter_values, context.get_image_from_uri,
        context.target_collector, context.counter_style, context, page)
    box.process_whitespace()
    box.process_text_transform()
    box = build.create_anonymous_boxes(box)

    span = page.border_width()
    if region == "top":
        height, position_y = page.margin_top, 0
    else:
        height, position_y = page.margin_bottom, page.margin_top + page.border_height()
    resolve_percentages(box, (span, height))
    box.position_x = page.margin_left
    box.position_y = position_y
    # Over-constrained: the right margin gives way, as for a block; the top region's
    # top margin and the bottom region's bottom margin, as for WeasyPrint's side boxes.
    compute_fixed_dimension(context, box, span, False, False)
    compute_fixed_dimension(context, box, height, True, region == "top")
    return margin_box_content_layout(context, page, box)


def apply() -> None:
    original_preprocess = weasyprint.css.validation.preprocess_declarations

    def preprocess_declarations(base_url, declarations, prelude=None):
        yield from original_preprocess(
            base_url, [_translate(declaration) for declaration in declarations], prelude)

    # The module attribute serves the recursion for nested rules; weasyprint.css has its
    # own reference from its import.
    weasyprint.css.validation.preprocess_declarations = preprocess_declarations
    weasyprint.css.preprocess_declarations = preprocess_declarations

    weasyprint.css.PAGE_MARGIN_BOXES = weasyprint.css.PAGE_MARGIN_BOXES | set(REGIONS)
    weasyprint.html.UA_STYLESHEET.page_rules.extend(
        weasyprint.CSS(string=_UA_REGIONS).page_rules)

    original_make_margin_boxes = weasyprint.layout.page.make_margin_boxes

    def make_margin_boxes(context, page, state):
        generated = list(original_make_margin_boxes(context, page, state))
        yield from generated
        keywords = {box.at_keyword for box in generated}
        for region in REGIONS:
            if any(f"@{region}-{suffix}" in keywords for suffix in _SIDE_SUFFIXES):
                continue
            if (box := _region_box(context, page, state, region)) is not None:
                yield box

    weasyprint.layout.page.make_margin_boxes = make_margin_boxes
    weasyprint.layout.make_margin_boxes = make_margin_boxes
