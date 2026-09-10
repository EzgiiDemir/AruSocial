"""Builds the labelled benchmark manifest from what is on disk.

Labels are assigned by provenance and by inspection, not by the model's
own score — a benchmark labelled from the model under test measures
nothing.

  safe    the app's own shipped imagery (campus, buildings, the Rodin
          sculpture series) plus uploads confirmed by eye as ordinary
  unsafe  uploads confirmed by eye as photographic nudity

The Rodin series matters here. ARUCAD is an art and design university, so
sculpture, life drawing and figure photography are ordinary coursework.
A classifier that cannot tell a bronze nude from a photographic one is
unusable at this specific institution, and that is exactly what these
files test.
"""

import json
from pathlib import Path

ASSETS = Path("../frontend/assets/images")
ORPHANED = Path("../backend/storage/app/private/orphaned/media")

# Confirmed by inspection as photographic nudity. Two duplicate groups —
# eight copies of one image, thirteen of another.
UNSAFE = [
    "dFVQbj4HkyyRUOFTrl1Iz5UfdaLENOKM7WcPB7Xt.png",
    "Gm1J0xKV4vhUMkkyfTNZ0Sl0Fj6ODyvq2WDPAKst.png",
    "hErfKfgyh2ZIyYsHXCEU4kXbPbqJ1doxa3EpreND.png",
    "MRu4fQ5cCKycp6TWGlxC754J84epPeSwX9IfOyYI.png",
    "PE4OHtPTz8MWO0Dh2sKiHqPuUWrmbKUkZ5rnlKA1.png",
    "t19PSXbyrkwwdrrIYYmMGqpNhiWN6h1wqFBVWx9r.png",
    "wrW2Bl2RAlXNqXqlpaWq9rH72cr896n8DrS7mYkx.png",
    "Y99WGkXGwRv9MJz64u2I7JZ0gseYMhYyh8PKR6mf.png",
    "6J9tTYpG81Xq1haeglbApHtu2g6TO5aZ08aKWwnC.png",
    "xohHKWxcC4ENr22vnXTry9oYC9FuA4oxpkBuee1y.png",
    "StICMbWvRojXkCyImR52r4DFmSKzNBjVEW3YQSmo.png",
    "0pahMCfH3oYC5AMLkikQUzhESfphuA1ZXzlu6dsR.png",
    "3dDEdKF6CT5452mVcy8BsZA4qQEAzGsyF4p72TI4.png",
    "3wxQBWmqW1FHTHjIZRCQTcpelwAwqnzdEEPrYMGJ.png",
    "aUSSuwCUXH6SugS7SiXkuerUPmTU5F40H9JS0j28.png",
    "AuXxxIAM4EA1nfNixFszbLK9oyF2QnOoxMCC1RNP.png",
    "d25Dm5pcZMUpcx4vroztuckL3wdi4EOKakTWz4WQ.png",
    "vAOyTaKUUquLzA8l3XGA9hcYi0pciWvb5kNomPBy.png",
    "WtdVUIp15Xs61tjYtgUQB1EnCrxPmowb6uKB0QLq.png",
    "zOpXy4B1xfy6k6C2IFsHN09RAcZaqP3hkAuA7eL1.png",
    "zWlDYko7eRZzS0qWrxgrTaETaXdgP1bzjkl7yhwn.png",
]

SUFFIXES = {".png", ".jpg", ".jpeg", ".webp"}


def main() -> None:
    unsafe = [str(ORPHANED / name) for name in UNSAFE if (ORPHANED / name).exists()]

    safe = [str(p) for p in sorted(ASSETS.rglob("*")) if p.suffix.lower() in SUFFIXES]
    # Everything else in the uploads folder that decodes: ordinary photos.
    safe += [
        str(p)
        for p in sorted(ORPHANED.rglob("*"))
        if p.suffix.lower() in SUFFIXES and p.name not in UNSAFE
    ]

    Path("benchmark_manifest.json").write_text(
        json.dumps({"safe": safe, "unsafe": unsafe}, indent=2)
    )
    print(f"safe={len(safe)}  unsafe={len(unsafe)}")


if __name__ == "__main__":
    main()
