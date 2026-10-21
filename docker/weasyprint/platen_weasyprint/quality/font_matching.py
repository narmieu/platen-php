"""F4a: weight matching for @font-face families, CSS Fonts Level 4 §5.2, like browsers.

WeasyPrint gives fontconfig the weight of each @font-face rule and lets it pick the face
of the closest weight on its own, non-linear scale (300 is 50 there, 100 is 0, 400 is 80),
which differs from CSS where faces are missing. Here every font configuration keeps the
weights of the @font-face faces it loaded, by family and style. When a style's first font
family is one of them and the matching rule picks, among the faces of that style (or of
the fallback style, as in CSS), another face than fontconfig's nearest weight, the weight
given to Pango is the picked one, which fontconfig then finds exactly. The one weight of
the description also selects the fallback fonts for glyphs the family lacks, so it is only
changed when the face differs: otherwise fallback glyphs keep the requested weight, as in
browsers. System fonts are left to fontconfig.

- set_rule() replaces the rule (the Prince layer uses CSS Fonts Level 3). The rule is read
  each time a font description is made, and nothing computed with it is cached.
- The font-weight descriptor also takes a range, ``font-weight: 100 900`` (variable fonts),
  which fontconfig gets as a weight range. A face without the descriptor keeps its own
  weight, so its style of its family stays with fontconfig.
- Faces of one family, style and weight (unicode-range subsets) get Pango fonts with the
  same description, by which WeasyPrint's PDF streams keyed and named their fonts: every
  glyph went to the font program of the first face. The streams now key fonts by face too.

get_font_description is imported by name into text.line_break and pdf.anchors, so the
patch replaces it there too.
"""

from collections.abc import Callable, Iterable
from hashlib import md5
from pathlib import Path
from tempfile import mkdtemp
from xml.etree.ElementTree import Element, SubElement, tostring

import weasyprint.pdf.anchors
import weasyprint.pdf.stream
import weasyprint.text.fonts
import weasyprint.text.line_break
from weasyprint.css.tokens import get_keyword, get_number
from weasyprint.css.validation import descriptors
from weasyprint.pdf.fonts import Font
from weasyprint.text.ffi import ffi, fontconfig, pango

from ..fontmetrics import _face_address

Weight = int | tuple[int, int]
Rule = Callable[[int, Iterable[Weight]], int]

_DESCRIPTION_MODULES = (
    weasyprint.text.fonts,
    weasyprint.text.line_break,
    weasyprint.pdf.anchors,
)

# The faces of these styles stand in, in this order, when a family has none of the style.
_STYLE_ORDER = {
    "normal": ("normal", "oblique", "italic"),
    "italic": ("italic", "oblique", "normal"),
    "oblique": ("oblique", "italic", "normal"),
}

_KEYWORD_WEIGHTS = {"normal": 400, "bold": 700}

# fontconfig's weight scale for CSS weights (FcWeightFromOpenTypeDouble), which Pango uses
# for the weight of a font description: linear between these points.
_FC_WEIGHTS = (
    (0, 0), (100, 0), (200, 40), (300, 50), (350, 55), (380, 75), (400, 80),
    (500, 100), (600, 180), (700, 200), (800, 205), (900, 210), (1000, 215),
)


def css4_match(desired: int, available: Iterable[Weight]) -> int:
    """The weight of the face that CSS Fonts Level 4 §5.2 picks for ``desired`` among the
    weights ``available``: single weights or (min, max) ranges.

    A range that contains ``desired`` matches it exactly; otherwise its endpoint nearer to
    ``desired`` competes. Desired 400–500: weights ≥ desired up to 500 ascending, then
    < desired descending, then > 500 ascending; below 400: ≤ desired descending, then
    above ascending; above 500: ≥ desired ascending, then below descending.
    """
    weights = set()
    for weight in available:
        if isinstance(weight, tuple):
            low, high = weight
            if low <= desired <= high:
                return desired
            weight = low if desired < low else high
        weights.add(weight)
    if not weights:
        raise ValueError("no weights available")
    if desired in weights:
        return desired
    below = sorted((weight for weight in weights if weight < desired), reverse=True)
    above = sorted(weight for weight in weights if weight > desired)
    if 400 <= desired <= 500:
        order = [w for w in above if w <= 500] + below + [w for w in above if w > 500]
    elif desired < 400:
        order = below + above
    else:
        order = above + below
    return order[0]


_rule: Rule = css4_match


def set_rule(fn: Rule) -> None:
    """Match the weights of @font-face families with ``fn`` instead of css4_match, from
    the next font description on."""
    global _rule
    _rule = fn


def faces(font_config) -> dict[tuple[str, str], list[Weight | None]]:
    """The weights of the @font-face faces loaded into ``font_config``, by (lower-cased
    family, style). None stands for a face without a font-weight descriptor."""
    return vars(font_config).setdefault("_platen_faces", {})


def matched_weight(style) -> int | None:
    """The weight that the rule picks for ``style`` among the faces of its first font
    family, or None to keep the requested weight: when that family is no @font-face
    family, when one of the faces it would pick from has no font-weight descriptor, or
    when fontconfig's nearest weight picks the same face anyway."""
    font_config = getattr(style, "font_config", None)
    registry = {} if font_config is None else faces(font_config)
    if not registry or not style["font_family"]:
        return None
    family = style["font_family"][0].lower()
    for font_style in _STYLE_ORDER[style["font_style"]]:
        weights = registry.get((family, font_style))
        if weights:
            if None in weights:
                return None
            desired = style["font_weight"]
            picked = _rule(desired, weights)
            return None if picked == _fontconfig_pick(desired, weights) else picked
    return None


def _fontconfig_pick(desired: int, weights: Iterable[Weight]) -> int | None:
    """The weight of the face fontconfig picks for ``desired`` among ``weights``: the
    nearest on its scale, ``desired`` itself in a range that contains it. None when two
    weights are equally near."""
    target = _fc_weight(desired)
    nearest, best = set(), None
    for weight in weights:
        if isinstance(weight, tuple):
            low, high = weight
            weight = min(max(desired, low), high)
        distance = abs(_fc_weight(weight) - target)
        if best is None or distance < best:
            nearest, best = {weight}, distance
        elif distance == best:
            nearest.add(weight)
    return nearest.pop() if len(nearest) == 1 else None


def _fc_weight(weight: float) -> float:
    for (x1, y1), (x2, y2) in zip(_FC_WEIGHTS, _FC_WEIGHTS[1:]):
        if weight <= x2:
            return y1 + (max(weight, x1) - x1) * (y2 - y1) / (x2 - x1)
    return _FC_WEIGHTS[-1][1]


def _font_path(font_config, rule_descriptors) -> Path:
    """Where WeasyPrint's add_font_face stores the font of ``rule_descriptors``."""
    if font_config._folder is None:
        font_config._folder = Path(mkdtemp(prefix="weasyprint-"))
    digest = md5(str(rule_descriptors).encode(), usedforsecurity=False).hexdigest()
    return font_config._folder / digest


def _assign_weight_range(font_config, font_path: Path, weights: tuple[int, int]) -> None:
    """Make fontconfig give the font stored at ``font_path`` the weight range ``weights``
    when add_font_face scans it."""
    root = Element("fontconfig")
    match = SubElement(root, "match", target="scan")
    test = SubElement(match, "test", name="file", compare="eq")
    SubElement(test, "string").text = font_path.as_posix()
    edit = SubElement(match, "edit", name="weight", mode="assign_replace")
    range_ = SubElement(edit, "range")
    for weight in weights:
        SubElement(range_, "double").text = repr(_fc_weight(weight))
    header = (
        b'<?xml version="1.0"?>',
        b'<!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd">')
    xml = b"\n".join((*header, tostring(root, encoding="utf-8")))
    fontconfig.FcConfigParseAndLoadFromMemory(font_config._config, xml, True)


def _absolute_weight(token) -> int | None:
    if (keyword := get_keyword(token)) is not None:
        return _KEYWORD_WEIGHTS.get(keyword)
    number = get_number(token)
    if number is not None and 1 <= number.value <= 1000:
        return round(number.value)
    return None


def _face_of(font: Font) -> int:
    return int(ffi.cast("uintptr_t", font.hb_face))


def _hash_apart(font: Font, fonts: Iterable[Font]) -> None:
    """Give ``font`` a PDF name that no font of another face in ``fonts`` has.

    WeasyPrint names a font after its description, which faces of one family, style and
    weight share; the fonts of one face keep sharing their name, as in WeasyPrint.
    """
    taken = {other.hash for other in fonts if _face_of(other) != _face_of(font)}
    base, salt = font.hash, 0
    while font.hash in taken:
        salt += 1
        digest = md5(f"{base}#{salt}".encode(), usedforsecurity=False).digest()[:6]
        font.hash = "".join(chr(65 + letter % 26) for letter in digest)
    if salt:
        font.name = b"/" + font.hash.encode() + font.name[font.name.index(b"+"):]


def apply() -> None:
    original_get_font_description = weasyprint.text.fonts.get_font_description
    original_add_font_face = weasyprint.text.fonts.FontConfiguration.add_font_face
    original_font_weight = descriptors.DESCRIPTORS["font-face"]["font-weight"]
    get_pango_font_key = weasyprint.pdf.stream.get_pango_font_key

    def get_font_description(style):
        """Get font description string out of given style."""
        font_description = original_get_font_description(style)
        if (weight := matched_weight(style)) is not None:
            pango.pango_font_description_set_weight(font_description, weight)
        return font_description

    def add_font_face(self, rule_descriptors, url_fetcher):
        """Add a font face to the Fontconfig configuration."""
        weight = rule_descriptors.get("font_weight")
        if isinstance(weight, tuple):
            # WeasyPrint only writes single weights: fontconfig gets the range from a rule
            # of its own. The range stays in the descriptors, which name the stored font.
            rule_descriptors = {
                **{k: v for k, v in rule_descriptors.items() if k != "font_weight"},
                "platen_font_weight_range": weight,
            }
            _assign_weight_range(self, _font_path(self, rule_descriptors), weight)
        original_add_font_face(self, rule_descriptors, url_fetcher)
        if _font_path(self, rule_descriptors).exists():
            family = rule_descriptors["font_family"].lower()
            key = family, rule_descriptors.get("font_style", "normal")
            faces(self).setdefault(key, []).append(_KEYWORD_WEIGHTS.get(weight, weight))

    def font_weight(tokens):
        """``font-weight`` descriptor validation, with the ranges of CSS Fonts Level 4."""
        if len(tokens) != 2:
            return original_font_weight(tokens)
        weights = [_absolute_weight(token) for token in tokens]
        if None not in weights:
            return tuple(sorted(weights))

    font_weight.wants_base_url = original_font_weight.wants_base_url

    def add_font(self, pango_font):
        key, description, font_size = get_pango_font_key(pango_font)
        key = key, _face_address(pango_font)
        if key not in self._fonts:
            font = Font(pango_font, description, font_size)
            _hash_apart(font, self._fonts.values())
            self._fonts[key] = font
        return self._fonts[key], font_size

    for module in _DESCRIPTION_MODULES:
        module.get_font_description = get_font_description
    weasyprint.text.fonts.FontConfiguration.add_font_face = add_font_face
    descriptors.DESCRIPTORS["font-face"]["font-weight"] = font_weight
    weasyprint.pdf.stream.Stream.add_font = add_font
