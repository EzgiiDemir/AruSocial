"""Scores a candidate model against the existing labelled set.

Run before wiring any new model into the service. A second classifier can
only ever *add* false positives to a pipeline whose safe set currently
passes at zero, so the question is not "does it detect the bad thing" but
"what does it do to ordinary campus photographs".

    python probe_candidate.py <model-id> [--label LABEL]

Prints the score distribution over the safe and unsafe sets and names the
safe files it scores highest, which is where the damage would be.
"""

import argparse
import json
import sys
from pathlib import Path

from PIL import Image


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("model")
    parser.add_argument("--manifest", default="benchmark_manifest.json")
    parser.add_argument("--worst", type=int, default=10)
    args = parser.parse_args()

    from transformers import pipeline

    print(f"loading {args.model} ...", file=sys.stderr)
    pipe = pipeline(task="image-classification", model=args.model)
    labels = list(pipe.model.config.id2label.values())
    print(f"labels: {labels}\n")

    manifest = json.loads(Path(args.manifest).read_text())

    for group in ("safe", "unsafe"):
        rows = []
        for path in manifest.get(group, []):
            p = Path(path)
            if not p.exists():
                continue
            try:
                image = Image.open(p).convert("RGB")
            except Exception:
                continue
            scores = {r["label"]: float(r["score"]) for r in pipe(image)}
            rows.append((str(p.name), scores))

        if not rows:
            continue

        print(f"--- {group.upper()}  n={len(rows)} ---")
        for label in labels:
            values = sorted((s.get(label, 0.0) for _, s in rows), reverse=True)
            top = values[0] if values else 0.0
            median = values[len(values) // 2] if values else 0.0
            over_half = sum(1 for v in values if v >= 0.5)
            print(f"  {label:<24} max={top:.4f}  median={median:.4f}"
                  f"  >=0.50: {over_half}/{len(values)}")
        print()

        if group == "safe":
            # The list that decides whether this model is usable: safe
            # photographs it is most confident about.
            for label in labels:
                ranked = sorted(rows, key=lambda r: r[1].get(label, 0.0), reverse=True)
                print(f"  highest SAFE for '{label}':")
                for name, scores in ranked[: args.worst]:
                    print(f"    {scores.get(label, 0.0):.4f}  {name}")
                print()

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
