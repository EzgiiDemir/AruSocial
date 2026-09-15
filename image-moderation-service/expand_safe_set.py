"""Find real photographs not yet in the benchmark, and screen them.

The safe set is the half that decides whether this pipeline is usable:
every false positive is a student whose ordinary coursework was refused.
It was 46 images, which is too few to trust.

Screening, not labelling. Files are scored by the deployed classifier and
sorted, so anything with a non-trivial score is surfaced for a human to
look at before it is called safe. Adding files to the safe set purely
because the classifier likes them would make the benchmark agree with the
model by construction and measure nothing.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

import httpx

SERVICE = "http://127.0.0.1:8801/v1/moderate/image"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    h.update(path.read_bytes())
    return h.hexdigest()


def score(path: Path, client: httpx.Client) -> float | None:
    try:
        with path.open("rb") as fh:
            r = client.post(SERVICE, files={"file": (path.name, fh, "image/png")}, timeout=60)
        if r.status_code != 200:
            return None
        return float(r.json().get("scores", {}).get("nsfw", 0.0))
    except Exception:  # noqa: BLE001
        return None


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", default="benchmark_manifest.json")
    ap.add_argument("--min-bytes", type=int, default=20480)
    ap.add_argument("--roots", nargs="*", default=[
        "../backend/storage/app/private/media",
        "../backend/storage/app/private/orphaned/media",
        "../frontend/assets",
    ])
    args = ap.parse_args()

    manifest = json.loads(Path(args.manifest).read_text(encoding="utf-8"))

    known: dict[str, str] = {}
    for label in ("safe", "unsafe"):
        for rel in manifest.get(label, []):
            p = Path(rel)
            if p.is_file():
                known[sha256(p)] = label

    print(f"manifest: {len(manifest['safe'])} safe, {len(manifest['unsafe'])} unsafe "
          f"({len(known)} readable, {len(set(known))} distinct hashes)")

    candidates: dict[str, Path] = {}
    for root in args.roots:
        base = Path(root)
        if not base.exists():
            continue
        for p in base.rglob("*"):
            if not p.is_file() or p.suffix.lower() not in {".png", ".jpg", ".jpeg", ".webp"}:
                continue
            if p.stat().st_size < args.min_bytes:
                continue
            digest = sha256(p)
            if digest in known or digest in candidates:
                continue
            candidates[digest] = p

    print(f"new distinct photographs found: {len(candidates)}\n")
    if not candidates:
        return 0

    with httpx.Client() as client:
        rows = []
        for digest, path in candidates.items():
            s = score(path, client)
            rows.append((s if s is not None else -1.0, path, digest))

    rows.sort(reverse=True)

    print(f"{'nsfw':>8}  path")
    print("-" * 78)
    for s, path, _ in rows:
        flag = ""
        if s < 0:
            flag = "   <-- UNREADABLE, exclude"
        elif s >= 0.20:
            flag = "   <-- REVIEW BY EYE before calling safe"
        elif s >= 0.05:
            flag = "   <-- look at this one"
        print(f"{s:>8.4f}  {str(path)[-62:]}{flag}")

    clean = [p for s, p, _ in rows if 0 <= s < 0.05]
    needs_eye = [p for s, p, _ in rows if s >= 0.05]

    print(f"\n{len(clean)} scored below 0.05 and are candidates for the safe set.")
    print(f"{len(needs_eye)} need a human to look before being labelled.")

    out = Path("expand_candidates.json")
    out.write_text(json.dumps({
        "clean": [str(p) for p in clean],
        "needs_review": [str(p) for p in needs_eye],
    }, indent=2), encoding="utf-8")
    print(f"\nwrote {out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
