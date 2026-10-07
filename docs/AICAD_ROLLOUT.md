# AICAD SupportedFacts rollout runbook

How the fact-bounded answer path for **planned (multi-task) questions** goes:

```
staging ON  →  production STAFF_ONLY  →  production ON
```

Each step needs its gates met (§8). Single-intent questions and the
operational and direct fast paths are not affected by any mode. The design is
in `AICAD_KNOWLEDGE.md` (Phases 3C and 3C.1).

## 1. Configuration (exact keys)

| Setting | Values | Read as |
|---|---|---|
| `AICAD_SUPPORTED_FACT_GENERATION_ENABLED` | `off` · `staff_only` · `on` (`true`/`false` still work) | `config('ai.supported_facts.mode')` |
| `AICAD_CAMPUS_TIMEZONE` | default `Europe/Nicosia` | `config('ai.campus_timezone')`, used for "open now" |
| `AI_CACHE_ENABLED` / `ai.cache.*` | unchanged | answer cache |

Target values:
- **Production:** `off` until the staff-only step.
- **Staging:** `on`.

Staging and production are configured through each server's `.env` (see
`ENVIRONMENTS.md`); there is no versioned staging env file. After changing
`.env`, run `php artisan config:cache` if config is cached, then
`php artisan queue:restart`.

**Staff** in `staff_only` means the existing back-office permission check,
`GranularPermissions::canAccessAdminPanel()`: anyone with an admin-panel role
grant. There is no e-mail list.

**Cache safety:**
- Planned questions are never served from the shared answer cache, whatever
  the mode.
- The cache key carries the mode (`|sf:<mode>`), so a single-intent answer
  cached under one mode is never served under another.
- **Personal questions are never cached.** A pre-existing defect let a second
  student receive the first student's personal answer from cache; it is fixed
  in `AiResponder` and covered by a regression test.

## 2. Seeding

```
php artisan db:seed --class=AiProgrammeAliasSeeder
```

This loads the official English and Russian programme names as `programme`
aliases. It is idempotent and additive only:
- A programme that already has an alias in a locale (seeded, added, renamed or
  deactivated by an operator) is skipped.
- A rerun never duplicates a row and never brings back a renamed name.
- Writes go through the model, so the alias cache and the retrieval version are
  invalidated immediately.

Run it on staging, and on production at the staff-only step. Nothing runs
against production automatically.

There are **no migrations** for this rollout beyond
`2026_10_05_100000_extend_language_of_instruction_phrases` (data only, from
the planner hotfix). It ships with the code and goes through the normal
`php artisan migrate --force`.

## 3. Staging deployment checklist

> **Superseded by [AICAD_STAGING_PACKAGE.md](AICAD_STAGING_PACKAGE.md)**, which includes the Phase 4A–4C migrations, `AiCampusAliasSeeder`, staging data readiness and fixtures. The list below is kept for history.

Follow `DEPLOYMENT.md` §3 (build and cache) and §4 (queue) for the commands.
In order:

1. Deploy the code; run `php artisan migrate --force` (expected: only the data
   migration above).
2. `php artisan db:seed --class=AiProgrammeAliasSeeder`
3. `.env`: `AICAD_CAMPUS_TIMEZONE=Europe/Nicosia`,
   `AICAD_SUPPORTED_FACT_GENERATION_ENABLED=on`
4. `php artisan config:cache && php artisan route:cache && php artisan view:cache`,
   then `php artisan queue:restart`
5. `php artisan ask:readiness --live`. Expect no `FAIL`; the known data-gap
   `WARN` is expected.
6. `php artisan ask:readiness --probe-fallbacks --as=<staff e-mail>`. Expect all
   three probes `PASS`. This makes up to 3 model calls and is never counted as
   traffic.
7. `php artisan ask:evaluate --retrieval-only`, then compare with the last
   accepted run (`--compare=OLD,NEW`): 0 newly failing.
8. `php artisan ask:evaluate --full --tag=fact_generation --as=<staff e-mail>`,
   plus `--tag=staging_pack`.
9. Smoke-test with the pack in §4, from the app or Search Playground.
10. AICAD Health → **SupportedFacts rollout** and **Readiness**: the counters
    move and the mode reads `on`.

## 4. Staging test pack

Ask these in the app, as a student and as staff. Where staging data lacks a
fact, the right answer says so; the expected facts below come from the current
data, and nothing is invented.

| Area | Question | Expect |
|---|---|---|
| hours + open now | Kütüphane şu an açık mı ve nerede? | hours 09:00–17:00 (weekdays); open/closed matches campus time; Meditation |
| weekend status | kutuphane hafta sonu acik mi, nerede tam olarak | weekday hours (so closed at weekends); no "şu an açık" |
| tomorrow | yarın öğrenci işlerine uğrasam açık olur mu, yanıma ne almam gerekiyor | hours; documents not stated in sources; no invented list |
| programme language | Görsel İletişim Tasarımı İngilizce mi ve öğrenci işleri nerede? | İngilizce; Titan |
| multilingual names | Is Visual Communication Design taught in English and where is student affairs? · Визуальный Дизайн и Коммуникация: обучение на английском? И где студенческий отдел? | English; same programme |
| unofficial name | Is Graphic Design taught in English and where is student affairs? | not found in data; no guess onto another programme |
| no diacritics | ogrenci islerine gidicem acik mi su an, yanimda ne goturmem lazim | hours and open state; documents not stated |
| Russian | Библиотека открыта? Как добраться туда? | hours; route needs location |
| route with location | Öğrenci işleri bugün açık mı ve oraya nasıl giderim? (with location shared) | route distance and duration from RoutingService |
| route without location | same, location off | destination Titan; asks for location; no minutes |
| documents (insufficient source) | Öğrenci işleri bugün açık mı, hangi belgeleri götürmeliyim ve buradan nasıl giderim? | PARTIAL; no document names |
| data unavailable | bugün bir şeyler yiyebileceğim açık yer var mı | says no venue hours are recorded |
| club profile unavailable | (after "Fotoğraf kulübü hakkında bilgi ver") kulübün insta hesabı var mı, odaları nerede | no handle, no URL, no room |
| negative / no-result | Is Veterinary Medicine taught in English at ARUCAD, and where is student affairs? | "not found in our data", never "not offered" |
| ambiguous entity | idari bina açık mı ve oraya nasıl giderim? | asks which building |
| speculation bait | Kulüp odaları genelde nerede olur ve bu kulübün instagramı ne? | no "usually…" claims |
| URL regression | Bu kulübün instagramı ne ve kulüp odası nerede? | no invented URL |
| academic calendar | akademik takvim ne zaman başlıyor? | single intent, legacy path; dates labelled with their academic year (2025-2026 record is stale) |
| conflicting evidence | — | no conflicting campus data exists; covered by the PHPUnit fixtures (Phase 3B A–D, 3C conflicts) |

## 5. Turning a staging issue into a regression case

Real staging issue → reproduce in **Search Playground** → **sanitize** (remove
names, numbers and anything personal; rephrase while keeping the shape) →
**Save as Evaluation Test**. The dialog proposes assertions; edit them before
saving. Then **classify the failing layer** and fix only that layer:

| Layer | Examples |
|---|---|
| PLANNER | a phrasing not recognised ("insta", "açık yer"), temporal scope |
| ENTITY | a name not resolved (aliases) |
| PROVIDER | a provider not routed |
| EVIDENCE | wrong or missing evidence, policy |
| FACT EXTRACTION / FACT VALIDATION | a fact not extracted, or a wrong one accepted |
| GENERATION / MODEL VARIANCE | model wording, run-to-run variance |
| CLAIM VERIFICATION | an unsupported claim escaped, or a false positive |
| DATA QUALITY | the data does not hold it (AICAD Health → data-quality warnings, `affects`) |

Rerun the baseline (`ask:evaluate --retrieval-only` and the full tags). Never
copy a real student prompt into a case unedited; nothing is copied
automatically.

## 6. Observability (what the health page shows)

AICAD Health → **SupportedFacts rollout** shows 30-day counters for real traffic
only. Playground, evaluation runs and readiness probes are excluded.
- **Paths:** fact path vs legacy; COMPLETE, PARTIAL, UNAVAILABLE and FAILED.
- **Claim handling:** unsupported and speculative sentences removed; UNCERTAIN
  sentences, and answers containing them, by language and task type;
  restatements.
- **Regenerations:** with reasons (contradiction, coverage, empty).
- **Failures:** technical path errors, split by legacy vs deterministic
  fallback; claim-verification failures; AI unavailable.
- **Data:** planned answers with data not found.
- **Cost and speed:** model calls per answer; median and p95 latency (last 500).
- **Rates:** path-error, technical-fallback, UNCERTAIN, regeneration, withheld
  and data-unavailable.

These are counters and latencies only; no question, answer or sentence is
stored. The Search Playground shows each sentence's class and reason for a
diagnostic request.

`php artisan ask:readiness --metrics` prints the same aggregates.

## 7. Soak period

Observe staging for long enough to cover weekday and weekend traffic and
opening and closing times. **Two weeks** is the recommended window. Aim for at
least **200 fact-path answers** and **every staging-pack area exercised by
real or staff questions**. These are guidance, not code: a quiet staging
server needs longer, not a lowered bar.

Review weekly:
- Unsupported claims surviving labelled review. Sample 20 UNCERTAIN-bearing
  answers in the Playground.
- Rates: path errors, technical fallbacks, regenerations, UNCERTAIN,
  data-unavailable.
- p95 latency.

## 8. Gates

**Staging ON → production STAFF_ONLY.** All must hold:
1. No unexplained regressions: retrieval and full suites against the last
   accepted runs, 0 newly failing.
2. No known unsupported-claim escape in the labelled suite: the
   `fact_generation`, `staging_pack` and `phase3c1` full tags all pass, on two
   consecutive runs.
3. Path-error rate 0 in the soak window, or every error explained and fixed.
4. Programme aliases seeded; `ask:readiness` shows no `FAIL`.
5. Campus timezone verified: the "open now" answers in the pack match a wall
   clock in Kyrenia.
6. Rollback tested (§9) on staging.
7. Health page operational, with counters moving.

**Production STAFF_ONLY → production ON.** All must hold:
1. At least 200 staff fact-path answers over a window covering a weekend.
2. Technical path-error rate at or below 0.5%, with no deterministic-fallback
   error left unexplained.
3. No recurring unsupported-claim escape: none found twice in sampled review.
4. UNCERTAIN sentence rate at or below 10%, and a sampled review finds them
   harmless (§10).
5. Citation integrity: `citation_invariant_violations` 0 in sampled traces;
   the full suite's citation precision is 1.0.
6. Task answer coverage at or above 0.95 in the full suite; withheld rate at or
   below 2%.
7. Fact-path p95 latency no worse than the legacy planned p95.
8. Deterministic fallback verified (probe 2 PASS), and no DATA_UNAVAILABLE
   request reaching a legacy model call.

There is no composite score: each gate is a measured number.

## 9. Rollback (and restoration)

Rollback is a configuration change with no migration and no data rollback.

1. `.env`: `AICAD_SUPPORTED_FACT_GENERATION_ENABLED=off`
2. `php artisan config:cache` (if cached), then `php artisan queue:restart`
3. Verify the mode with `php artisan ask:readiness` (it shows `OFF`). Ask one
   planned pack question: Search Playground → "facts built, Phase 3B
   generation".

The response contract is identical in both modes. No cached answer crosses
modes (the key carries the mode), and fast paths are unaffected. To restore,
set the value back to `on` (or `staff_only`) and repeat steps 2–3. All of this
is verified by `AicadStagingRolloutTest`.

## 10. UNCERTAIN policy review

UNCERTAIN means a declarative campus-subject sentence with no fact and nothing
checkable. Today it is **kept**.

**Local acceptance review (pre-staging).** 18-question pack, real model:

| | First pass | After fixes |
|---|---|---|
| UNCERTAIN sentences | 8 of 42 (19%) | 2 of 39 (5%) |
| Answers containing one | 41% | 12% |

The first pass sorted into three groups:
- **Concrete escapes:**
  - day-specific open/closed claims ("open on Saturdays" against weekday-only
    hours)
  - a reversed-order "open now" ("открыта сейчас")
  - copied `[fact_…]` markup

  Fixed (claim verification and output sanitizing) and covered by regression
  tests.
- **Invented referral** ("…web sitesindeki «Kulüplerim» bölümüne…"): not
  caught, and still kept. This is the open risk the soak must measure.
- **Harmless framing** ("kampüs haritasında konumunu kontrol edebilirsin").

Interim recommendation: **FACT-TYPE-SPECIFIC POLICY.** Day-status claims are
now checked; keep generic UNCERTAIN; revisit referrals to named
sections/offices after the soak.

Decide after the soak, from the health counters (by language and task type)
plus a sampled Playground review:

| Recommendation | When |
|---|---|
| KEEP | UNCERTAIN sentences are harmless framing |
| FACT-TYPE-SPECIFIC POLICY | escapes cluster in one task type |
| REGENERATE / REMOVE | sampled UNCERTAIN sentences carry unsupported campus facts |

## 11. Production STAFF_ONLY plan (prepared, not executed)

1. **Pre-deploy:**
   - staging gates met (§8)
   - last retrieval and full runs green
   - `ask:readiness --live` clean on staging
2. **Deploy:** normal procedure (`DEPLOYMENT.md` §3, §8). The only migration is
   the data one above.
3. **Seed:** `php artisan db:seed --class=AiProgrammeAliasSeeder`
4. **Config:** `AICAD_CAMPUS_TIMEZONE=Europe/Nicosia`,
   `AICAD_SUPPORTED_FACT_GENERATION_ENABLED=staff_only`; then `config:cache`
   and `queue:restart`.
5. **Health:**
   - queue: worker running (AICAD Health → Queue)
   - Ollama and embedder: `ask:readiness --live`
6. **Cache:** no flush needed. The mode is part of the key, planned questions
   are uncached, and personal answers are never cached.
7. **Smoke tests:** as a staff user, five pack questions (hours + open now,
   multilingual programme, route without location, documents, club). As a
   student, one planned question: the trace shows the legacy path.
8. **Watch** (first 48 hours, then weekly): path errors, deterministic
   fallbacks, withheld rate, UNCERTAIN rate, p95.
9. **Rollback:** `AICAD_SUPPORTED_FACT_GENERATION_ENABLED=off`, then §9.
