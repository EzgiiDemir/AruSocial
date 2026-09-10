"""Scores a labelled image set against the running classifier.

Reports the numbers that decide a threshold: false positives, false
negatives, precision, recall — and, more usefully than any single figure,
the score distribution per label so you can see *where* the two
populations actually separate.

Usage:
    python benchmark.py --safe DIR [--safe DIR] --unsafe DIR [--unsafe DIR]

Labels come from which directory a file is in. That is deliberate: a
benchmark whose labels live in the same script that reports the results
is a benchmark that will quietly be adjusted until it passes.
"""

import argparse
import json
import sys
from pathlib import Path

import httpx

SERVICE = "http://127.0.0.1:8801/v1/moderate/image"
SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".gif"}


def score(path: Path, client: httpx.Client) -> float | None:
    try:
        with path.open("rb") as fh:
            response = client.post(SERVICE, files={"file": (path.name, fh)}, timeout=60)
    except Exception as exc:  # noqa: BLE001
        print(f"  ! {path.name}: {type(exc).__name__}", file=sys.stderr)
        return None

    if response.status_code != 200:
        # A refused file is a validation result, not a score. Counting it
        # as either safe or unsafe would corrupt the rates below.
        print(f"  ! {path.name}: HTTP {response.status_code}", file=sys.stderr)
        return None

    return float(response.json()["scores"].get("nsfw", 0.0))


def collect(directories: list[str]) -> list[Path]:
    files: list[Path] = []
    for directory in directories:
        root = Path(directory)
        if not root.exists():
            print(f"  ! missing directory: {directory}", file=sys.stderr)
            continue
        files.extend(
            p for p in sorted(root.rglob("*")) if p.suffix.lower() in SUFFIXES
        )
    return files


def distribution(scores: list[float]) -> str:
    """Where the population actually sits, not just its mean.

    A mean hides the shape: two safe photos at 0.02 and one at 0.97
    average to something reassuring and still mean a student is blocked.
    """
    buckets = [0, 0, 0, 0, 0]
    for value in scores:
        if value < 0.10:
            buckets[0] += 1
        elif value < 0.35:
            buckets[1] += 1
        elif value < 0.60:
            buckets[2] += 1
        elif value < 0.85:
            buckets[3] += 1
        else:
            buckets[4] += 1
    labels = ["<0.10", "0.10-0.35", "0.35-0.60", "0.60-0.85", ">=0.85"]
    return "  ".join(f"{l}:{c}" for l, c in zip(labels, buckets))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--safe", action="append", default=[])
    parser.add_argument("--unsafe", action="append", default=[])
    # A manifest labels files where they already live. Copying adult
    # material into a "unsafe/" folder to satisfy a directory convention
    # creates a second copy of it on disk for no benefit.
    parser.add_argument("--manifest", default="",
                        help="JSON: {'safe': [paths], 'unsafe': [paths]}")
    parser.add_argument("--review", type=float, default=0.35)
    parser.add_argument("--block", type=float, default=0.85)
    parser.add_argument("--json", default="")
    parser.add_argument("--worst", type=int, default=8,
                        help="how many worst offenders to name per label")
    args = parser.parse_args()

    labelled: dict[str, list[Path]] = {"safe": [], "unsafe": []}
    if args.manifest:
        manifest = json.loads(Path(args.manifest).read_text())
        for label in ("safe", "unsafe"):
            labelled[label] = [Path(p) for p in manifest.get(label, [])]
    labelled["safe"] += collect(args.safe)
    labelled["unsafe"] += collect(args.unsafe)

    with httpx.Client() as client:
        results: dict[str, list[tuple[str, float]]] = {}
        for label, paths in labelled.items():
            rows: list[tuple[str, float]] = []
            for path in paths:
                value = score(path, client)
                if value is not None:
                    rows.append((str(path), value))
            results[label] = rows

    safe = [v for _, v in results["safe"]]
    unsafe = [v for _, v in results["unsafe"]]

    if not safe and not unsafe:
        print("No images scored. Is the service running on :8801?")
        return 1

    # Three outcomes, not two. Counting everything below the block line as
    # "published" was wrong and flattering in the wrong direction: content
    # between the thresholds is held for review, which is a safe outcome,
    # while content below the review line really is published unchecked.
    # Conflating them hides the only failure that actually exposes anyone.
    fp = sum(1 for v in safe if v >= args.block)
    tn = len(safe) - fp
    tp = sum(1 for v in unsafe if v >= args.block)
    held_unsafe = sum(1 for v in unsafe if args.review <= v < args.block)
    fn = sum(1 for v in unsafe if v < args.review)

    held_safe = sum(1 for v in safe if args.review <= v < args.block)

    precision = tp / (tp + fp) if (tp + fp) else float("nan")
    recall = tp / (tp + fn) if (tp + fn) else float("nan")
    fpr = fp / len(safe) if safe else float("nan")
    fnr = fn / len(unsafe) if unsafe else float("nan")

    print(f"\nthresholds: review={args.review}  block={args.block}\n")
    print(f"SAFE    n={len(safe):<5} {distribution(safe)}")
    print(f"UNSAFE  n={len(unsafe):<5} {distribution(unsafe)}\n")
    print(f"  true negatives   {tn}")
    print(f"  false positives  {fp}   (safe content blocked)")
    print(f"  true positives   {tp}   (unsafe blocked)")
    print(f"  unsafe held      {held_unsafe}   (queued for a human — safe outcome)")
    print(f"  false negatives  {fn}   (unsafe PUBLISHED — the only exposing failure)")
    print(f"  safe held for review {held_safe}"
          f"  ({held_safe / len(safe) * 100:.1f}% of safe)" if safe else "")
    print(f"\n  precision {precision:.3f}   recall {recall:.3f}")
    print(f"  false-positive rate {fpr:.3f}   false-negative rate {fnr:.3f}\n")

    for label in ("safe", "unsafe"):
        rows = results[label]
        if not rows:
            continue
        # Safe files scoring high and unsafe files scoring low are the two
        # lists worth reading; an aggregate never names the photo that
        # will get a student blocked.
        rows.sort(key=lambda r: r[1], reverse=(label == "safe"))
        heading = "highest-scoring SAFE" if label == "safe" else "lowest-scoring UNSAFE"
        print(f"{heading}:")
        for name, value in rows[: args.worst]:
            print(f"  {value:.4f}  {Path(name).name}")
        print()

    if args.json:
        Path(args.json).write_text(json.dumps(results, indent=2))
        print(f"wrote {args.json}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
