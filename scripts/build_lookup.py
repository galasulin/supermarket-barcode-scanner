#!/usr/bin/env python3
"""
Build a slim barcode -> name lookup for the scanner front-end.

Input : a scraped product catalog JSON (list of objects with at least
        "barcode" and "name" fields).
Output: ramilevy-lookup.json  ({ "<barcode>": "<name>", ... })

Only entries with a real numeric barcode (>= 8 digits) are kept, so the
file stays small (a few hundred KB) and loads instantly on mobile.

Usage:
    python scripts/build_lookup.py path/to/products.json app/ramilevy-lookup.json
"""
import json
import sys


def build(src_path: str, out_path: str) -> None:
    data = json.load(open(src_path, encoding="utf-8"))
    slim = {}
    for item in data:
        bc = str(item.get("barcode") or "").strip()
        name = (item.get("name") or "").strip()
        if name and len(bc) >= 8 and bc.isdigit():
            slim[bc] = name
    json.dump(
        slim,
        open(out_path, "w", encoding="utf-8"),
        ensure_ascii=False,
        separators=(",", ":"),
    )
    print(f"{len(slim)} entries -> {out_path}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        print(__doc__)
        sys.exit(1)
    build(sys.argv[1], sys.argv[2])
