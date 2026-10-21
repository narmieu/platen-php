"""Throwaway: clean calibration for superscript/subscript line growth and line-height: normal.

Each case is four single-line paragraphs R1, T, R2, R3 (margin 0):
growth above T = gap(R1,T) - gap(R2,R3); growth below T = gap(T,R2) - gap(R2,R3).
The cases are set in Liberation Sans (hhea metrics); the line-height: normal rows add DejaVu
Sans (hhea, no line gap) and DejaVu Sans with USE_TYPO_METRICS (typo metrics with a line gap).
Needs fontTools (calib_fonts.typo_dejavu).
"""
import sys

from calib_fonts import CALIB, face, typo_dejavu

out = sys.argv[1]
rows = []
cases = []
for p in (10, 20, 30):
    for lh_tag, lh in (("F10", f"{p}pt"), ("F12", f"{p * 1.2:g}pt"), ("F15", f"{p * 1.5:g}pt"), ("N12", "1.2"), ("N15", "1.5"), ("M", "normal")):
        for ss_tag, ss in (("d", ""), ("e", f"font-size:{p * 0.66:g}pt"), ("h", f"font-size:{p * 0.5:g}pt")):
            for kind in ("sup", "sub"):
                cid = f"{kind[2]}{p}{lh_tag}{ss_tag}"
                style = f"font-size:{p}pt;line-height:{lh}"
                inner = f'<{kind} style="{ss}">&reg;</{kind}>' if kind == "sup" else f'<{kind} style="{ss}">2</{kind}>'
                rows.append(f'<p style="{style}">A{cid} x</p>')
                rows.append(f'<p style="{style}">T{cid} Basic{inner} x</p>')
                rows.append(f'<p style="{style}">B{cid} x</p>')
                rows.append(f'<p style="{style}">C{cid} x</p>')
# line-height: normal in fonts whose hhea / typo / win metrics disagree, plus font-size: larger
for tag, fam in (("DejaVu", "'Calib DejaVu'"), ("Libera", "Calib"), ("Typo", "'Calib Typo'")):
    rows.append(f'<p style="font-family:{fam};font-size:20pt;line-height:normal">N{tag}1 x<br>N{tag}2 x<br>N{tag}3 x</p>')
for p in (10, 20):
    rows.append(f'<p style="font-size:{p}pt">Z{p} <span style="font-size:larger">larger{p}</span> <span style="font-size:smaller">smaller{p}</span></p>')
html = """<!doctype html><html lang="en"><head><meta charset="utf-8">
<style>""" + "\n".join((CALIB, face("Calib DejaVu", "DejaVuSans.ttf"), face("Calib Typo", typo_dejavu()))) + """
@page { size: A4; margin: 15mm; } body { font-family: Calib; margin: 0; } p { margin: 0; }</style>
</head><body>
""" + "\n".join(rows) + "\n</body></html>"
open(f"{out}/sup2.html", "w").write(html)
print(len(rows), "paragraphs")
