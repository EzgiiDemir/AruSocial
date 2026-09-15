"""Benchmark a candidate text-moderation model before shipping it.

The counterpart to probe_candidate.py for images, and it exists for the
same reason: the obvious model on the Hub was, last time, broken in the
dangerous direction — it scored safe content higher than unsafe and would
have blocked most of the campus library. A model card is not evidence.

Run:
    python probe_text_candidate.py <model-id> [--revision REV]

Prints per-label scores for the harmful and safe populations, and the
number that actually decides whether a model is usable here: how many
ordinary campus posts it would flag. At an art and design university the
safe set deliberately includes life drawing, sculpture, war photography,
horror film studies and suicide-prevention outreach.
"""

from __future__ import annotations

import argparse
import json
import statistics
import sys
from pathlib import Path


def load_set(path: Path) -> tuple[list[dict], list[dict]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    return data["harmful"], data["safe"]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("model_id")
    ap.add_argument("--revision", default=None)
    ap.add_argument("--manifest", default="text_benchmark.json")
    ap.add_argument("--max-len", type=int, default=512)
    args = ap.parse_args()

    harmful, safe = load_set(Path(args.manifest))

    try:
        from transformers import pipeline
    except Exception as exc:  # noqa: BLE001
        print(f"transformers unavailable: {exc}")
        return 2

    print(f"loading {args.model_id} ...", flush=True)
    try:
        pipe = pipeline(
            task="text-classification",
            model=args.model_id,
            revision=args.revision,
            top_k=None,
            truncation=True,
            max_length=args.max_len,
            device=-1,
        )
    except Exception as exc:  # noqa: BLE001
        print(f"LOAD FAILED: {type(exc).__name__}: {exc}")
        return 1

    # A checkpoint whose head is randomly initialised loads fine and then
    # returns noise. That is exactly how the violence model wasted an
    # afternoon, so the warning it prints is worth repeating loudly.
    print("  loaded. Check stderr above for 'newly initialized' warnings.\n")

    def score_all(rows: list[dict]) -> dict[str, list[float]]:
        per_label: dict[str, list[float]] = {}
        for row in rows:
            out = pipe(row["text"])[0]
            for entry in out:
                per_label.setdefault(str(entry["label"]).lower(), []).append(
                    float(entry["score"])
                )
        return per_label

    h_scores = score_all(harmful)
    s_scores = score_all(safe)

    labels = sorted(set(h_scores) | set(s_scores))
    print(f"{'label':<24}{'harmful med':>13}{'safe med':>11}{'safe max':>11}   separation")
    print("-" * 74)
    for label in labels:
        h = h_scores.get(label, [0.0])
        s = s_scores.get(label, [0.0])
        h_med, s_med, s_max = statistics.median(h), statistics.median(s), max(s)
        # Positive means the harmful population sits above the safe one on
        # this label. Negative means the model is inverted for our data,
        # which is the failure that matters most and is easy to miss.
        sep = h_med - s_med
        flag = "  <-- INVERTED" if sep < 0 else ""
        print(f"{label:<24}{h_med:>13.4f}{s_med:>11.4f}{s_max:>11.4f}{sep:>12.4f}{flag}")

    print("\nper-case detail (harmful):")
    for row in harmful:
        out = pipe(row["text"])[0]
        top = max(out, key=lambda e: e["score"])
        print(f"  {row['id']:<22}{row['cat']:<6}{str(top['label']).lower():<18}{top['score']:.4f}")

    print("\nper-case detail (safe) — anything scoring high here is a false positive:")
    for row in safe:
        out = pipe(row["text"])[0]
        top = max(out, key=lambda e: e["score"])
        print(f"  {row['id']:<22}{'':<6}{str(top['label']).lower():<18}{top['score']:.4f}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
