"""@font-face rules for the calibration documents, with free fonts only: DejaVu, Liberation
and tests/Support/fonts/platen-test.woff (Liberation Sans cut down to printable ASCII, so
it has no U+2010 and no č ć š đ ž).

The fonts are embedded as data: URIs, so DocRaptor and platen load the very same files
without reaching this machine. The DejaVu and Liberation files are read from the
directories in FONT_DIRS (Debian's fonts-dejavu-core and fonts-liberation; the platen dev
container has them).
"""
import base64
import io
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
FONT_DIRS = (
    Path("/usr/share/fonts/truetype/dejavu"),
    Path("/usr/share/fonts/truetype/liberation"),
    REPO / "tests" / "Support" / "fonts",
)
MEDIA_TYPES = {".ttf": "font/ttf", ".otf": "font/otf", ".woff": "font/woff", ".woff2": "font/woff2"}


def font_path(file):
    for directory in FONT_DIRS:
        if (directory / file).is_file():
            return directory / file
    raise FileNotFoundError(f"{file} is in none of {', '.join(map(str, FONT_DIRS))}")


def data_uri(data, suffix):
    return f"data:{MEDIA_TYPES[suffix]};base64,{base64.b64encode(data).decode()}"


def face(family, file, weight=None, style=None):
    """An @font-face rule: ``file`` (a file name in FONT_DIRS, or (bytes, ".ttf")) as the
    face of ``family``, with the font-weight / font-style descriptors when given."""
    if isinstance(file, tuple):
        data, suffix = file
    else:
        path = font_path(file)
        data, suffix = path.read_bytes(), path.suffix
    descriptors = [f'font-family: "{family}"', f'src: url("{data_uri(data, suffix)}")']
    if weight is not None:
        descriptors.append(f"font-weight: {weight}")
    if style is not None:
        descriptors.append(f"font-style: {style}")
    return "@font-face { " + "; ".join(descriptors) + " }"


def renamed(file, family, postscript_name, weight, style="normal"):
    """``file`` (in FONT_DIRS) as a face of the font family ``family``, as (bytes, ".ttf"):
    family name ``family`` (name ID 1), full and PostScript name ``postscript_name``,
    ``weight`` as usWeightClass. Faces of one @font-face family built from fonts of
    different families are then one family inside too, as in a web font kit; WeasyPrint
    (fontconfig) otherwise draws every weight with one of the faces. The PostScript name
    tells which face a PDF used. Needs fontTools."""
    from fontTools.ttLib import TTFont

    font = TTFont(font_path(file))
    names = font["name"]
    for name_id in (1, 2, 3, 4, 6, 16, 17):
        names.removeNames(nameID=name_id)
    for name_id, value in ((1, family), (2, "Italic" if style == "italic" else "Regular"),
                           (3, postscript_name), (4, postscript_name), (6, postscript_name)):
        names.setName(value, name_id, 3, 1, 0x409)
    font["OS/2"].usWeightClass = weight
    return _saved(font)


def typo_dejavu():
    """DejaVu Sans with USE_TYPO_METRICS (OS/2 fsSelection bit 7) set, as (bytes, ".ttf"):
    its line metrics are then the OS/2 typo values (ascender 1556, descender -492, line gap
    410 of 2048 units per em) instead of hhea (1901, -483, 0). The bit is defined from OS/2
    version 4, so the table is raised from version 1 to 4. Needs fontTools."""
    from fontTools.otlLib.maxContextCalc import maxCtxFont
    from fontTools.ttLib import TTFont

    font = TTFont(font_path("DejaVuSans.ttf"))
    os2, glyphs = font["OS/2"], font["glyf"]
    cmap = font.getBestCmap()
    os2.version = 4
    os2.sxHeight = glyphs[cmap[ord("x")]].yMax
    os2.sCapHeight = glyphs[cmap[ord("H")]].yMax
    os2.usDefaultChar, os2.usBreakChar = 0, 32
    os2.usMaxContext = maxCtxFont(font)
    os2.fsSelection |= 1 << 7
    return _saved(font)


def _saved(font):
    out = io.BytesIO()
    font.save(out)
    return out.getvalue(), ".ttf"


# The faces most documents use. "Calib" is Liberation Sans (every glyph the documents
# need, hhea metrics); "Platen Test" lacks U+2010 and every non-ASCII letter.
CALIB = face("Calib", "LiberationSans-Regular.ttf")
PLATEN_TEST = face("Platen Test", "platen-test.woff")
