"""F7a: ``sub`` and ``sup`` inherit the line-height, so a superscript grows its line as in
CSS 2.1, browsers and Prince.

WeasyPrint's HTML user-agent stylesheet has ``sub, sup { line-height: normal }``, which the
HTML specification's does not. It gives a superscript a small inline box that seldom
reaches above the line, so the line does not grow where browsers and Prince grow it.

A user-agent rule ``sub, sup { line-height: inherit }`` after WeasyPrint's own restores the
paragraph's line-height. With F6's content area (line_metrics), an inline box of font size
f and line-height L spans baseline − (L + (A − D)·f)/2 to baseline + (L − (A − D)·f)/2 and
the line box spans the strut and every inline box, as CSS 2.1 §10.8 says; the raise and
drop stay WeasyPrint's (half the element's own font size), as do the sizes.

add_user_agent_css() lets other modules add user-agent rules for HTML documents.
"""

import weasyprint

_LINE_HEIGHT = "sub, sup { line-height: inherit }"

_stylesheets: list[weasyprint.CSS] = []


def add_user_agent_css(css: str) -> None:
    """Add the rules of ``css`` to the user-agent stylesheets of HTML documents, after
    WeasyPrint's and after earlier additions, so that they win over those at the same
    specificity."""
    _stylesheets.append(weasyprint.CSS(string=css))


def apply() -> None:
    original_ua_stylesheets = weasyprint.HTML._ua_stylesheets

    def _ua_stylesheets(self, forms=False):
        return [*(original_ua_stylesheets(self, forms) or []), *_stylesheets]

    weasyprint.HTML._ua_stylesheets = _ua_stylesheets
    add_user_agent_css(_LINE_HEIGHT)
