"""Throwaway: generate Prince calibration documents (free fonts only, see calib_fonts.py).

usage: make_calib.py <out_dir> <word_width_pt> <space_width_pt>
(word/space widths of "nnnn" and " " in Liberation Sans at 10pt: 22.246 2.778; 0 = skip the
justification doc)
"""
import os
import sys

from calib_fonts import CALIB, PLATEN_TEST, face, renamed

out = sys.argv[1]
word_w = float(sys.argv[2])
space_w = float(sys.argv[3])
os.makedirs(out, exist_ok=True)

HEAD = """<!doctype html><html lang="{lang}"><head><meta charset="utf-8"><title>calib</title>
<style>
{faces}
@page {{ size: A4; margin: 15mm; }}
body {{ font-family: {body}; font-size: 10pt; margin: 0; }}
p {{ margin: 0 0 3pt; }}
{css}
</style></head><body>
"""

# A: weight matching, one document per face set of the family "Calib W" (spec §5 F4). Each
# weight is another font file, renamed to "Calib W"; its PostScript name in the PDF
# (CalibW-<weight>, CalibW-Italic-<weight>) tells which face was picked. Needs fontTools.
NORMAL_FILES = {200: "DejaVuSansMono.ttf", 300: "LiberationMono-Regular.ttf", 400: "DejaVuSerif.ttf",
                500: "LiberationSerif-Regular.ttf", 700: "DejaVuSerif-Bold.ttf", 900: "LiberationSerif-Bold.ttf"}
ITALIC_FILES = {200: "LiberationMono-Italic.ttf", 700: "LiberationSerif-BoldItalic.ttf"}
FACE_SETS = {  # name: (normal weights, italic weights); no italic faces = italic uses the normal ones
    "a": ((200, 300, 400, 500, 700, 900), (200, 700)),
    "b": ((200, 300, 500, 700), ()),
    "c": ((200, 400, 500, 700), ()),
}
for name, (normal, italic) in FACE_SETS.items():
    faces = [face("Calib W", renamed(NORMAL_FILES[w], "Calib W", f"CalibW-{w}", w), w, "normal") for w in normal]
    faces += [face("Calib W", renamed(ITALIC_FILES[w], "Calib W", f"CalibW-Italic-{w}", w, "italic"), w, "italic")
              for w in italic]
    rows = []
    for style in ("normal", "italic"):
        for w in range(100, 1000, 100):
            tag = f"W{w}{style[0].upper()}"
            rows.append(f'<p style="font-weight:{w};font-style:{style}">{tag} Hamburgefonstiv</p>')
    with open(f"{out}/weights_{name}.html", "w") as fh:
        fh.write(HEAD.format(lang="en", faces="\n".join(faces), body='"Calib W"', css="") + "\n".join(rows) + "\n</body></html>")

# A2: generic families and glyph fallback (Platen Test has no non-ASCII letters)
families = ["serif", "sans-serif", "monospace", "cursive", "fantasy", "system-ui", "Arial", "Helvetica",
            "'Times New Roman'", "Times", "'Courier New'", "Courier", "Georgia", "Verdana", "'DejaVu Sans'",
            "'Liberation Sans'", "NoSuchFont"]
rows = [f'<p style="font-family:{f}">F{i:02d} Hamburgefonstiv</p>' for i, f in enumerate(families)]
fallback = [
    ("FB1", "'Platen Test'"),
    ("FB2", "'Platen Test', serif"),
    ("FB3", "'Platen Test', sans-serif"),
    ("FB4", "'Platen Test', monospace"),
    ("FB5", "'Platen Test', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"),
]
rows += [f'<p style="font-family:{f}">{tag} ab čćšđž ČĆŠĐŽ</p>' for tag, f in fallback]
rows.append("<p>" + " ".join(f"F{i:02d}={f}" for i, f in enumerate(families)).replace("'", "") + "</p>")
with open(f"{out}/families.html", "w") as fh:
    fh.write(HEAD.format(lang="en", faces=PLATEN_TEST, body="'Platen Test'", css="") + "\n".join(rows) + "\n</body></html>")

# B: layout behaviours with a font that has every glyph (Calib = Liberation Sans, no fallback)
css = """
.sup p { margin: 0 0 6pt; }
.box { width: 60mm; }
ul, ol { margin: 0 0 6pt 20mm; padding: 0; }
.hy { width: 22mm; hyphens: auto; text-align: left; margin-bottom: 6pt; }
@page wide { margin: 20mm 30mm; @top { content: "TOPREGION"; background: #ff0000; }
             @bottom { content: "BOTTOMREGION"; background: #00ff00; } }
.wide { page: wide; break-before: page; }
@page cmyk { -prince-pdf-page-colorspace: cmyk; }
.cmyk { page: cmyk; break-before: page; }
.rgbpage { break-before: page; }
.sw { width: 20mm; height: 8mm; display: inline-block; margin: 2pt; }
"""
rows = ['<div class="sup">']
for size in (8, 10, 14, 20, 30):
    for mode, lh in (("F", f"{round(size * 1.2, 2)}pt"), ("N", "1.5"), ("M", "normal")):
        tag = f"S{size}{mode}"
        rows.append(f'<p style="font-size:{size}pt;line-height:{lh}">{tag}a Basic<sup>&reg;</sup> x<br>{tag}b line<br>{tag}c line</p>')
        rows.append(f'<p style="font-size:{size}pt;line-height:{lh}">{tag}d Basic<sub>2</sub> x<br>{tag}e line<br>{tag}f line</p>')
    rows.append(f'<p style="font-size:{size}pt;line-height:{round(size * 1.2, 2)}pt">S{size}Xa Basic<sup style="font-size:{round(size * 0.66, 1)}pt">&reg;</sup> x<br>S{size}Xb line<br>S{size}Xc line</p>')
rows.append("</div>")
for size in (8, 12, 16):
    rows.append(f'<ul style="font-size:{size}pt;list-style-type:disc"><li>L{size}D item one</li><li>L{size}D item two</li></ul>')
    rows.append(f'<ol style="font-size:{size}pt"><li>L{size}O item one</li><li>L{size}O item two</li></ol>')
    rows.append(f'<ul style="font-size:{size}pt;list-style-type:disc;list-style-position:inside"><li>L{size}I item one</li></ul>')
long_text = "Characterization internationalization responsibilities incomprehensibilities representative"
rows.append(f'<p class="hy" lang="en">HYK {long_text}</p>')
rows.append(f'<p class="hy" lang="en" style="font-family:\'Platen Test\'">HYT {long_text}</p>')
rows.append(f'<p class="hy" lang="en" style="font-family:Arial">HYA {long_text}</p>')
rows.append(f'<p class="hy" lang="en" style="font-family:\'DejaVu Sans\'">HYD {long_text}</p>')
rows.append('<div class="wide"><p>WIDE page body</p></div>')
swatches = "".join(f'<span class="sw" style="background:{c}"></span>' for c in ("#000000", "#808080", "#ffffff", "#ff0000", "#053384", "rgba(0,0,0,0.5)"))
text = '<p style="color:#000">CMYKtext black</p><p style="color:#808080">CMYKtext gray</p><p style="color:#053384">CMYKtext navy</p>'
rows.append(f'<div class="cmyk"><p>CMYK page</p>{swatches}{text}</div>')
rows.append(f'<div class="rgbpage"><p>RGB page</p>{swatches.replace("CMYK", "RGB")}{text.replace("CMYKtext", "RGBtext")}</div>')
with open(f"{out}/layout.html", "w") as fh:
    fh.write(HEAD.format(lang="en", faces=CALIB + "\n" + PLATEN_TEST, body="Calib", css=css) + "\n".join(rows) + "\n</body></html>")

# C: justification shrink limit. Line 1 = 10 words "nnnn" (9 spaces); boxes shrink in 0.05pt steps.
if word_w > 0:
    natural = 10 * word_w + 9 * space_w
    css = (".j { text-align: justify; margin: 0 0 4pt; }\n"
           ".j20 { font-size: 20pt; }\n")
    rows = []
    step = 0.05
    # Markers sit at the end, so line 1 is exactly ten "nnnn" (natural width known).
    for k in range(0, 200):
        width = natural - k * step
        rows.append(f'<p class="j" style="width:{width:.3f}pt">' + " ".join(["nnnn"] * 10) + f" nnnn nnnn J{k:03d}</p>")
    # Last-line behaviour: exactly ten words that only fit when shrunk (tag in its own paragraph)
    for k in range(0, 200, 10):
        width = natural - k * step
        rows.append(f'<p>K{k:03d}</p><p class="j" style="width:{width:.3f}pt">' + " ".join(["nnnn"] * 10) + "</p>")
    # Same test at 20pt: widths double
    for k in range(0, 200, 4):
        width = 2 * (natural - k * step)
        rows.append(f'<p class="j j20" style="width:{width:.3f}pt">' + " ".join(["nnnn"] * 10) + f" nnnn T{k:03d}</p>")
    with open(f"{out}/justify.html", "w") as fh:
        fh.write(HEAD.format(lang="en", faces=CALIB, body="Calib", css=css) + "\n".join(rows) + "\n</body></html>")
print("written", sorted(os.listdir(out)))
