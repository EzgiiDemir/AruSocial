# Moderation runbook

Who works the queue, how fast, and what to do when it backs up.

This document is the half of moderation that is not code. The automated
pipeline catches roughly two thirds of unseen harmful text and the
nudity/gore it was calibrated for; **everything else depends on people**,
and a queue nobody drains turns "held for review" into a silent delete.

## Roles

| Role | Who | Does |
|---|---|---|
| **Moderator** | Student Affairs staff, min. 2 trained | Works the case queue, decides content and account actions, answers appeals |
| **Escalation owner** | Head of Student Affairs | Cases marked `escalate`, anything involving a minor, threats naming a person, self-harm follow-up |
| **Safety contact** | `guvenlik@arucad.edu.tr` | Monitored inbox; the address published in-app and at `/legal/safety` |
| **Technical owner** | IT / the team owning this repo | Classifier uptime, threshold changes, monthly measurement |

Two trained moderators is a minimum, not a target: one person cannot
cover illness, holidays or their own coursework, and a single reviewer
also has nobody to check their judgement against.

## Response targets

| Queue | Target | Hard limit |
|---|---|---|
| Case flagged `Acil` (priority ≤ 20) | 2 hours | 6 hours |
| Any user report | 24 hours | 48 hours |
| Held content (`pending`) | 24 hours | 48 hours |
| Appeal | 3 working days | 5 working days |

The 24-hour figure is published in the Terms and on the safety page. It
is a promise to students, not an internal aspiration — if it cannot be
met, change the published figure rather than quietly miss it.

## Shifts

- **Weekdays:** one moderator on duty 09:00–17:00, checking the queue at
  least at 09:00, 13:00 and 16:00.
- **Weekends and holidays:** one moderator on call, checking once daily.
  Self-harm and threat cases are pushed to the escalation owner directly.
- **Exam periods and orientation week:** expect the highest volume of the
  year; put both moderators on duty.

A shift means the queue is checked, not that someone is available. What
matters is that no held post waits longer than the target above.

## Checking the assistant, not just moderation

Two commands, both safe to run any time and both read-only:

```
php artisan ask:benchmark          # does retrieval find the right page?
php artisan moderation:status      # is the classifier up, is the queue draining?
```

`ask:benchmark` runs 30 labelled campus questions in Turkish, English
and Russian and reports how many found the page that answers them. It
exits non-zero below 80%, so it works in CI or a release checklist. Run
it after a crawl, after changing a retrieval threshold, and before a
release. A drop means either retrieval regressed or the corpus lost a
page — `--verbose-misses` shows which.

Both need the classifier running on `:8801`. Without it, moderation
falls back to rules alone and retrieval to keyword matching; the app
keeps working and gets measurably worse.

## The daily check

```
php artisan moderation:status
```

Exits non-zero when something needs a person — and, since 11 September
2026, **runs hourly on its own** (`routes/console.php`) rather than
waiting for someone to remember it.

It no longer depends on anyone reading that exit code. When it finds a
problem it writes

```
moderation.status.problems  {"problems":[...],"held_total":N,
                             "reports_unresolved":N,"appeals_open":N}
```

at **error** level, and raises a Sentry event when `SENTRY_DSN` is set.
The healthy path writes `moderation.status.ok` at debug, so "the check
ran and was happy" stays distinguishable from "the check never ran" —
in a log that only records failures those look identical.

For this to work the Laravel scheduler itself must be running:

```
* * * * *  cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, one Task Scheduler entry every minute doing the same. **If
that is not set up, nothing below happens at all** — verify with
`php artisan schedule:list`.

It reports and alerts on:

- **classifier / text signals** — a signal that fails to load
  makes the pipeline quietly weaker while everything still looks healthy;
- **oldest held item** — the number the response targets depend on;
- **oldest unresolved report** — the number the published 24-hour promise
  depends on;
- **enforcement on/off** — off is intended only during testing;
- **24h decision counts** — read as a ratio, not a total.

### What an alert means

| Alert | First action |
|---|---|
| classifier `unreachable` / `model_not_loaded` | Restart `image-moderation-service`. Uploads are failing closed meanwhile — students see a 503, which reads to them as rejection. |
| `CLIP signal is not loaded` | The service is up but running on the NSFW model alone — the configuration measured publishing a nude at 0.0090. Restart and confirm all three signals. |
| `semantic text layer unreachable` | Text moderation has degraded to the phrase list, which publishes paraphrase. Restart; do not leave overnight. |
| `held content, oldest waiting Nh` | Someone works the queue. If this fires repeatedly, the shift pattern is wrong, not the threshold. |
| `unresolved reports, oldest Nh` | The published promise is being missed. Treat as an incident. |
| `enforcement is OFF` | Expected during testing only. Before launch, remove the flag. |

## Monthly measurement

Thresholds are policy, and policy that is never re-measured rots.

```
cd image-moderation-service
python per_category_threshold.py          # headroom per category
.venv/Scripts/python benchmark.py --manifest benchmark_manifest.json
```

and from the backend, with the API running:

```
scratchpad/run_all_sets.ps1               # recall and false positives
```

Record the numbers in `docs/MODERATION_COVERAGE.md` with the date. If
recall or false positives have moved, find out why before changing a
threshold — the last three times a number moved, the cause was a bug in
the measurement or a collision in the matcher, not the model.

**Never tune against a held-out set.** `text_holdout2.json` is the only
honest estimate of performance on writing nobody anticipated; the moment
anything is adjusted against it, retire it and write another.

## Decisions

Content and account decisions are separate on purpose.

- **Content:** approve / hold / remove / escalate.
- **Account:** none / warn / restrict / suspend.

Removing a post is not a judgement about the person who wrote it. A first
mistake gets the content decision and `none`. Account action follows a
pattern, which is why the case screen shows the author's history.

**Self-harm is never punitive.** The post publishes, the author is shown
counselling contacts, no strike is recorded, and a review event reaches
this queue. If you see one, tell the escalation owner the same day. The
system is deliberately more willing to offer help unnecessarily than to
stay silent.

**Appeals are reviewed by someone other than the original decider**
wherever staffing allows. An appeal answered by the person being appealed
against is not an appeal.

## What the system does not catch

State this to anyone who assumes the filter is complete:

- roughly a third of harmful text phrased in ways nobody anticipated,
  concentrated in threats, harassment, sexual coercion, scams and coded
  hate speech — the five categories where safe and harmful text overlap
  and no threshold separates them;
- weapons, hate symbols and drug imagery in photographs — scored but
  **unvalidated**, so they decide nothing;
- anything in a language other than Turkish, English or Russian.

User reports are not a fallback for these. They are the primary control.
