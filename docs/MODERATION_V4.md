# Text moderation — §4 measurement, 14 September 2026

Recall, precision and false-positive rate, per language and per category,
for Turkish, English and Russian.

## The headline

| Set | Layers | Recall | Precision | Blocked FP |
|---|---|---|---|---|
| `eval_v4` (**tuned against**) | rules only | 95.0% | 100% | 0 / 55 |
| `eval_v4` (**tuned against**) | rules + semantic | 95.0% | 100% | 0 / 55 |
| `eval_v4_holdout` (**fresh**) | rules only | 31.0% | 100% | 0 / 31 |
| `eval_v4_holdout` (**fresh**) | rules + semantic | **55.2%** | **100%** | **0 / 31** |

**Quote 55.2%.** The 95% is the system scoring itself on the examples that
were used to fix it, which is not a measurement of anything except how well
a phrase list memorises phrases.

The gap between 95% and 55% is the single most useful number here. It is
the cost of a rule-based layer meeting wording nobody thought to list, and
it is why the semantic classifier is not optional: it takes the fresh-set
recall from 31% to 55% on its own.

## Per language, fresh set, full pipeline

| Language | Recall | Precision | Blocked FP |
|---|---|---|---|
| English | 60.0% | 100% | 0 |
| Russian | 55.6% | 100% | 0 |
| Turkish | 50.0% | 100% | 0 |

## Per category, fresh set, full pipeline

| Category | Recall | Note |
|---|---|---|
| PROF — profanity, inflections, obfuscation | 100% | The mature part. Root matching generalises; phrase lists do not. |
| HAR — harassment, bullying | 66.7% | |
| PRIV — sharing personal information | 66.7% | |
| SELF — self-harm | 66.7% | Misses are indirect ideation with no clinical word. |
| SPAM | 66.7% | Structural signals generalise well. |
| HATE — hate speech | 33.3% | |
| SCAM — fraud and phishing | 33.3% | |
| SEX — sexual harassment | **0%** | See below. |

## What was changed, and why it is not threshold-tuning

No threshold was lowered. Every gain came from one of three things:

1. **Phrase families that did not exist.** Conditional threats ("do that
   again and you'll see") had no rule at all — every listed threat named a
   violent act, so the commonest real shape published untouched. Same for
   organised exclusion ("everyone ignore her"), which is the commonest form
   of campus bullying and contains no word a list would catch.

2. **Structural detection for spam and fraud** (`StructuralSignals`). Spam
   has no vocabulary — "жми сюда" repeated three times is spam because it
   repeats, and next week it is a different product at a different price.
   What does not move is the shape: repetition, shouting, a throwaway
   domain, a small number promised as a large one. Two independent signals
   are required, so club recruitment and second-hand sales stay published.
   SPAM went 17% → 100% on the tuning set.

3. **Two bugs that made whole languages score zero.**
   - Normalisation folds Cyrillic to Latin lookalikes: `продаю` becomes
     `пpoдaю`. Needles written in real Russian matched nothing. Every
     Russian structural signal was silently dead until the needles were
     folded through the same canonicaliser.
   - The de-obfuscation pass maps `1→i, 0→o, 3→e`, so `1000 за 300`
     canonicalises to `io za eoo`. No amount of money was visible to the
     fraud detector at all. Digit and link checks now run on the original
     text.

## Precision: two false positives found and fixed

Both were sentences a student would actually write.

- **Sharing your own phone number was treated as doxxing.** Any Turkish
  mobile number in any post was blocked, including "benim numaram …", which
  is the single commonest way a number appears on a campus feed. Publishing
  your own number is your decision.
- **"I'll find you tomorrow, I need to give you the notes" was refused as a
  threat, while "we will find you" was missed.** Exactly backwards: the
  menace is in the plural — a group announcing it will find you — not in
  the verb.

Precision is now 100% on both sets, with zero blocked false positives.

## Ambiguity is held, not published

The brief asks for this and it is load-bearing given a 55% recall. A message
that quotes a threat in order to report it — «he said "you will regret it",
who do I report this to?» — is genuinely ambiguous to an automated system.
It is now **held for a moderator** rather than refused, so the person coming
forward is not the one who gets blocked, and a real threat wrapped in
"asking for a friend" still cannot publish.

`softenIfReporting()` downgrades a removal to a review when reporting
markers are present. It never clears: "I will kill you. Who do I report this
to?" would otherwise publish on the strength of one trailing phrase.

## The honest limitations

- **SEX scores 0% on the fresh set.** All three items are coercive
  propositions with no explicit word in them — "come to my room tonight and
  I won't tell anyone about the exam". The pattern is quid-pro-quo
  reasoning, not vocabulary, and neither layer currently models it. This is
  the largest single gap and it is in the category where the consequences
  of a miss are worst.
- **The fresh set is not an independent holdout.** It was written after the
  fixes by the same author. Different scenarios, different vocabulary,
  different obfuscations — but not independent. A set written by someone
  who has not seen the lexicon, ideally drawn from real reported content, is
  what would make these numbers trustworthy.
- **Both sets are small** (60 and 29 harmful items). A category figure
  drawn from three items moves 33 points per item.
- **The semantic classifier must be running.** With the service down the
  system falls back to rules alone, i.e. 31% on unseen text. It is a
  separate process on :8801 and nothing currently restarts it.

## Reproducing

```bash
# rules only
php artisan moderation:benchmark eval_v4_holdout

# whole pipeline (needs the classifier on :8801)
php artisan moderation:benchmark eval_v4_holdout --semantic

# the regression floors
php artisan test --filter=ModerationBenchmarkTest
```

`ModerationBenchmarkTest` asserts floors that have actually been met — per
category and per language, so an overall average cannot hide a category
that collapsed. The precision floor is 100% on both sets: recall can always
be bought by blocking more, and a moderation system that refuses ordinary
conversation is one students route around, at which point it protects
nobody.
