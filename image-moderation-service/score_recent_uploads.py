"""Score recently uploaded media against every visual category.

Written for the case where a student reports that an ordinary photo was
refused: it takes the actual files from storage rather than a
reconstruction, and prints every category so the one that fired is
visible instead of guessed at.
"""

from __future__ import annotations

import argparse
import time
from pathlib import Path

import httpx

SERVICE = "http://127.0.0.1:8801/v1/moderate/image"
CATEGORIES = ["nsfw", "clip_nudity", "clip_kissing", "clip_swimwear",
              "clip_gore", "clip_weapon", "clip_hate_symbol",
              "clip_drugs", "clip_self_harm"]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="../backend/storage/app")
    ap.add_argument("--hours", type=float, default=6.0)
    ap.add_argument("--min-bytes", type=int, default=20480)
    args = ap.parse_args()

    cutoff = time.time() - args.hours * 3600
    files = sorted(
        (p for p in Path(args.root).rglob("*")
         if p.is_file()
         and p.suffix.lower() in {".png", ".jpg", ".jpeg", ".webp"}
         and p.stat().st_size >= args.min_bytes
         and p.stat().st_mtime >= cutoff),
        key=lambda p: p.stat().st_mtime,
    )

    print(f"{len(files)} uploads in the last {args.hours:g}h\n")
    header = f"{'file':<22}" + "".join(f"{c.replace('clip_', ''):>9}" for c in CATEGORIES)
    print(header)
    print("-" * len(header))

    with httpx.Client() as client:
        for p in files:
            try:
                with p.open("rb") as fh:
                    r = client.post(SERVICE, files={"file": (p.name, fh, "image/png")},
                                    timeout=120)
            except Exception as exc:  # noqa: BLE001
                print(f"{p.name[:20]:<22}request failed: {type(exc).__name__}")
                continue
            if r.status_code != 200:
                print(f"{p.name[:20]:<22}HTTP {r.status_code}")
                continue
            scores = r.json().get("scores", {})
            row = "".join(f"{scores.get(c, 0.0):>9.3f}" for c in CATEGORIES)
            print(f"{p.name[:20]:<22}{row}")

    print("\nThresholds in Laravel: nsfw 0.20/0.50, clip_nudity / clip_kissing /"
          "\nclip_swimwear 0.35/0.45, clip_gore 0.45/0.60. Anything at or above"
          "\nthe second number is refused; between the two it is held.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
