"""Throwaway: render calibration HTML files on DocRaptor (public test key) and local platen.

usage: PLATEN_API_KEY=<key> [PLATEN_URL=http://localhost:8080] render_calib.py <dir> <name> [<name> ...]
       -> <dir>/<name>.docraptor.pdf, <dir>/<name>.platen.pdf
"""
import base64
import json
import os
import sys
import urllib.error
import urllib.request

PLATEN_URL = os.environ.get("PLATEN_URL", "http://localhost:8080").rstrip("/")
PLATEN_API_KEY = os.environ.get("PLATEN_API_KEY") or sys.exit("Set PLATEN_API_KEY to a key from platen's PLATEN_API_KEYS")
TARGETS = {
    # DocRaptor's public key only renders test documents (watermarked, free)
    "docraptor": ("https://api.docraptor.com/docs", "YOUR_API_KEY_HERE", True),
    "platen": (f"{PLATEN_URL}/docs", PLATEN_API_KEY, False),
}

directory, *names = sys.argv[1:]
for name in names:
    html = open(f"{directory}/{name}.html", encoding="utf-8").read()
    for target, (url, key, test) in TARGETS.items():
        body = json.dumps({
            "document_type": "pdf", "test": test, "document_content": html, "name": f"{name}.pdf",
            "javascript": False, "pipeline": "10", "prince_options": {"media": "print"},
        }).encode()
        auth = base64.b64encode(f"{key}:".encode()).decode()
        req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json", "Authorization": f"Basic {auth}"})
        try:
            with urllib.request.urlopen(req, timeout=180) as resp:
                pdf = resp.read()
            open(f"{directory}/{name}.{target}.pdf", "wb").write(pdf)
            print(f"{name:18s} {target:10s} {len(pdf):8d} bytes")
        except urllib.error.HTTPError as e:
            print(f"{name:18s} {target:10s} HTTP {e.code}: {e.read()[:300]!r}")
