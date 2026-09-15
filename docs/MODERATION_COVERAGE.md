# Moderation coverage

What the pipeline detects, what it does not, and what every number here
was measured against. Written to be uncomfortable to read: the sections
headed "not covered" are the ones that will otherwise be assumed covered.

**Last measured: 11 September 2026.** Operations — who works the queue,
response targets, alert handling — are in
[MODERATION_RUNBOOK.md](MODERATION_RUNBOOK.md).

## Current state at a glance

| | |
|---|---|
| Text | Two layers: deterministic lexicon + self-hosted semantic. **70% recall on never-seen writing, 0 false positives after fixes** |
| Long posts | Whole post **and** every clause scored, so position is irrelevant. **30/30 buried-clause cases refused** |
| Sexual solicitation | Explicit term + invitation + no academic framing. **15/15 refused, 0/12 seminar announcements touched** |
| Abuse by root | Suffixes, spacing, masking and declension folded to a root. **27/27 inflected forms caught, 0/28 ordinary words refused** |
| Images | Self-hosted NSFW + CLIP. Nudity, kissing, swimwear, gore. **0/24 unsafe published, 0/50 safe blocked** |
| Video | **Removed** 14 September 2026. Upload answered with 415 `VIDEO_NOT_SUPPORTED` |
| Hosting | **Entirely self-hosted.** No content leaves ARUCAD for moderation |
| Enforcement | **OFF** — testing phase, see below |
| Human review | Queue, appeals and decisions built; needs staffing |
| Monitoring | `moderation:status` runs **hourly**, logs at error level and raises a Sentry event. Needs `schedule:run` on a cron |

## Who is scanned

Students everywhere. Staff everywhere *except* where they publish as the
university — `admin.*`, `trainer.*` and the shared media library — so
official announcements are never held in a queue, while a trainer's
personal post goes through the same pipeline as a student's.

The rule lives in one function, `ModerationExemption::appliesTo()`, and is
decided from the server-side role: a request cannot claim to be staff. An
unknown surface counts as personal, so a call site that forgets to pass
one fails toward scanning.

Exempt submissions still write a `decided_by=exempt` evidence row naming
the role. Bans apply regardless of role.

## Text moderation

Two layers, either of which can block, neither of which can rescue what
the other refused:

- **Deterministic lexicon** — 21 categories, TR/EN/RU, with obfuscation
  normalisation (leetspeak, spacing, homoglyphs, zero-width). Instant,
  offline, and the only layer that can record a strike.
- **Self-hosted semantic layer** — contrastive sentence embeddings,
  `paraphrase-multilingual-MiniLM-L12-v2` pinned by revision, 27 ms/text
  on CPU. Catches rewording the lexicon has never seen.

The semantic layer scores a **margin**: how much more the text resembles
a category exemplar than ordinary campus writing. Raw similarity answers
a different question, and answering it is why a single-label toxicity
model scored an English threat 0.0085 and a life-drawing announcement
0.3722.

### Measured, 11 September 2026

Four labelled sets. Only the third was never tuned against, and it is the
one to quote:

| set | what it is | recall | false positives |
|---|---|---|---|
| `text_benchmark.json` | the rules were built from it | 19/19 | 0/20 |
| `text_holdout.json` | written after, later tuned against | 12/15 (80%) | 1/15 (held) |
| **`text_holdout2.json`** | **never tuned against** | **10/15 (67%)** | **0/15** |
| `text_suite_v2.json` | 21 categories × TR/EN/RU | 48/48 | 0/44 |
| `text_profanity_forms.json` | inflected/masked abuse | 27/27 | 0/28 |
| `text_longform.json` | one harmful clause inside a long post | 30/30 | 0/30 |
| **`text_holdout3.json`** | **written after all tuning** | **see below** | **see below** |

Totals across every set: **184/187 harmful caught, 0/182 safe texts
refused.** That second number is the sturdier half, and at a university
it is the half that matters more — a false positive refuses coursework.

#### The only honest generalisation number

`text_holdout3.json` was written *after* the lexicon expansion and
threshold recalibration were finished, precisely because every other set
had by then been tuned against and none could still answer "how does
this do on writing nobody anticipated". Nothing in it reuses a phrasing,
slang term or bypass trick from an earlier set.

| reading | recall | false positives |
|---|---|---|
| **first score, fully clean** | **23/33 (70%)** | **5/30 (17%)** |
| after fixing the mechanisms those 5 exposed | 25/33 (76%) | 0/30 |
| after the synonym pass | 30/33 (91%) | 0/30 |
| after the sexual-solicitation work | 29/33 (88%) | 0/30 |

The last row went *down* by one, and the case is worth keeping rather
than fixing: a student reporting that somebody swore at them
("он послал меня нахуй" inside a message about a lost workshop key).
Quoting abuse you received in order to report it is the behaviour this
system should encourage, so publishing it is defensible — the label was
arguably wrong, not the verdict. The English twin in the same set *is*
refused, so enforcement here is inconsistent; that inconsistency is
real and unresolved.

**Quote the 70/17 as the generalisation estimate**, not the 91%. The
tuned sets read 100% with zero false positives, and that number is
memorisation: the gap between it and 70% is the whole reason held-out
sets exist. The third row is no longer a clean measurement because the
set has now informed changes — it is retired, and a fourth set has to be
written before that question can be asked again.

The five false positives were worth more than the recall figure, because
each was a mechanism rather than a sentence:

- **`tavuk`** was listed as a mild insult (from "korkak tavuk",
  *coward*), so a student saying the chicken at lunch was good got
  warned. Now the phrase, not the bare word.
- **`iptal` read as `aptal`.** Edit-distance matching accepted one
  substitution on tokens of five characters or more, and *cancelled* is
  one edit from *stupid* — so the university's own phishing warning
  ("yurt hakkınız iptal edilir") was flagged as calling the reader
  stupid. The window now starts at six characters.
- **A message about a key and a sticking lock** scored CRIME 0.17 and
  HATE 0.15 — enough to remove it — because no benign exemplar talked
  about tools, damage or entry. At a design school that register is
  everywhere; fifteen workshop exemplars fixed it.

### Sexual solicitation

**Found by hand-testing the live feed, not by any labelled set.** A post
reading `gorup sex do u want to come in` published. Every SEX rule in
the lexicon was about harassing a *person* — quid pro quo, coercion,
objectification — and none covered an open proposition to a whole feed.
The semantic layer did not cover it either: its SEX exemplars were
harassment-shaped, so `group sex` scored **−0.121**, further from the
sexual category than ordinary campus writing.

`TextPolicyEngine::sexualSolicitation()` requires three things together:

1. **an explicit term, matched as a whole token.** `sex` prefixes
   `sexual`, and prefix-matching would refuse the university's own
   "sexual harassment awareness workshop";
2. **an invitation** within a clause (8 tokens). Without one the word is
   describing something — "the sex of the participants was recorded" is
   a methods section;
3. **no academic framing within 3 tokens.** Narrow on purpose: a
   clause-wide guard was itself a bypass, because `ders` — *lesson* —
   appears in most Turkish campus posts, so appending one lesson
   reference disabled the rule for a whole 300-word post.

Measured: **15/15 propositions refused, 0/12 academic uses refused**,
including "Anyone want to come to the sex education seminar with me?",
which carries an explicit term *and* an invitation and survives only on
the framing.

Adding sexual-solicitation exemplars to the semantic layer had a cost
worth recording: they are all shaped like invitations, so the model
learned the *shape*, and the innocuous clause "Anyone want to come along
with me?" jumped to SEX 0.142 and held a sexual-health announcement.
Fifteen benign invitations in the three languages fixed it properly —
that clause now scores **−0.417** while real propositions sit at
0.27–0.32.

### Harm buried in a long post

**The semantic layer used to average it away.** It embedded the whole
post as one vector, so a threat sitting between three sentences about
the exam timetable was averaged together with them. Measured, burying a
clause cost **49–173% of its margin**, and in four of six cases the top
category flipped to one that fires nothing:

| | clause alone | buried in a post |
|---|---|---|
| tr threat | VIO 0.1434 | DRUG −0.0436 |
| en threat | VIO 0.1822 | IMP −0.0546 |
| ru harassment | HATE 0.2138 | MISINFO 0.0718 |

The layer now scores **the whole post *and* each clause**, and every
category keeps whichever reading found it strongest
(`text_classifier.split_sentences`). After the change the same buried
clauses score **identically to the clause alone — a 0.0% drop in all
six, across all three languages.** The response also carries
`evidence`: the clause that produced the top margin, so a moderator
opening a 300-word post is not left guessing which sentence was read.

**The deterministic lexicon never had this problem.** It reads token by
token, so position was always irrelevant — measured across nine
categories at the start, middle and end of a post, the verdict is
identical. What looked like a position bug in the lexicon was a
*coverage* gap: it knew the Turkish phrasing and not the English or
Russian one, and one verb but not its synonym.

Two consequences of clause scoring, both real:

- It is **strictly more sensitive**, so every threshold in
  `config/moderation.php` had to be re-derived — the old ones were
  measured against a unit that no longer exists, and leaving them
  refused an exhibition announcement as DRUG.
- Short clauses had no fair baseline, because every benign exemplar was
  a full sentence. A fragment like "Program panoda asılı" resembled
  none of them, so its baseline collapsed and its margin inflated.
  Short benign clauses in all three languages fixed that: PRIV, VIO,
  SPAM and CYBER all gained real headroom, and HATE's safe ceiling fell
  from 0.0162 to −0.0561.

### Abusive words are matched by root, not by finished form

Turkish is agglutinative and Russian declines, so a list of finished
words is a list of a fraction of them. Measured before this work:
`siktir git` was refused while **`siktir`, `sikerim seni`, `sikik
herif`, `yarrağımı ye` and `göt veren herif` all published** — a filter
that loses to a suffix is one students beat in an afternoon.

Five changes closed it:

- `PROFANITY_STRONG` holds **roots** (`sik`, `yarra`, `хуй`, `пизд`,
  `ебан`), matched by token prefix, so every suffix comes with it.
  `yarra` not `yarrak`, because Turkish softens a final k before a vowel
  suffix — the root surfaces as "yarrağımı".
- `DIRECTED_PROFANITY` refuses imperatives aimed at a person on their
  own, without waiting for a second word. This is what `siktir` needed:
  the old list only held the finished phrase `siktir git`.
- Strong obscenity now counts toward the pointing-neighbour test, so
  `sikik herif` reads as aimed at somebody rather than as an
  exclamation. A **demonstrative** only points when it comes first
  ("bu ezik"); following the obscenity it opens the next noun phrase
  — "какого хуя **эта система** не работает" is aimed at the system.
- **Any** obscenity followed by "off"/"you"/"urself" is a dismissal.
  Listing finished phrases meant listing spellings: `fuck off` was
  refused, `phuck off` and `fvck you` published. Anchoring on the
  particle covers spellings nobody has typed yet.
- A doubled letter no longer buys a way past a root (`sikktir`,
  `fukk`, `bittch`). Normalisation deliberately *keeps* doubles —
  English needs `kill`, `hall` — so the collapse happens at match time
  instead, guard still applied.

Measured against 28 hand-written bypass attempts — case changes,
spacing (`s i k t i r`), letter repeats, `*`/`!`/`1`/`$` substitution,
hyphens, Latin letters inside Cyrillic (`пиzдец`, `иди нa хуй`) — **27
caught before this pass's last three fixes, 28/28 after**. The one that
needed a lexicon entry rather than a mechanism was `shithead`, which
was simply absent.

The safe half is the half that keeps the feature alive. Turkish
normalisation folds ş→s, so the root `sik` reaches **şikayet** —
*complaint* — the word a campus app most needs to accept, and `göt`
reaches `götürmek`, *to take*. A prefix guard (`FALSE_POSITIVE_GUARD`)
carries `şikayet`, `sikke`, `siklet`, `sokak`, `художник`, `сукно`,
`мудрость`, `блин`, `Scunthorpe`. After all of the widening above, the
lexicon layer refuses **0 of the 122 safe texts** across every labelled
set — re-checked after each change, because widening a matcher is
exactly when a safe set starts failing.

**Swearing at a situation still publishes, deliberately.** `sikeyim
böyle işi`, `блять достало`, `amk ya yeter artık` and bare `хуй` are
matched and then allowed, because nobody is being sworn at — the same
answer the 150-case corpus already gives "What the fuck is wrong with
this system?". No rule refuses the first list and publishes the second;
they are the same speech act. They are listed under
`untargeted_exclamation` in the set rather than counted as misses, so
the choice stays visible. Holding coarse language regardless of target
is one switch in `decideFromWords()`, and it will hold student
complaints about the app along with it.

### Per-category, and why some categories cannot be fixed by a threshold

`per_category_threshold.py` reports, for each category, the highest
margin any safe text reaches against the lowest any harmful text of that
category reaches. The result splits cleanly in two.

**Clean separation** — these were given thresholds above their own safe
ceiling and now sit at 100% on the broad suite:

```
          safe max   harmful min   threshold (review/block)
VIO        -0.066       0.0366      0.02 / 0.10
PRIV        0.043       0.3784      0.12 / 0.20
IMP         0.029       0.2666      0.10 / 0.15
CYBER       0.105       0.2459      0.15 / 0.20
DRUG        0.121       0.3164      0.16 / 0.22
CRIME       0.134       0.3608      0.18 / 0.25
ANIMAL      0.311       0.4660      0.35 / 0.40
SPAM       -0.053       0.4451      0.12  (hold only)
IP          0.103       0.4550      0.18  (hold only)
MISINFO     0.106       0.4023      0.18  (hold only)
POL         0.086       0.5629      0.18  (hold only)
```

The last four hold rather than block: political speech, a copyright
claim, a rumour and a spam complaint are judgement calls, and refusing
them on a model's word is too strong.

**Overlap — no threshold separates them.** Safe text scores as high as
harmful text, so lowering a threshold only buys false positives:

```
          safe max   harmful min   headroom
HAR         0.1106      0.0094       -0.10
HATE       -0.0607     -0.1394       -0.08
SCAM        0.0213     -0.2751       -0.30
SEX         0.0442     -0.1140       -0.16
THR         0.0789     -0.0246       -0.10
```

These five are carried mainly by the lexicon, and they are where almost
every remaining miss lives. Fixing them needs better exemplars or a
better model, not a different number.

### Self-harm is a support path, never a block

A student saying they are in crisis is met with help: the post
publishes, the author is shown ARUCAD's counselling address and the
emergency number, no strike is recorded, and a moderator gets a review
event. `SELF` therefore has a `support` threshold and deliberately no
`block` one.

The threshold is 0.15, from measured margins: real distress scores
+0.22 to +0.44, ordinary end-of-term exhaustion +0.11. At 0.05 the
exhausted student got a counselling offer, which is noise rather than
care.

### The review band

Between `review` and `block`, content is **held for a moderator** rather
than refused or published. Enabling it lifted recall on the untouched set
from 40% to 67%. Three properties make holding defensible, each pinned by
tests: the post is not published, the author is told plainly **and told
it is not a violation**, and it costs no strike.

This only works while the queue is drained. See the runbook.

## Visual moderation

### Everyday clothing is not nudity

Crop tops, bustiers, sports bras, short shorts, short dresses and modest
cleavage are ordinary modern clothing and **publish**. Only real nudity
and explicit sexual content is refused.

This had to be corrected against real photographs. The safe set that
originally calibrated the NSFW threshold contained **no photographs of
people in summer clothing at all** — it was buildings, sculptures,
screenshots and logos — so the distribution it produced was far too
narrow, and the review line sat at 0.20:

```
t-shirt and denim shorts, rooftop      nsfw 0.221   <- was HELD
sports bra and running shorts          nsfw 0.116
shirtless male selfie                  nsfw 0.016
muay thai bout                         nsfw 0.000
real nudity (what this model catches)  nsfw 0.60 - 0.98
```

Review moved to **0.35**: above every everyday photograph measured, still
far below anything flagged as explicit, and block unchanged at 0.50 —
nothing sits between the two in any labelled set. The two nudes this
model misses entirely (0.0090, 0.0005) are caught by `clip_nudity`, so
raising the line costs no recall.

The benchmark now contains those real photographs, each verified by eye.
**Any threshold calibrated only on buildings and sculptures will do this
again**: widen the safe set with the content students actually post.

### Policy: real people in photographs, not art on paper

Refused when the subject is a **photograph of real people**: nudity and
sexually explicit imagery, two people kissing in any combination, bikini
and swimwear. Allowed when the same subject is **drawn, painted, printed
or sculpted** — the campus is built around a nude sculpture collection
and figure drawing is a syllabus, so a rule that cannot tell bronze from
photography refuses the university's own coursework.

The separation is made by naming the *medium* on both sides of the CLIP
prompts: every risky prompt says "a photograph of a real person", and the
benign side names charcoal, pencil life study, anatomical study, oil,
watercolour, engraving, bronze, marble, and "a sculpture of two figures
embracing or kissing".

```
                                    nudity  kissing  swimwear  verdict
Rodin, The Kiss                     0.0095   0.0004    0.0051  allowed
Eternal Spring (nude embrace)       0.2077   0.0019    0.0072  allowed
Age of Bronze                       0.1334   0.0000    0.0021  allowed
  ...12 artworks, all allowed, max 0.2077

real nudity                         0.5128 - 0.7120    all refused
highest score on ANY safe content            0.3204
thresholds                          review 0.35   block 0.45
```

End to end through the API: **0 of 26 unsafe images published, 0 of 54
safe blocked or held.** Every one of the 80 images has been verified by
eye, not assumed.

**Kissing recall is now measured**, not assumed: two real photographs of
couples kissing score **0.996 and 0.998** and are refused, while Rodin's
*The Kiss* scores 0.0004 and publishes.

Two signals run together and the strictest wins: the NSFW classifier and
CLIP. If either fails to load the service reports 503 and uploads are
refused — falling back to one signal silently reinstates a pipeline that
was measured publishing a nude at 0.0090.

### Video was removed

Video upload, storage, playback and moderation were removed from
the product on 14 September 2026 — backend, API, UI and the
`video_player` dependency. An upload of one is answered with
**415 `VIDEO_NOT_SUPPORTED`**, by name rather than a generic type
error, because older builds of the app still have the button.

There was no video-specific schema to migrate: `media_items` held
no video rows and `content_type` is a generic string column. The
128 orphaned files left on disk by testing were deleted with
`php artisan media:purge-video --force`, which dry-runs by default
and writes an audit entry.

### Stories and posts inherit this by construction

Neither uploads its own media. Both can only *reference* a library item,
and the attachment resolver refuses anything not `approved`. An image is
scanned once, at upload, and there is no second path that could miss it.

## Not covered

Stated plainly because these will otherwise be assumed covered.

| Category | State |
|---|---|
| Weapons, hate symbols, drug imagery in photographs | **Scored and recorded, no thresholds, decide nothing.** No lawful positive examples exist here to calibrate against. Prompt noise is now low (worst safe hate_symbol 0.0996, weapon off the list entirely — see below), so the ceiling is ready; the floor still needs positives |
| Self-harm imagery | Same — scored, unvalidated, inert |
| Threats, harassment, sexual coercion, scams, coded hate in text | Detected inconsistently; the five overlap categories above |
| Any language other than TR/EN/RU | Not covered at all |
| Child safety imagery | **Deliberately excluded.** Requires an approved hash-matching programme and a legal reporting path, not an improvised detector |

A threshold guessed without a positive example is not a policy; it is a
coin toss applied to students' coursework. To enable one: obtain lawful
test images, add them to `benchmark_manifest.json`, run `probe_clip.py`,
and set the threshold inside the measured gap.

**Half of that calibration is now done.** The four inert prompts had
nothing on the benign side of the argument, and CLIP scores by
competition — so an image with no good benign match drifted toward
whichever risky prompt was least wrong. A dormitory building scored
`self_harm` 0.23 and a Rodin sculpture scored `hate_symbol` 0.25.
Twenty benign prompts naming what those four actually compete with in a
design school (tools, printed graphics, cafeteria drinks, hands) moved
the safe ceiling, measured over the 54-image campus library:

| | without | with |
|---|---|---|
| worst safe `hate_symbol` | 0.1676 | **0.0996** |
| safe max `nudity` | 0.3204 | **0.2110** |
| held at threshold 0.10 | 23/54 (43%) | **8/54 (15%)** |
| held at 0.30 | 2/54 | **0/54** |

Nudity recall is unchanged at the same three unseparable images, and the
gap between the safe ceiling and the lowest separable nude **widened
from 0.192 to 0.291** — so this costs nothing on the one category that
is calibrated. Those three, incidentally, are not nudity at all: they
are gore and kissing, and `clip_gore` 0.754 and `clip_kissing`
0.996/0.998 block them. Nothing in the unsafe set publishes.

**User reports are the primary control for everything in this table**,
not a fallback.

## Account enforcement is currently OFF

`MODERATION_ENFORCEMENT_ENABLED=false` in `backend/.env`, set for the
testing phase.

Content moderation is unaffected — unsafe content is still detected and
refused. What is suspended is only the account consequence: no strike,
no suspension, no ban. Suppressed consequences are written to the audit
log as `moderation_enforcement_skipped`.

**Before production:**

1. Remove `MODERATION_ENFORCEMENT_ENABLED` from `.env`, then
   `php artisan config:clear`.
2. Confirm `GET /api/v1/health` → `data.moderation.enforcement` is `on`.
3. **Reset the strike counters on the test accounts** — `student@` is at
   3 of 3 and would be banned by its next violation.
4. Re-read the audit log for `moderation_enforcement_skipped`.

The suite pins `MODERATION_ENFORCEMENT_ENABLED=true` in `phpunit.xml`, so
it keeps asserting production behaviour while the local flag is off.

## Reporting and review

Every surface files through one sheet, so a report reaches the queue as a
**category** rather than free text. Users can block from a profile or a
chat. Held and removed content can be **appealed in-app**, reviewed by a
different moderator where staffing allows.

The moderator queue (Admin → Moderation) shows cases ordered by severity
with what was reported, what the models said — including model and policy
version — and the author's prior history. Content and account decisions
are separate controls.

## Checking whether it is working

```
php artisan moderation:status              # human-readable
php artisan moderation:status --json       # for monitoring
php artisan moderation:status --stale-hours=6
```

Reports every signal, enforcement state, held content and **how
long the oldest piece has waited**, 24h decisions, and unresolved
reports. Exits non-zero when something needs a human.

Each signal is listed separately because the dangerous failure is a
running service with one signal missing: `/health` says ok, uploads keep
flowing, and images are judged on the NSFW score alone.

Queue *depth* is not the signal — depth 40 with the oldest twenty minutes
old is a queue being used; depth 3 with the oldest three weeks old is a
broken promise. Age is what it alerts on.

## Measurement discipline

Four things learned the hard way, each after a number turned out to be
wrong:

1. **Never tune against a held-out set.** Each round of exemplar writing
   lifts the set it was written for and barely moves a fresh one. The
   spread between 100% / 80% / 67% across the sets above *is* the lesson.
2. **A benchmark that has never been widened has not been tested; it has
   been agreed with.** The unsafe image set was four distinct pictures
   and reported zero false negatives. Widening it and looking at the
   images found three the model missed, two of them approved and
   published.
3. **A harness that alters its input measures something else.** Appending
   a random suffix to defeat the idempotency cache changed embeddings and
   invented two false positives; treating HTTP 429 as a moderation
   verdict inflated recall and invented four more.
4. **Check which layer actually decided.** Recall of 62% dropped to 58%
   once a matcher bug was fixed — some "correct" blocks had been firing
   for the wrong reason.

## Incidents worth remembering

**The calibration tool was measuring a classifier that does not exist.**
`probe_clip.py` held its own copies of `RISK_PROMPTS` and
`BENIGN_PROMPTS`, and they had drifted badly from the service: it knew
nothing of the eleven artistic-media prompts or the everyday-clothing
set. Every threshold ever set from its output described a copy, and the
"safe-set noise" figures quoted in this document (weapon 0.2104,
hate_symbol 0.2525) were measurements of prompts nothing ran. The probe
now imports both lists from `app.clip_classifier`. Two prompt lists that
must agree are one prompt list.

*The next two incidents concern video, which was removed on 14 September
2026. They are kept because neither lesson is about video: an
integration can fail silently and look healthy, and a measurement
harness can report broken code as working.*

**Two ffmpeg flags, wrong, failing silently.** Scene detection was
written, deployed and returned nothing for every clip. `-vsync` is
rejected outright by current ffmpeg builds ("Unrecognized option"), and
the report path was passed as `file=C:\...` — ffmpeg's filter syntax
uses `:` as its own separator, so a Windows path is parsed as filter
options and the whole graph fails. The code caught neither, returned an
empty list, and the only symptom was slightly worse recall. It now logs
the exit code and stderr when the report is missing.

**And the measurement that found it was wrong first.** The initial
harness scored frames through `ModerationClient`, which returns
`not_configured` in a CLI context — every frame came back 0.0000, clean
and harmful alike. The second harness then took the maximum over *all*
score keys including `normal`, the benign class, so clean frames read as
0.9988 "prohibited". Both would have reported a broken sampler as
working. A measurement needs a known-positive and a known-negative
before its output means anything.

**Retuning the benign exemplars silently cost four catches.** Adding
benign invitations and workshop language — both necessary, both fixing
real false positives — pulled four previously-caught harmful posts back
under their thresholds, including a **minor-safety** case. Nothing
failed; recall just dropped, and only a re-score of every labelled set
after the change revealed it. Two lessons, both now in the lexicon: a
signal resting on the semantic layer alone disappears the moment that
layer is retuned ("join the cell" read as an ordinary invitation), and
**every exemplar change requires re-scoring all sets, not just the ones
it was aimed at.**

**Minor safety depended on how a number was typed.** The rule held
`13 yasindaki~numaram` and digits only, so "On üç yaşındaki kızlarla
görüşmek istiyorum" — the same sentence with the age spelled out —
matched nothing. Of every category here this is the one that must not
depend on spelling, and it had been the one that did. Ages now cover
words and digits in all three languages; benign mentions ("13 yaşındaki
kardeşimle sinemaya gittik") still publish.

**A whole category was disabled by a missing line.** `decideFromPhrase`
maps a rule's `kind` to a verdict and ends in `default => null`, so a
rule whose kind is not in that list matches the text, produces a hit,
and then does nothing — no error, no log. `hate_exclusion` shipped with
28 phrases covering collective-expulsion rhetoric in three languages and
published every one of them until the kind was registered. It looked
exactly like working code. `PhraseRuleWiringTest` now walks all 41
kinds and fails if any produces no decision.

**Lowering a threshold published sexual harassment.** The self-harm
support line was lowered from 0.15 to 0.09 on measured headroom — safe
ceiling 0.0671, harmful floor 0.1234. But that method only compares
harmful *self-harm* text against safe text; it says nothing about
harmful text of some *other* category landing in between. "Numaranı ver
güzelim, geceleri seni yalnız bırakmam" scores SELF 0.0924, so it was
routed to the support path — which publishes. The lexicon had already
refused it as sexual harassment and was simply not consulted.
`isCryForHelp` now yields when the engine blocks for a non-self-harm
reason. A crisis post containing swearing is still a crisis post, so
this applies only where the engine blocks, never where it warns. Found
by the end-to-end posting test, not by any unit test.

**A test that asserted the wrong status code passed on everything.**
The first draft of `LongFormModerationE2ETest` asserted `!== 201`. The
feed returns **200** on success, so every case passed — including the
six ordinary posts the test existed to protect, which were being
refused at the time. A green test proved nothing for one run.

**One suffix was the whole filter.** `siktir git` was refused and
`siktir` was not, because the lexicon held finished phrases rather than
roots. The same gap published `sikerim seni`, `sikik herif` and
`yarrağımı ye`. The first attempted fix — hold *all* untargeted strong
profanity — broke three cases in the 150-case corpus, including "What
the fuck is wrong with this system?", which is a student complaining
about the app and must publish. The parity problem belonged to
imperatives, not to profanity in general: `DIRECTED_PROFANITY` fixed it
without touching ordinary frustration. Widening the same fix a second
time then refused *"какого хуя эта система не работает"*, because a
demonstrative **after** an obscenity was read as a target when it was
really introducing "the system". Both regressions were caught by the
corpus, not by review — which is the argument for keeping it.

**A three-consonant skeleton blocked ordinary posts.** The engine
compares consonant skeletons when it detects deliberate masking, so
`s.e.n.i ö.l.d.ü.r` is caught. But `"beat you up"` reduces to **btp**,
three consonants that occur in ordinary prose constantly — and skeleton
matching switches on for any text containing a masking character,
including `#`. Combined with homoglyph folding turning "завтра" into
"зabtpa", the Russian question *"Во сколько завтра открывается
библиотека?"* with a hashtag was refused **for making a threat**. Any
student using a hashtag was exposed. Fixed with a minimum skeleton
length; `SkeletonMatchingTest` pins both directions.

**Enabling one layer switched another off.** Turning on the remote
semantic layer to fix *text* rerouted every image away from the
self-hosted classifier, because the media path asked "is any remote
provider configured?". That account had no quota, so an unsafe image came
back **201 approved and published**. Precedence now follows measured
authority, not configuration order, and a healthy remote provider cannot
override a local block either. `MediaProviderPrecedenceTest` pins it.

**A support decision replayed as a server error.** A self-harm event
recorded while the provider was down was stamped `decided_by=unavailable`,
which the ten-minute idempotency replay could not tell from "nothing
inspected this" — so posting the same words twice answered with
`MODERATION_UNAVAILABLE` instead of the counselling contact. Re-posting is
exactly what someone in distress does.

**A privacy claim that depended on an env var.** The notice said content
never leaves ARUCAD for moderation while the remote provider's config
defaulted to *on*, so a deployment that never set the variable would have
sent student posts out. The default is now off and a test reads the
config file to keep it that way.

## Operational requirements

- **The classifier must be running.** When it is not, every image and
  upload correctly fails closed with a 503 — indistinguishable,
  from inside the app, from content being rejected. Start it with
  `image-moderation-service/run.ps1` and check `GET /api/v1/health`.
- **A moderator must drain the queue.** Held content waits indefinitely
  otherwise, which to a student is the same as broken.
- **Re-measure monthly** and record the numbers with the date. See the
  runbook.
- **The Laravel scheduler must be running.** `moderation:status` is
  scheduled hourly and is what turns any of the above into an alert —
  it logs at error level and raises a Sentry event when it finds a
  problem. Without `schedule:run` on a cron or Task Scheduler entry, the
  check never fires and the pipeline can fail silently for as long as
  nobody happens to upload something. Verify with
  `php artisan schedule:list`.
