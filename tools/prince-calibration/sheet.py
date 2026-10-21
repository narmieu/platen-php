"""Throwaway: contact sheet of every page of several PDFs, one column per PDF.

usage: sheet.py <out.png> <dpi> <label=pdf> [<label=pdf> ...]
"""
import sys

import pymupdf
from PIL import Image, ImageDraw

out, dpi, *specs = sys.argv[1:]
dpi = int(dpi)
cols = []
for spec in specs:
    label, path = spec.split("=", 1)
    doc = pymupdf.open(path)
    pages = []
    for page in doc:
        pix = page.get_pixmap(dpi=dpi)
        pages.append(Image.frombytes("RGB", (pix.width, pix.height), pix.samples))
    cols.append((label, pages))

pad, head = 12, 28
w = max(p.width for _, ps in cols for p in ps)
h = max(p.height for _, ps in cols for p in ps)
rows = max(len(ps) for _, ps in cols)
sheet = Image.new("RGB", (len(cols) * (w + pad) + pad, head + rows * (h + pad) + pad), (200, 200, 200))
draw = ImageDraw.Draw(sheet)
for c, (label, pages) in enumerate(cols):
    x = pad + c * (w + pad)
    draw.text((x, 8), f"{label} ({len(pages)} pages)", fill=(0, 0, 0))
    for r, img in enumerate(pages):
        y = head + r * (h + pad)
        sheet.paste(img, (x, y))
        draw.rectangle([x - 1, y - 1, x + img.width, y + img.height], outline=(90, 90, 90))
        draw.text((x + 4, y + 4), str(r + 1), fill=(255, 0, 0))
sheet.save(out)
print(out, sheet.size)
