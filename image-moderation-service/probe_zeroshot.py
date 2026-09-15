"""Zero-shot policy classification, probed before it is trusted.

Why this shape. Fixed-label toxicity models were tried first and failed
on evidence: `unitary/multilingual-toxic-xlm-roberta` exposes one "toxic"
label, scored an English threat at 0.0085 and a phishing message at
0.0014, while an ordinary life-drawing announcement scored 0.3722. No
threshold separates those, and the categories we are missing — threats,
sexual coercion, self-harm intent, scams — are precisely the ones it
cannot express.

Zero-shot NLI lets the *policy* be the label. Each category is written as
a hypothesis in the same language as the content, so "this text contains
a threat to hurt someone" is scored directly instead of being inferred
from a generic toxicity score.

The safe set is the real test. At an art and design university, "this
text is sexual" must not fire on a life-drawing class, and "this text
expresses suicidal intent" must not fire on a suicide-prevention seminar.
Those are in the manifest deliberately.
"""

from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

# Hypotheses are written per category. Turkish first because most campus
# content is Turkish; the NLI model is multilingual, so one hypothesis
# set is scored against content in any language.
HYPOTHESES: dict[str, str] = {
    "THR": "Bu metin birine zarar vermekle tehdit ediyor.",
    "HAR": "Bu metin belirli bir kişiyi hedef alarak aşağılıyor veya taciz ediyor.",
    "SEX": "Bu metin cinsel içerikli bir teklif veya cinsel taciz içeriyor.",
    "SELF": "Bu metni yazan kişi kendi canına kıymayı düşündüğünü söylüyor.",
    "SCAM": "Bu metin sahte bir bağlantıyla parasal veya kişisel bilgi istiyor.",
    "HATE": "Bu metin bir etnik gruba veya milliyete karşı nefret söylemi içeriyor.",
    "VIO": "Bu metin birine karşı fiziksel şiddet uygulamayı anlatıyor.",
}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "model_id",
        nargs="?",
        default="MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7",
    )
    ap.add_argument("--manifest", default="text_benchmark.json")
    args = ap.parse_args()

    data = json.loads(Path(args.manifest).read_text(encoding="utf-8"))
    harmful, safe = data["harmful"], data["safe"]

    from transformers import pipeline

    print(f"loading {args.model_id} ...", flush=True)
    try:
        pipe = pipeline("zero-shot-classification", model=args.model_id, device=-1)
    except Exception as exc:  # noqa: BLE001
        print(f"LOAD FAILED: {type(exc).__name__}: {exc}")
        return 1

    labels = list(HYPOTHESES)
    templates = [HYPOTHESES[k] for k in labels]

    def score(text: str) -> dict[str, float]:
        # multi_label so categories are independent: a message can be both
        # a threat and harassment, and forcing them to compete for one
        # softmax would suppress the second.
        out = pipe(
            text,
            candidate_labels=templates,
            hypothesis_template="{}",
            multi_label=True,
        )
        by_template = dict(zip(out["labels"], out["scores"]))
        return {k: float(by_template[HYPOTHESES[k]]) for k in labels}

    started = time.perf_counter()

    print("\nHARMFUL — expected category must score high")
    print(f"{'id':<22}{'exp':<6}{'exp score':>10}   top category")
    print("-" * 66)
    harmful_expected: list[float] = []
    for row in harmful:
        s = score(row["text"])
        exp = row["cat"]
        top = max(s, key=s.get)
        got = s.get(exp, 0.0)
        harmful_expected.append(got)
        mark = "" if got >= 0.5 else "   <-- LOW"
        print(f"{row['id']:<22}{exp:<6}{got:>10.4f}   {top} {s[top]:.4f}{mark}")

    print("\nSAFE — every category must score low")
    print(f"{'id':<22}{'max cat':<8}{'max score':>10}")
    print("-" * 46)
    safe_max: list[float] = []
    for row in safe:
        s = score(row["text"])
        top = max(s, key=s.get)
        safe_max.append(s[top])
        mark = "   <-- FALSE POSITIVE RISK" if s[top] >= 0.5 else ""
        print(f"{row['id']:<22}{top:<8}{s[top]:>10.4f}{mark}")

    elapsed = time.perf_counter() - started
    n = len(harmful) + len(safe)

    print("\n--- separation ---")
    print(f"harmful, expected-category scores: min {min(harmful_expected):.4f}  "
          f"median {sorted(harmful_expected)[len(harmful_expected)//2]:.4f}")
    print(f"safe, highest-any-category:        max {max(safe_max):.4f}  "
          f"median {sorted(safe_max)[len(safe_max)//2]:.4f}")
    print(f"\nA usable threshold must sit between {max(safe_max):.4f} and "
          f"{min(harmful_expected):.4f}. "
          f"{'THERE IS NO SUCH GAP.' if max(safe_max) >= min(harmful_expected) else 'Gap exists.'}")
    print(f"\n{n} texts in {elapsed:.1f}s  ({elapsed/n*1000:.0f} ms/text on CPU)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
