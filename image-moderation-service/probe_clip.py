"""CLIP zero-shot as a multi-category visual signal.

The NSFW classifier in production detects adult content and nothing else.
Gore, weapons, hate symbols and drug imagery are not weakly detected by
it — they are not detected at all, and no threshold changes that.

Two purpose-built candidates were already rejected on evidence
(`jaranohaal/vit-base-violence-detection` loads with randomly initialised
weights and scored safe content higher than unsafe; the top weapons
result classifies Counter-Strike skins). CLIP is the opposite bet: a
very widely used, permissively licensed model whose weights certainly
load, scored against prompts we write ourselves — so one model can carry
every category, and the category list is policy rather than a checkpoint.

WHAT THIS MEASURES. False positives, rigorously, against the real campus
library. It cannot measure recall, because this repository holds no
lawful gore or weapon test images and none will be sourced casually.
That asymmetry is deliberate and matches the rule already established
for image moderation: a second model can only *add* false positives to a
pipeline whose safe set currently passes at zero, so the first question
is never "does it catch the bad thing" but "what does it do to ordinary
campus photographs".

Prompts are contrastive: each risky category competes against several
benign descriptions drawn from what this university actually
photographs. Without the benign side every image scores high on
something, which is the failure mode that killed the zero-shot text
attempt.
"""

from __future__ import annotations

import argparse
import json
import statistics
from pathlib import Path

# Imported from the service, never copied.
#
# This file used to hold its own duplicates of both lists, and they had
# drifted: the service had grown eleven artistic-media prompts and the
# whole everyday-clothing set that the probe knew nothing about. So the
# tool used to calibrate thresholds was measuring a classifier that does
# not exist, and every number it printed described a copy.
#
# Two prompt lists that must agree are one prompt list.
from app.clip_classifier import BENIGN_PROMPTS, RISK_PROMPTS  # noqa: E402


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="openai/clip-vit-base-patch32")
    ap.add_argument("--manifest", default="benchmark_manifest.json")
    ap.add_argument("--worst", type=int, default=10)
    args = ap.parse_args()

    from PIL import Image
    import torch
    from transformers import CLIPModel, CLIPProcessor

    print(f"loading {args.model} ...", flush=True)
    try:
        model = CLIPModel.from_pretrained(args.model)
        processor = CLIPProcessor.from_pretrained(args.model)
    except Exception as exc:  # noqa: BLE001
        print(f"LOAD FAILED: {type(exc).__name__}: {exc}")
        return 1
    model.eval()

    risk_labels = list(RISK_PROMPTS)
    prompts = [RISK_PROMPTS[k] for k in risk_labels] + BENIGN_PROMPTS

    manifest = json.loads(Path(args.manifest).read_text(encoding="utf-8"))

    def score(path: Path) -> dict[str, float] | None:
        try:
            image = Image.open(path).convert("RGB")
        except Exception:  # noqa: BLE001
            return None
        inputs = processor(text=prompts, images=image, return_tensors="pt", padding=True)
        with torch.no_grad():
            out = model(**inputs)
        probs = out.logits_per_image.softmax(dim=1)[0].tolist()
        return {k: float(probs[i]) for i, k in enumerate(risk_labels)}

    results: dict[str, list[tuple[str, dict[str, float]]]] = {"safe": [], "unsafe": []}
    for label in ("safe", "unsafe"):
        for rel in manifest.get(label, []):
            path = Path(rel)
            s = score(path)
            if s is None:
                print(f"  ! unreadable: {path.name}")
                continue
            results[label].append((path.name, s))

    print(f"\nscored: safe n={len(results['safe'])}  unsafe(nudity) n={len(results['unsafe'])}")

    print("\n--- per-category scores on the SAFE campus set ---")
    print(f"{'category':<14}{'median':>9}{'max':>9}   worst offender")
    print("-" * 62)
    safe_max: dict[str, float] = {}
    for cat in risk_labels:
        vals = [s[cat] for _, s in results["safe"]]
        if not vals:
            continue
        worst_name, worst = max(results["safe"], key=lambda r: r[1][cat])
        safe_max[cat] = worst[cat]
        print(f"{cat:<14}{statistics.median(vals):>9.4f}{max(vals):>9.4f}   {worst_name[:34]}")

    print("\n--- highest risk score of any category, per safe image ---")
    ranked = sorted(
        results["safe"], key=lambda r: max(r[1].values()), reverse=True
    )[: args.worst]
    for name, s in ranked:
        top = max(s, key=s.get)
        print(f"  {max(s.values()):.4f}  {top:<12} {name[:44]}")

    print("\n--- what a REVIEW threshold would cost ---")
    for t in (0.10, 0.20, 0.30, 0.50):
        held = sum(1 for _, s in results["safe"] if max(s.values()) >= t)
        pct = held / max(1, len(results["safe"])) * 100
        print(f"  threshold {t:.2f} -> {held:>2}/{len(results['safe'])} safe campus photos held ({pct:.0f}%)")

    # The one category we CAN measure recall for, because the manifest
    # holds labelled examples of it — including the two the production
    # NSFW model missed.
    if results["unsafe"]:
        print("\n--- nudity recall on the labelled unsafe set ---")
        nud = [(n, s.get("nudity", 0.0)) for n, s in results["unsafe"]]
        nud.sort(key=lambda r: r[1])
        worst_safe = safe_max.get("nudity", 0.0)
        print(f"  safe set's highest nudity score: {worst_safe:.4f}")
        for name, v in nud[:6]:
            gap = "  <-- BELOW the safe max, unseparable" if v <= worst_safe else ""
            print(f"  {v:.4f}  {name[:44]}{gap}")
        below = sum(1 for _, v in nud if v <= worst_safe)
        print(f"  {below}/{len(nud)} unsafe images score at or under the safe maximum.")

    print(
        "\nRecall for gore/weapons/hate symbols/drugs is NOT measured here: there are\n"
        "no lawful test images for them in this repository. Any of those categories\n"
        "enabled on this evidence alone must HOLD for review, never auto-block."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
