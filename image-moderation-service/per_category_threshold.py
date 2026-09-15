"""Per-category threshold headroom, measured over every labelled set.

One threshold for every category is a convenience, not a finding. Some
categories separate cleanly and some barely clear zero, and a single
number set for the easiest of them leaves the others below the line.

For each category this prints the highest margin any SAFE text reaches
and the lowest any HARMFUL text of that category reaches. Where the safe
ceiling sits well under the harmful floor there is headroom to lower the
threshold; where they overlap, no threshold helps and the honest answer
is that the category needs better exemplars or a better model.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import httpx

SERVICE = "http://127.0.0.1:8801/v1/moderate/text"


def margins(client: httpx.Client, text: str) -> dict[str, float]:
    r = client.post(SERVICE, json={"text": text}, timeout=60)
    r.raise_for_status()
    return {k: float(v) for k, v in r.json()["margins"].items()}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--sets", nargs="*", default=[
        "text_suite_v2.json", "text_benchmark.json",
        "text_holdout.json", "text_holdout2.json",
    ])
    args = ap.parse_args()

    harmful_by_cat: dict[str, list[tuple[str, float]]] = {}
    safe_ceiling: dict[str, float] = {}
    safe_worst: dict[str, str] = {}
    safe_n = 0

    with httpx.Client() as client:
        for path in args.sets:
            p = Path(path)
            if not p.exists():
                continue
            data = json.loads(p.read_text(encoding="utf-8"))

            for row in data["harmful"]:
                cat = row.get("cat")
                if not cat or row.get("expect") == "support":
                    continue
                m = margins(client, row["text"])
                if cat in m:
                    harmful_by_cat.setdefault(cat, []).append((row["id"], m[cat]))

            for row in data["safe"]:
                safe_n += 1
                m = margins(client, row["text"])
                for cat, v in m.items():
                    if v > safe_ceiling.get(cat, -9.0):
                        safe_ceiling[cat] = v
                        safe_worst[cat] = row.get("id", "?")

    print(f"safe texts measured: {safe_n}\n")
    print(f"{'cat':<9}{'safe max':>10}{'harmful min':>13}{'headroom':>11}   worst safe")
    print("-" * 72)

    for cat in sorted(harmful_by_cat):
        rows = harmful_by_cat[cat]
        floor = min(v for _, v in rows)
        ceiling = safe_ceiling.get(cat, 0.0)
        gap = floor - ceiling
        note = "" if gap > 0 else "   <-- OVERLAP, no threshold works"
        print(f"{cat:<9}{ceiling:>10.4f}{floor:>13.4f}{gap:>11.4f}   "
              f"{safe_worst.get(cat, '-')[:18]}{note}")

    print(
        "\nA category with positive headroom can have its threshold lowered to\n"
        "just above its own safe ceiling. A category with overlap cannot be\n"
        "fixed by moving a number — it needs better exemplars or a better model."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
