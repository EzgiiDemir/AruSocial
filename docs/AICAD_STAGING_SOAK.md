# AICAD staging soak (Phase 4D runbook)

This is the procedure the team runs **on a real staging server**. Deploy first with `AICAD_STAGING_PACKAGE.md`. Nothing here touches production.

**Status (2026-10-06):** no staging server exists yet, so no soak has happened. The only hostnames in the repository are the production targets (`*-aruverse.arucad.edu.tr`), and internal DNS resolves every `*.arucad.edu.tr` name, including invented ones, to one internal address. Everything below is ready to run once a staging host is provisioned.

## 1. Priority-A data (before the soak)
Use **staff-verified values only**. Values found in crawled pages are candidates (`AICAD_COVERAGE.md` → candidate values), not data, until a content owner confirms them. Where verified values aren't available yet, use the labelled fixtures (`php artisan aicad:staging-fixtures`). Fixtures are never presented as ARUCAD data.

| # | Item | Where | Verify (on staging) |
|---|---|---|---|
| 1 | Office phones | Admin → Services → office → Phone | `ask:smoke` "fact: office phone" now checks the recorded phone is stated verbatim |
| 2 | Food venue → campus place | Admin → Food & Drink → venue → Campus place | "yemekhane nerede" → that place; a route question → that destination |
| 3 | Food venue weekly hours | Same form → Weekly opening hours | "yemekhane şu an açık mı", "yarın açık mı", "hafta sonu açık mı" answer from the rows |
| 4 | 2026–2027 academic year, active | Admin → Academic years | `ask:readiness` → `academic_year OK`; the stale-year data warning is gone |

No redeploy is needed after data entry: each value is read on the next question. PHPUnit `AicadCoverageClosureTest::test_staff_edits_in_the_admin_forms_reach_aicad_immediately` proves this for phone, hours, venue place, club Instagram and team place.

Record coverage before and after each item:
```
php artisan tinker --execute='$c=app(App\Services\Ai\AicadCoverage::class); echo json_encode($c->summary())." ".json_encode(array_filter($c->metrics()));'
```

## 2. Readiness
```
php artisan ask:readiness --live --probe-fallbacks --as=<staff e-mail> --metrics
```
Record each of these:
- `supportedfacts_mode` (must be ON)
- `campus_timezone`
- `queue`
- `ollama_model`
- `embedder`
- `retrieval`
- `programme_aliases`
- `campus_aliases`
- `programme_facts`
- `knowledge_corpus`
- `latest_evaluation`
- `academic_year`
- `feature_rollback`
- `known_data_gaps`

Any infrastructure `FAIL` (model, embedder, retrieval, queue, timezone) blocks the soak. `known_data_gaps WARN` is expected and must be understood, not hidden.

## 3. Evaluations (record every run id)
```
php artisan ask:evaluate --retrieval-only
AICAD_SUPPORTED_FACT_GENERATION_ENABLED=off php artisan ask:evaluate --full --as=<staff> --id=88 --id=89 --id=90 --id=91 --id=92 --id=93 --id=94 --id=99 --id=116 --id=117 --id=129
php artisan ask:evaluate --full --as=<staff> --tag=fact_generation
php artisan ask:evaluate --retrieval-only <fixture case ids>      # only if fixtures are installed (see AICAD_STAGING_PACKAGE.md §6)
php artisan ask:evaluate --compare=<baseline>,<new>
```
- The retrieval set covers phases 3A, 3B, 3C, 3C.1 and 4A–4C (tags `phase3a` … `phase4c`).
- The local baseline is in `docs/coverage/phase4b_baseline.json`, plus the 4D runs listed in its report.
- **0 unexplained newly failing cases.** Expectations are never weakened to pass.

## 4. Controller smoke pack (real HTTP)
```
php artisan ask:smoke --base=https://<staging-api>/api/v1 --as=<staff or approved test account>
```
- It sends 20 requests through auth, middleware and the controller, and covers:
  - Turkish, English, Russian and Turkish without diacritics
  - fast paths (menu, events) and structured facts (phone, language, duration, dates)
  - planning (office, club follow-up, open food place)
  - navigation with and without a location
  - temporal questions (now, tomorrow, weekend)
  - data gaps (club Instagram, club room, staff person)
- Expectations come from the server's own data: a phone or Instagram account must be stated only if one is recorded, and a named person must appear on an indexed official page.
- It refuses production, uses a token it creates and then revokes, and stores nothing.
- Never use real student accounts unless they are approved fixtures.

## 4b. Two-user privacy pack (mandatory)
```
php artisan aicad:staging-fixtures
php artisan ask:smoke --privacy --base=https://<staging-api>/api/v1
```
The pack covers four question types for each user: an identical personal question, personal clubs, mixed public and personal, and a personal follow-up. It also covers staff vs student, and a public question asked by two users. It fails when B or staff receive A's department marker, when a personal answer is served from cache, or when A's own answer doesn't carry A's data. That last check makes the leak check inconclusive rather than vacuously green.

**Local validation (2026-10-07, not staging):**
- No leak: B and staff never received A's data.
- Personal answers were never cached, and the public answer was served from cache for the second user.
- But A's own data didn't surface in 4 of 4 personal checks: the local model ignored or misattributed the personal block (see §10), so the pack is not green.

## 5. The soak
There is no fixed duration in code. Run until the window has exercised all of the following, with staff or test users:
- weekdays and a weekend
- tr, en and ru
- multi-task questions and follow-ups
- navigation
- document facts and structured facts
- partial data and unavailable data
- ambiguous questions

## 6. Metrics to watch (AICAD Health → SupportedFacts rollout, or `ask:readiness --metrics --json` → `rollout`)
Counters only. No raw prompt or answer is stored for telemetry.

| Metric | Source |
|---|---|
| Fact-path requests; COMPLETE / PARTIAL / UNAVAILABLE / FAILED | `counters.supported_facts_requests`, `outcome_*` |
| Path errors; technical (legacy) and deterministic fallbacks | `path_errors`, `path_error_legacy_fallbacks`, `path_error_deterministic_fallbacks`, `rates.path_error_rate`, `rates.technical_fallback_rate` |
| **Absence answers (no model call)** | `deterministic_absence_answers` (Phase 4D) |
| Unsupported / speculative sentences removed | `unsupported_claims_removed`, `speculative_claims_removed` |
| UNCERTAIN rate | `rates.uncertain_sentence_rate`, `rates.uncertain_response_rate`, `uncertain_by_language`, `uncertain_by_task_type` |
| Restatements, regenerations, withheld answers | `restatements`, `rates.regeneration_rate`, `rates.withheld_rate` |
| Model calls per request | `model_calls_per_request` |
| Latency | `latency_ms.median`, `latency_ms.p95` |
| DATA_UNAVAILABLE rate | `rates.data_unavailable_rate` |
| Citation integrity, task coverage | per evaluation run (`citation_invariant_violations`, `coverage_final`) |

## 7. Failure workflow
For each meaningful miss:
1. Find it in AICAD Health or the trace.
2. Reproduce it in the Ask Playground.
3. Classify the failing layer: **PLANNER · ENTITY · PROVIDER · EVIDENCE · FACT EXTRACTION · FACT VALIDATION · GENERATION · CLAIM VERIFICATION · DATA QUALITY · CONTENT/SOURCE · MODEL VARIANCE · OPERATIONS**.
4. Sanitize the wording and save it as an evaluation case.
5. Fix the layer it belongs to. Don't paper over a miss with another regex.
6. Rerun the focused tests, then the regression suite.

The 4D local smoke run is the worked example: an unqualified "açık" claim (CLAIM VERIFICATION), an all-unavailable plan sent to the model (GENERATION policy), the club name "Fotoğraf" read as a document (CLAIM VERIFICATION), and stray CJK text (GENERATION, caught by the verifier).

## 8. Priority-B data (after the soak starts; don't block on it)
Club Instagram, club e-mail and club meeting place (Admin → Clubs → Contact & room), then team → campus place (Admin → Sports; create the Place first). After each batch, record the coverage summary (§1), `rates.data_unavailable_rate`, task coverage and the COMPLETE share.

## 9. Production STAFF_ONLY gate (decide; do NOT enable)
Recommend `READY_FOR_PRODUCTION_STAFF_ONLY` only if **every** line holds on staging:

| Criterion | How to check |
|---|---|
| 0 unexplained regressions | `ask:evaluate --compare` |
| Full labelled suite stable across ≥ 2 runs | retrieval, legacy, fact_generation |
| No serious path errors | `rates.path_error_rate` ≈ 0 |
| Timezone confirmed | readiness `campus_timezone` |
| Alias seeders confirmed | readiness `programme_aliases`, `campus_aliases` OK |
| Academic year corrected | readiness `academic_year` OK |
| Privacy cache tests green | `php artisan test --filter=AiPersonalAnswerCacheTest` on the staging build |
| Rollback verified | set `off`, `config:cache`, `queue:restart`, then a planned question goes to the legacy path; set back to `on` |
| No recurring unsupported-claim escape | smoke pack plus soak review; `unsupported_claims_removed` explained |
| No data-unavailable request reaching legacy generation | `deterministic_absence_answers` counts them; UNAVAILABLE plans show `aiMode=deterministic` |
| Task coverage high; citation integrity perfect | evaluation runs |
| p95 acceptable vs legacy | `latency_ms.p95` vs the legacy full-answer p95 |

Otherwise: `KEEP_IN_STAGING`.

## 10. Known blocker found by the local HTTP smoke (2026-10-07)
**Personal answers don't surface the student's own data**, so the privacy smoke can't go green, and this blocks the STAFF_ONLY gate:

| Question | Path | What happened | Layer |
|---|---|---|---|
| "profilim ne durumda?" | legacy model (personal block present, never cached) | "Please confirm you are logged in"; the personal block was ignored | GENERATION |
| "bölümüm ne?" → "peki seviyem kaç?" | legacy model | Stated department "Plastik Sanatlar, 1. yıl": wrong, taken from retrieved programme pages instead of the personal block. AnswerGrounding can't catch it, because the name is in the prompt | GENERATION |
| "kulüplerim neler ve bölümüm ne?", "bugün yemekte ne var ve bölümüm ne?" | operational path | Public club list / food list; the personal part was dropped | ROUTING (operational claims personal questions) |
| "hangi kulüplerdeyim?" | operational path | Not recognised as personal (`PersonalContext::isRelevant` only knows "kulübüm/kulüplerim") | PLANNER/vocabulary |

**Proposed smallest fix** (not implemented in 4E, which forbids AI-path changes without a staging-proven failure; needs approval):
1. A deterministic `DirectAnswer` for profile, department, year, level and club-membership questions from `PersonalContext`, like the existing appointment answer. No model call, never cached.
2. The operational path doesn't claim a question that `PersonalContext` marks personal.
3. Extend the personal vocabulary for membership questions ("hangi kulüplerdeyim").

Each needs a sanitized evaluation case and a PHPUnit regression, and the privacy pack must be green afterwards.

