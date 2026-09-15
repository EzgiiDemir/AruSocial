"""Find the best block threshold using every labelled text at once.

The exemplar sets were tuned; the threshold has not been. It was set to
0.15 from one set of 39 texts, and there are now 89 across three files.

What this answers: is the recall shortfall a threshold that is too high,
or content the model genuinely cannot separate? If the misses cluster
just under the line while the safe texts sit far below it, there is free
recall available. If they are mixed in among the safe texts, no threshold
helps and the honest answer is that this layer catches what it catches.

Prints the full sweep rather than a single recommendation, because the
choice between recall and false positives is policy — and at a university
a false positive refuses a student's coursework.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import httpx

SERVICE = "http://127.0.0.1:8801/v1/moderate/text"

# SELF routes to support rather than a refusal, so it is excluded from a
# *block* threshold sweep — mixing the two would tune one number against
# two different decisions.
BLOCK_CATEGORIES = {"THR", "HAR", "SEX", "SCAM", "HATE", "VIO"}


def margins(client: httpx.Client, text: str) -> dict[str, float]:
    r = client.post(SERVICE, json={"text": text}, timeout=60)
    r.raise_for_status()
    return {k: float(v) for k, v in r.json()["margins"].items()}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--sets", nargs="*", default=[
        "text_benchmark.json", "text_holdout.json", "text_holdout2.json",
    ])
    args = ap.parse_args()

    harmful: list[tuple[str, float]] = []
    safe: list[tuple[str, float]] = []

    with httpx.Client() as client:
        for path in args.sets:
            data = json.loads(Path(path).read_text(encoding="utf-8"))
            for row in data["harmful"]:
                if row.get("expect") == "support":
                    continue
                m = margins(client, row["text"])
                # Only the categories that can block, and only the best
                # of them: one category clearing the line is enough.
                best = max(m[c] for c in BLOCK_CATEGORIES if c in m)
                harmful.append((f"{path[:-5]}/{row['id']}", best))
            for row in data["safe"]:
                m = margins(client, row["text"])
                best = max(m[c] for c in BLOCK_CATEGORIES if c in m)
                safe.append((f"{path[:-5]}/{row['id']}", best))

    print(f"harmful (blockable): {len(harmful)}    safe: {len(safe)}\n")

    safe_sorted = sorted(safe, key=lambda r: -r[1])
    print("highest-scoring SAFE texts — these set the ceiling:")
    for name, v in safe_sorted[:8]:
        print(f"  {v:+.4f}  {name}")

    harmful_sorted = sorted(harmful, key=lambda r: r[1])
    print("\nlowest-scoring HARMFUL texts — these set the floor:")
    for name, v in harmful_sorted[:10]:
        print(f"  {v:+.4f}  {name}")

    print("\nthreshold sweep:")
    print(f"  {'thr':>7}{'recall':>12}{'false pos':>12}   note")
    best_safe = safe_sorted[0][1]
    for t in [0.02, 0.04, 0.06, 0.08, 0.10, 0.12, 0.15, 0.20, 0.30]:
        tp = sum(1 for _, v in harmful if v >= t)
        fp = sum(1 for _, v in safe if v >= t)
        note = ""
        if fp == 0 and t <= best_safe:
            note = "  <- still clear of every safe text"
        print(f"  {t:>7.2f}{tp:>7}/{len(harmful):<4}{fp:>7}/{len(safe):<4}{note}")

    # The most useful single number: the lowest threshold that still
    # refuses nothing safe.
    candidates = [t / 100 for t in range(1, 51)]
    clean = [t for t in candidates if sum(1 for _, v in safe if v >= t) == 0]
    if clean:
        lowest = min(clean)
        tp = sum(1 for _, v in harmful if v >= lowest)
        print(f"\nLowest threshold with ZERO false positives: {lowest:.2f}"
              f"  ->  recall {tp}/{len(harmful)}")
        print(f"Highest safe margin anywhere: {best_safe:+.4f}")
    else:
        print("\nNo threshold avoids false positives — the populations overlap.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
