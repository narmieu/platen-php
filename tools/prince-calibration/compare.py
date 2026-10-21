"""Throwaway: compare a baseline PDF (DocRaptor) with a candidate PDF (platen) on every axis we can measure.

usage: compare.py <baseline.pdf> <candidate.pdf> <out_dir> [dpi]

Writes <out_dir>/report.json and per-page side-by-side images <out_dir>/page_NN.png
(baseline | candidate | diff). DocRaptor's public-key test watermark (two hatched vector bands per
page, not extractable text) is masked out of pixel diffs and vector-path comparisons only.
"""
import collections
import difflib
import json
import os
import re
import sys
import unicodedata

import numpy as np
import pymupdf
from PIL import Image, ImageDraw

MM = 72 / 25.4
WM_URI = "docraptor.com/public-key-watermark"
FLAGS = pymupdf.TEXTFLAGS_WORDS & ~pymupdf.TEXT_PRESERVE_LIGATURES


def mm(v):
    return round(v / MM, 2)


def hexcolor(rgb):
    if rgb is None:
        return None
    if isinstance(rgb, int):
        return f"#{rgb:06x}"
    vals = list(rgb)
    if len(vals) == 1:
        vals = vals * 3
    if len(vals) == 4:  # CMYK -> rough RGB, only used for labelling
        c, m, y, k = vals
        vals = [(1 - c) * (1 - k), (1 - m) * (1 - k), (1 - y) * (1 - k)]
    return "#" + "".join(f"{round(max(0, min(1, v)) * 255):02x}" for v in vals)


def watermark_bands(page):
    rects = [l["from"] for l in page.get_links() if WM_URI in (l.get("uri") or "")]
    bands = []
    for r in sorted(rects, key=lambda r: r.y0):
        lo, hi = r.y0 - 6, r.y1 + 6
        if bands and lo <= bands[-1][1]:
            bands[-1][1] = max(bands[-1][1], hi)
        else:
            bands.append([lo, hi])
    return [tuple(b) for b in bands]


def in_bands(rect, bands):
    return any(rect.y0 >= a - 1 and rect.y1 <= b + 1 for a, b in bands)


def is_watermark_path(d, bands):
    if not bands or not in_bands(d["rect"], bands):
        return False
    fill = d.get("fill")
    if d["type"] == "s" and not fill:
        return True
    if fill and tuple(round(v, 2) for v in fill) == (1.0, 1.0, 1.0) and (d.get("fill_opacity") or 1) < 0.95:
        return True
    return fill is not None and hexcolor(fill) in ("#adadad", "#454545", "#4a8fe3", "#4b8fe3", "#4a8ee3")


def norm_text(t):
    t = unicodedata.normalize("NFC", t).replace("­", "").replace(" ", " ")
    return t.strip()


def load(path):
    doc = pymupdf.open(path)
    pages = []
    for pno, page in enumerate(doc):
        bands = watermark_bands(page)
        spans = collections.Counter()
        span_boxes = []
        for b in page.get_text("dict", flags=FLAGS)["blocks"]:
            for line in b.get("lines", []):
                for s in line["spans"]:
                    txt = norm_text(s["text"])
                    if txt:
                        key = (re.sub(r"^[A-Z]{6}\+", "", s["font"]), round(s["size"], 1), hexcolor(s["color"]))
                        spans[key] += len(txt)
                        span_boxes.append((pymupdf.Rect(s["bbox"]), key))
        words = []
        for w in page.get_text("words", flags=FLAGS, sort=True):
            t = norm_text(w[4])
            if t:
                cx, cy = (w[0] + w[2]) / 2, (w[1] + w[3]) / 2
                font = next((k for r, k in span_boxes if r.x0 - 0.5 <= cx <= r.x1 + 0.5 and r.y0 - 0.5 <= cy <= r.y1 + 0.5), None)
                words.append({"p": pno, "x0": w[0], "y0": w[1], "x1": w[2], "y1": w[3], "t": t, "font": font})
        fills = collections.Counter()
        strokes = collections.Counter()
        n_paths = 0
        for d in page.get_drawings():
            if is_watermark_path(d, bands):
                continue
            n_paths += 1
            r = d["rect"]
            area = max(0.0, r.width) * max(0.0, r.height)
            if d.get("fill") is not None:
                fills[hexcolor(d["fill"])] += area / (MM * MM)
            if d.get("color") is not None and d["type"] in ("s", "fs"):
                strokes[hexcolor(d["color"])] += 1
        images = [
            {"bbox_mm": [mm(v) for v in i["bbox"]], "px": [i["width"], i["height"]], "cs": i.get("cs-name")}
            for i in page.get_image_info()
        ]
        links = [l.get("uri") for l in page.get_links() if WM_URI not in (l.get("uri") or "")]
        pages.append({
            "size_mm": [mm(page.rect.width), mm(page.rect.height)],
            "bands": bands,
            "words": words,
            "spans": spans,
            "fills": fills,
            "strokes": strokes,
            "n_paths": n_paths,
            "images": images,
            "links": links,
        })
    fonts = set()
    for pno in range(len(doc)):
        for f in doc.get_page_fonts(pno):
            fonts.add((re.sub(r"^[A-Z]{6}\+", "", f[3]), f[2], f[1] != "n/a"))
    cmyk = rgb = 0
    for xref in range(1, doc.xref_length()):
        try:
            obj = doc.xref_object(xref, compressed=True)
        except Exception:
            continue
        cmyk += obj.count("/DeviceCMYK")
        rgb += obj.count("/DeviceRGB")
    meta = {k: doc.metadata.get(k) for k in ("format", "title", "author", "subject", "creator", "producer")}
    return doc, pages, sorted(fonts), {"cmyk_refs": cmyk, "rgb_refs": rgb}, meta


def raster(page, dpi, bands):
    pix = page.get_pixmap(dpi=dpi, alpha=False)
    img = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)
    return img


def mask(img, bands_list, dpi):
    if not bands_list:
        return img
    img = img.copy()
    draw = ImageDraw.Draw(img)
    s = dpi / 72
    for a, b in bands_list:
        draw.rectangle([0, int(a * s), img.width, int(b * s) + 1], fill=(255, 255, 255))
    return img


def main():
    base_path, cand_path, out_dir = sys.argv[1:4]
    dpi = int(sys.argv[4]) if len(sys.argv) > 4 else 100
    os.makedirs(out_dir, exist_ok=True)
    bdoc, bpages, bfonts, bcs, bmeta = load(base_path)
    cdoc, cpages, cfonts, ccs, cmeta = load(cand_path)

    rep = {
        "files": {"baseline": base_path, "candidate": cand_path},
        "bytes": [os.path.getsize(base_path), os.path.getsize(cand_path)],
        "pages": [len(bpages), len(cpages)],
        "page_sizes_mm": [sorted({tuple(p["size_mm"]) for p in bpages}), sorted({tuple(p["size_mm"]) for p in cpages})],
        "meta": {"baseline": bmeta, "candidate": cmeta},
        "colorspace": {"baseline": bcs, "candidate": ccs},
        "fonts": {
            "baseline_only": [f for f in bfonts if f not in cfonts],
            "candidate_only": [f for f in cfonts if f not in bfonts],
            "common": [f for f in bfonts if f in cfonts],
        },
    }

    # --- text: global word sequence, then per-page placement and geometry of matched words
    bw = [w for p in bpages for w in p["words"]]
    cw = [w for p in cpages for w in p["words"]]
    sm = difflib.SequenceMatcher(None, [w["t"] for w in bw], [w["t"] for w in cw], autojunk=False)
    text_diffs = []
    page_moves = collections.Counter()
    deltas = collections.defaultdict(list)
    font_map = collections.Counter()
    width_ratio = collections.defaultdict(list)
    for tag, i1, i2, j1, j2 in sm.get_opcodes():
        if tag == "equal":
            for i, j in zip(range(i1, i2), range(j1, j2)):
                a, b = bw[i], cw[j]
                font_map[(a["font"], b["font"])] += 1
                if a["x1"] - a["x0"] > 3:
                    width_ratio[(a["font"], b["font"])].append((b["x1"] - b["x0"]) / (a["x1"] - a["x0"]))
                if a["p"] != b["p"]:
                    page_moves[(a["p"] + 1, b["p"] + 1)] += 1
                else:
                    deltas[a["p"] + 1].append((b["x0"] - a["x0"], b["y0"] - a["y0"], (b["x1"] - b["x0"]) - (a["x1"] - a["x0"])))
        else:
            text_diffs.append({
                "op": tag,
                "baseline": " ".join(w["t"] for w in bw[i1:i2])[:300],
                "candidate": " ".join(w["t"] for w in cw[j1:j2])[:300],
                "baseline_page": bw[i1]["p"] + 1 if i1 < len(bw) else None,
                "candidate_page": cw[j1]["p"] + 1 if j1 < len(cw) else None,
            })
    rep["text"] = {
        "words": [len(bw), len(cw)],
        "identical_word_sequence": [w["t"] for w in bw] == [w["t"] for w in cw],
        "similarity": round(sm.ratio(), 5),
        "diffs": text_diffs[:60],
        "n_diffs": len(text_diffs),
        "words_on_other_page": {f"{a}->{b}": n for (a, b), n in sorted(page_moves.items())},
        # (baseline font, size, color) -> (candidate font, size, color) for every matched word
        "font_map": [[list(a) if a else None, list(b) if b else None, n] for (a, b), n in font_map.most_common()],
        # median candidate/baseline word width per font pair: ~1.000 means same glyph metrics
        "width_ratio": [
            [list(a) if a else None, list(b) if b else None, len(v), round(float(np.median(v)), 4)]
            for (a, b), v in sorted(width_ratio.items(), key=lambda kv: -len(kv[1]))
        ],
    }
    geo = {}
    for p, ds in sorted(deltas.items()):
        arr = np.array(ds)
        dx, dy, dw = (np.abs(arr[:, k]) / MM for k in range(3))
        geo[p] = {
            "matched": len(ds),
            "dx_mm_median": round(float(np.median(dx)), 2), "dx_mm_p95": round(float(np.percentile(dx, 95)), 2), "dx_mm_max": round(float(dx.max()), 2),
            "dy_mm_median": round(float(np.median(dy)), 2), "dy_mm_p95": round(float(np.percentile(dy, 95)), 2), "dy_mm_max": round(float(dy.max()), 2),
            "width_mm_p95": round(float(np.percentile(dw, 95)), 2),
            "over_1mm": int(((dx > 1) | (dy > 1)).sum()), "over_3mm": int(((dx > 3) | (dy > 3)).sum()),
        }
    rep["geometry_same_page_words"] = geo

    # --- typography: (font, size, color) usage by character count
    bsp = sum((p["spans"] for p in bpages), collections.Counter())
    csp = sum((p["spans"] for p in cpages), collections.Counter())
    rep["typography"] = {
        "baseline_only": [[*k, n] for k, n in bsp.most_common() if k not in csp][:25],
        "candidate_only": [[*k, n] for k, n in csp.most_common() if k not in bsp][:25],
        "count_mismatch": [[*k, bsp[k], csp[k]] for k in bsp if k in csp and bsp[k] != csp[k]][:25],
    }

    # --- per page: header/footer bands, images, vector colors, links, pixels
    per_page = []
    for i in range(max(len(bpages), len(cpages))):
        b = bpages[i] if i < len(bpages) else None
        c = cpages[i] if i < len(cpages) else None
        entry = {"page": i + 1}
        if b is None or c is None:
            entry["missing_in"] = "baseline" if b is None else "candidate"
            per_page.append(entry)
            continue

        def band_text(page, lo, hi):
            return " ".join(w["t"] for w in page["words"] if w["y0"] >= lo and w["y1"] <= hi)

        h = b["size_mm"][1] * MM
        entry["header_text"] = [band_text(b, 0, 30 * MM), band_text(c, 0, 30 * MM)]
        entry["footer_text"] = [band_text(b, h - 30 * MM, h), band_text(c, h - 30 * MM, h)]
        entry["images"] = [b["images"], c["images"]]
        bf, cf = b["fills"], c["fills"]
        colors = sorted(set(bf) | set(cf))
        entry["fill_area_mm2_by_color"] = {
            col: [round(bf.get(col, 0)), round(cf.get(col, 0))]
            for col in colors
            if abs(bf.get(col, 0) - cf.get(col, 0)) > max(25, 0.05 * max(bf.get(col, 0), cf.get(col, 0)))
        }
        entry["paths"] = [b["n_paths"], c["n_paths"]]
        entry["links"] = [b["links"], c["links"]]

        bimg = raster(bdoc[i], dpi, b["bands"])
        cimg = raster(cdoc[i], dpi, c["bands"])
        if cimg.size != bimg.size:
            cimg = cimg.resize(bimg.size)
        allbands = b["bands"] + c["bands"]
        bm, cm = mask(bimg, allbands, dpi), mask(cimg, allbands, dpi)
        a1 = np.asarray(bm, dtype=np.int16)
        a2 = np.asarray(cm, dtype=np.int16)
        diff = np.abs(a1 - a2).max(axis=2)
        changed = diff > 40
        entry["pixels_changed_pct"] = round(100 * float(changed.mean()), 3)
        entry["mean_abs_diff"] = round(float(diff.mean()), 3)
        ink_b = (a1.min(axis=2) < 200)
        ink_c = (a2.min(axis=2) < 200)
        entry["ink_pct"] = [round(100 * float(ink_b.mean()), 2), round(100 * float(ink_c.mean()), 2)]

        # diff panel: gray baseline, red = only in candidate, blue = only in baseline
        panel = np.full(a1.shape, 255, dtype=np.uint8)
        g = (a1.mean(axis=2) * 0.35 + 255 * 0.65).astype(np.uint8)
        panel[..., 0] = panel[..., 1] = panel[..., 2] = g
        only_c = changed & ink_c & ~ink_b
        only_b = changed & ink_b & ~ink_c
        both = changed & ~(only_c | only_b)
        panel[only_c] = (220, 0, 0)
        panel[only_b] = (0, 70, 220)
        panel[both] = (230, 140, 0)
        trio = Image.new("RGB", (bimg.width * 3 + 20, bimg.height), (120, 120, 120))
        trio.paste(bm, (0, 0))
        trio.paste(cm, (bimg.width + 10, 0))
        trio.paste(Image.fromarray(panel), (2 * bimg.width + 20, 0))
        trio.save(os.path.join(out_dir, f"page_{i + 1:02d}.png"))
        per_page.append(entry)
    rep["per_page"] = per_page

    with open(os.path.join(out_dir, "report.json"), "w") as fh:
        json.dump(rep, fh, indent=1, ensure_ascii=False, default=str)

    # --- console summary
    print(f"pages {rep['pages'][0]} vs {rep['pages'][1]} | sizes {rep['page_sizes_mm']} | words {rep['text']['words']} "
          f"| text identical: {rep['text']['identical_word_sequence']} (sim {rep['text']['similarity']}, {rep['text']['n_diffs']} diff runs)")
    print(f"words on other page: {rep['text']['words_on_other_page'] or 'none'}")
    print(f"fonts baseline-only: {rep['fonts']['baseline_only']}")
    print(f"fonts candidate-only: {rep['fonts']['candidate_only']}")
    print(f"colorspace refs: {rep['colorspace']}")
    for e in per_page:
        if "missing_in" in e:
            print(f"  p{e['page']:>2}: missing in {e['missing_in']}")
            continue
        g = geo.get(e["page"], {})
        hdr_ok = e["header_text"][0] == e["header_text"][1]
        ftr_ok = e["footer_text"][0] == e["footer_text"][1]
        print(f"  p{e['page']:>2}: px {e['pixels_changed_pct']:>6}% | ink {e['ink_pct']} | words dy p95 {g.get('dy_mm_p95')}mm max {g.get('dy_mm_max')} "
              f"dx p95 {g.get('dx_mm_p95')} | >1mm {g.get('over_1mm')}/{g.get('matched')} | hdr {'=' if hdr_ok else '≠'} ftr {'=' if ftr_ok else '≠'} "
              f"| imgs {len(e['images'][0])}/{len(e['images'][1])} | color-area diffs {len(e['fill_area_mm2_by_color'])}")


main()
