"""F4b: weight matching for @font-face families as Prince does it, CSS Fonts Level 3 §5.2.

Prince does not use the Level 4 rule that browsers follow. The quality layer's rule is
replaced with css3_match, which picks, for 500, the 400 face first and then the faces above
500, so italic 500 text of a family with faces {200, 700} is set in the 700 face.
"""

from collections.abc import Iterable

from ..quality import font_matching

Weight = int | tuple[int, int]


def css3_match(desired: int, available: Iterable[Weight]) -> int:
    """The weight of the face that CSS Fonts Level 3 §5.2 picks for ``desired`` among the
    weights ``available``: single weights or (min, max) ranges.

    A range that contains ``desired`` matches it exactly; otherwise its endpoint nearer to
    ``desired`` competes. Desired 400: 500 first, then the rule below 400. Desired 500: 400
    first, then the rule above 500. Below 400: ≤ desired descending, then above ascending.
    Above 500: ≥ desired ascending, then below descending.
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
    if desired == 400:
        order = [w for w in above if w == 500] + below + above
    elif desired == 500:
        order = [w for w in below if w == 400] + above + below
    elif desired < 400:
        order = below + above
    else:
        order = above + below
    return order[0]


def apply() -> None:
    font_matching.set_rule(css3_match)
