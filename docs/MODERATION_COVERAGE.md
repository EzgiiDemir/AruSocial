# Moderation coverage

What the pipeline actually detects, and what it does not. Written to be
uncomfortable to read, because the categories in the "not covered"
section are the ones that will be assumed covered otherwise.

Last verified: 10 September 2026.

## Covered

| Category | Layer | Confidence |
|---|---|---|
| Hate, slurs | deterministic lexicon, TR/EN/RU | high for listed terms |
| Threats, violent text | deterministic + severity ladder | high |
| Harassment, profanity | deterministic | good with a named target |
| PII (phone, e-mail, IBAN, TC kimlik) | deterministic regex | high |
| Doxxing | PII + hostile context | medium |
| Drugs (sale) | deterministic | medium; coursework is exempted by context |
| Self-harm | deterministic, routed to support | medium, never punitive |
| Obfuscation (leetspeak, spacing, homoglyphs, zero-width) | normalization | high |
| **Adult / nudity — images** | NSFW classifier | **measured, see below** |
| **Adult / nudity — video** | same classifier, per keyframe | fail-closed pending FFmpeg |

### Measured image performance

Against `image-moderation-service/benchmark_manifest.json`:

```
safe    n=49   highest score 0.0663
unsafe  n=21   lowest  score 0.6715
thresholds     review 0.20   block 0.50
false positives 0        false negatives 0
```

The populations do not overlap. The safe set deliberately includes the
Rodin sculpture series (`03-falling-man`, `05-eve`, `07-eternal-spring`,
`11-the-kiss`) because ARUCAD is an art and design university where
sculpture and figure work are ordinary coursework; those score
0.003–0.006, so the model separates bronze from photography.

**Limit:** the unsafe set is 21 files but only 2 distinct images. Two
images cannot establish a recall figure. The thresholds are measured and
defensible; the accuracy is not statistically validated.

## Not covered

These are **not detected by any automatic layer**. They depend entirely
on user reports and the moderator queue.

| Category | Why |
|---|---|
| Graphic violence / gore | No usable model found — see below |
| Weapons | No usable model found |
| Hate symbols | No general model exists; needs a curated hash set |
| Self-harm imagery | Not reliably detectable; reports are primary |
| Drug imagery | Not covered by the NSFW model |
| Scam / phishing text | Known gap in the deterministic lexicon |
| Bullying in context | Requires history between two people, not a classifier |
| Child safety | Deliberately excluded — requires an approved hash-matching programme and a legal reporting path, not an improvised detector |

### Why there is no violence model

Searched the Hub for permissively licensed image classifiers covering
violence, gore and weapons. The results, and what happened:

- **`jaranohaal/vit-base-violence-detection`** (Apache-2.0, ~1.2k
  downloads, the leading result, with three near-identical forks) —
  **broken**. The checkpoint is in timm `blocks.*` layout, which
  `ViTForImageClassification` cannot map, so every encoder layer and the
  classifier head initialise randomly. Transformers warns: *"You should
  probably TRAIN this model on a down-stream task."*

  Benchmarked anyway. On our labelled set it scored **safe** content
  higher on the violent label (median 0.67) than the **unsafe** set
  (0.47), with 38 of 46 safe images above 0.50. Enabling it would have
  blocked most of the campus photo library while catching nothing.

- **Weapons** — the top result is `Kaludi/csgo-weapon-classification`,
  which classifies Counter-Strike weapon skins. Not applicable.

- **Hate symbols** — nothing.

Before enabling any replacement, run:

```
python probe_candidate.py <model-id> --manifest benchmark_manifest.json
```

A second model can only *add* false positives to a pipeline whose safe
set currently passes at zero, so the question to answer first is not
"does it catch the bad thing" but "what does it do to ordinary campus
photographs".

## Operational requirements

- The classifier must be running. When it is not, every image and video
  upload correctly fails closed with a 503 — which is indistinguishable,
  from inside the app, from content being rejected. Start it with
  `image-moderation-service/run.ps1`, and check
  `GET /api/v1/health` → `data.moderation.image`.
- **FFmpeg is not installed.** Video is therefore held for review rather
  than published. Installing it activates keyframe scanning.
- A moderator must drain the review queue. Held content waits
  indefinitely otherwise, which to a student is the same as broken.
