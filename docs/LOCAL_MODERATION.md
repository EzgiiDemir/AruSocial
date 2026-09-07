# Local content moderation

ARUCAD does not send student text, images or videos to a moderation vendor.
Text is evaluated by the server's version-controlled Turkish, English and
Russian policy rules; every media upload is non-public until the local
classifier and/or an authorised reviewer has made a decision.

## Categories and actions

| Category | Detection | Immediate result | Account result |
|---|---|---|---|
| Profanity, harassment, hate, credible threat | Local text rules with Turkish normalisation | Request rejected | One strike |
| Sexual exploitation, graphic violence | Local text rules; local media model when confidence is high | Request/upload rejected | One strike |
| Nudity, hate symbols, graphic violence in image/video | ARUCAD-operated local model | Upload rejected only at configured high confidence | One strike |
| Ambiguous or model-unavailable media | Human reviewer queue | Never public | No automatic strike |
| Reviewer-confirmed prohibited media | Authorised moderation decision | File removed | One strike |

Three strikes trigger the existing account-wide ban middleware. A ban blocks
every protected API route, including portal routes. This avoids a silent
client-only rule that a modified app could bypass.

## Local model contract

`LOCAL_MODERATION_BINARY` must point to an executable stored and operated by
ARUCAD on the Laravel host (or a private mounted volume). Laravel calls it
without a shell:

```text
arucad-moderator --input /private/path/upload.tmp --mime image/jpeg
```

Its stdout must be one JSON object:

```json
{
  "scores": {
    "nudity": 0.02,
    "sexual_exploitation": 0.00,
    "graphic_violence": 0.97,
    "hate_symbol": 0.01
  }
}
```

Scores are limited to 0–1. Categories at or above
`LOCAL_MODERATION_BLOCK_THRESHOLD` (default `0.92`) are rejected and struck.
Invalid output, runner timeout, a missing executable, or an unavailable model
does **not** publish the file: it remains in the protected moderation queue.

## Model ownership requirement

This repository cannot truthfully manufacture a reliable visual model from PHP
code. ARUCAD must supply a legally sourced, versioned model artifact and a
validation set for Turkish campus content. Before enabling automatic strikes,
record its version, threshold, precision/recall by category, false-positive
review path, retention policy and appeal owner. The adapter intentionally
contains no network URL, API key, or remote fallback.
