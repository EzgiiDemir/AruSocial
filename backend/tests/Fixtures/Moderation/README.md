# Moderation evaluation sets

## The rule that makes these worth anything

**Tuning against a set retires it.** Once the lexicon or a threshold has
been changed because of what a set reported, that set measures how well the
system memorised those examples — not how well it generalises. Its numbers
may still be quoted, labelled as *tuned*, but the headline recall and
precision figures must come from a set that was written before the change
and read once after it.

That is why there are several, and why each carries the date it was written
and what it was used for.

## The sets

| File | Written | Status |
|---|---|---|
| `eval_v4.json` | 14 Sep 2026, before the fixes | Drove the §4 work. **Tuned against — retired as a headline number.** |
| `eval_v4_holdout.json` | 14 Sep 2026, after the fixes | Fresh wording, read once. **This is the honest number.** |

**Be precise about what the second one is.** It was written *after* the
fixes, by the same author, with knowledge of what had just changed. That is
weaker than a pre-registered holdout: it uses different scenarios, different
vocabulary and different obfuscations rather than paraphrases of the tuning
items, but it is not independent. Read it as "fresh examples, same author".

A genuinely independent set — written by someone who has not seen the
lexicon, ideally from real reported content — is the next thing this needs,
and is listed in the pre-launch checklist.

## Shape

```json
{
  "harmful": [
    {"id": "tr-thr-01", "lang": "tr", "cat": "THR", "text": "…",
     "note": "why this is harmful"}
  ],
  "safe": [
    {"id": "tr-safe-01", "lang": "tr", "near": "THR", "text": "…",
     "note": "why this is safe despite looking like THR"}
  ]
}
```

`cat` is the category the item should be caught under. `near` on a safe item
names the category it is deliberately close to — those are the items that
actually matter, because anything can score zero false positives on text
that looks nothing like a violation.

## Categories

| Code | Covers |
|---|---|
| `PROF` | Profanity, its inflections, and obfuscated spellings |
| `HATE` | Hate speech and discrimination |
| `THR` | Threats and intimidation |
| `HAR` | Harassment and bullying |
| `SEX` | Sexual harassment and unwanted sexual content |
| `SCAM` | Fraud and phishing |
| `SELF` | Self-harm |
| `PRIV` | Sharing someone else's personal information |
| `SPAM` | Spam and mass unsolicited posting |

## Running them

```bash
cd backend && php artisan test --filter=ModerationBenchmarkTest
```

The harness prints recall, precision and false-positive rate per language
and per category. It asserts only floors that have actually been met, so a
regression fails the build while an improvement does not need the test
edited to pass.
