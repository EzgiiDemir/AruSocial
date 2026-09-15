"""Does the policy separate artistic nudity from photographic nudity?

The rule being tested: block photographs of real people kissing, in
swimwear, or nude — and allow drawn, painted and sculpted nudity, which
is coursework at an art and design university.

There is no lawful set of kissing or swimwear photographs in this
repository, so RECALL for those two categories is unmeasured and this
script does not pretend otherwise. What it can measure is the half that
decides whether the rule is usable here at all: the false-positive side,
against the university's own collection.

That collection is unusually good evidence. Rodin's *The Kiss* is two
nude figures kissing; *Eternal Spring* and *Eternal Idol* are nude
figures embracing. If the kissing and nudity prompts fire on those, the
policy refuses the sculptures the campus is built around. If they fire on
nothing at all, the prompts are inert and would not catch a photograph
either.

So the useful signal is BOTH numbers together, printed side by side:
how strongly each artwork reads as its risky category, and how strongly
it reads as art.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import httpx

SERVICE = "http://127.0.0.1:8801/v1/moderate/image"

# The artworks that make this policy hard, by filename fragment.
ARTWORKS = (
    "the_kiss", "11-the-kiss", "07-eternal-spring", "10-eternal-idol",
    "05-eve", "18-age-of-bronze", "03-falling-man", "02-rodin",
    "09-minotaur", "08-meditation", "06-daniele", "galatea",
)

WATCH = ("nudity", "kissing", "swimwear")


def score(path: Path, client: httpx.Client) -> dict[str, float] | None:
    try:
        with path.open("rb") as fh:
            r = client.post(SERVICE, files={"file": (path.name, fh, "image/png")},
                            timeout=120)
    except Exception:  # noqa: BLE001
        return None
    if r.status_code != 200:
        return None
    return {k: float(v) for k, v in r.json().get("scores", {}).items()}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", default="benchmark_manifest.json")
    ap.add_argument("--review", type=float, default=0.45)
    ap.add_argument("--block", type=float, default=0.60)
    args = ap.parse_args()

    manifest = json.loads(Path(args.manifest).read_text(encoding="utf-8"))

    def is_artwork(p: Path) -> bool:
        return any(frag in p.name.lower() for frag in ARTWORKS)

    with httpx.Client() as client:
        print("ARTWORKS — nude and kissing figures that MUST stay allowed")
        print(f"  {'file':<30}{'nudity':>9}{'kissing':>9}{'swimwear':>10}   verdict")
        print("  " + "-" * 68)
        worst_art = 0.0
        for rel in manifest["safe"]:
            p = Path(rel)
            if not is_artwork(p):
                continue
            s = score(p, client)
            if s is None:
                print(f"  {p.name[:28]:<30}unreadable")
                continue
            vals = {k: s.get(f"clip_{k}", 0.0) for k in WATCH}
            top = max(vals.values())
            worst_art = max(worst_art, top)
            verdict = ("BLOCKED" if top >= args.block
                       else "held" if top >= args.review else "allowed")
            mark = "   <-- REFUSES CAMPUS ART" if verdict != "allowed" else ""
            print(f"  {p.name[:28]:<30}{vals['nudity']:>9.4f}{vals['kissing']:>9.4f}"
                  f"{vals['swimwear']:>10.4f}   {verdict}{mark}")

        print("\nREAL NUDITY — must be caught")
        print(f"  {'file':<30}{'nudity':>9}{'kissing':>9}{'swimwear':>10}   verdict")
        print("  " + "-" * 68)
        seen: set[str] = set()
        best_real = 1.0
        for rel in manifest["unsafe"]:
            p = Path(rel)
            if p.name in seen:
                continue
            seen.add(p.name)
            s = score(p, client)
            if s is None:
                continue
            vals = {k: s.get(f"clip_{k}", 0.0) for k in WATCH}
            top = max(vals.values())
            verdict = ("BLOCKED" if top >= args.block
                       else "held" if top >= args.review else "PUBLISHED")
            # Gore has no reason to score on these three, so it is not
            # counted against the nudity floor.
            if s.get("clip_gore", 0.0) < 0.5:
                best_real = min(best_real, top)
            print(f"  {p.name[:28]:<30}{vals['nudity']:>9.4f}{vals['kissing']:>9.4f}"
                  f"{vals['swimwear']:>10.4f}   {verdict}")

        print("\nOTHER SAFE CAMPUS PHOTOS — highest of the three, per image")
        print("  " + "-" * 68)
        others = []
        for rel in manifest["safe"]:
            p = Path(rel)
            if is_artwork(p):
                continue
            s = score(p, client)
            if s is None:
                continue
            vals = {k: s.get(f"clip_{k}", 0.0) for k in WATCH}
            others.append((max(vals.values()), p.name, max(vals, key=vals.get)))
        others.sort(reverse=True)
        for v, name, cat in others[:6]:
            print(f"  {v:.4f}  {cat:<10} {name[:40]}")

        ceiling = max([worst_art] + [v for v, _, _ in others]) if others else worst_art
        print(f"\n  highest score across ALL safe content: {ceiling:.4f}")
        print(f"  lowest score on real nudity:            {best_real:.4f}")
        print(f"  thresholds in use: review {args.review}  block {args.block}")
        if ceiling >= best_real:
            print("\n  NO SEPARATION — safe content scores as high as unsafe.")
        else:
            print(f"\n  Gap: {best_real - ceiling:+.4f}")

    print(
        "\nNOT MEASURED: recall for `kissing` and `swimwear`. There are no\n"
        "lawful photographs of either in this repository. These categories\n"
        "are enabled on the false-positive evidence above and on the fact\n"
        "that the prompts demonstrably fire on the right subject matter —\n"
        "test them with real examples before trusting them."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
