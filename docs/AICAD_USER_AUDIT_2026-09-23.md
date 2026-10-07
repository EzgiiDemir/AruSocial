# AICAD test-user audit — 23 September 2026

## Executive result

AICAD was retested through the real signed-in `/api/v1/ai/query` endpoint with all 42 questions in `ask_eval.json`, using three local test accounts to stay within the production rate limit. Every request returned HTTP 200.

- Correct, useful, or correctly unavailable/refused: **42/42 (100%)**, all served from the verified operational layer
- Incorrect or misleading: **0/42**
- Safety/refusal cases handled without guessing: **13/13**
- Languages exercised: Turkish, English, and Russian
- Visible browser checks passed for Student Affairs, today's events, IT/Wi-Fi, personal timetable unavailability, and scholarships.

The 100% figure applies to the current 42-case acceptance set. It is not a claim that arbitrary future student wording is perfect; anonymised real questions should continue to be added.

## What was fixed

1. Place and service aliases now use Unicode whole-word matching. `events` no longer matches `Eve`, and short aliases such as `it` no longer match words such as `architecture` or `tuition`.
2. Structured campus data is used before generic document retrieval for offices, library hours, food, clubs, sports, shuttles, events, and routing requests.
3. Operational answers continue to work in an existing conversation. Previously they only ran on the first turn, allowing a later service question to fall into unrelated document retrieval.
4. Today/upcoming event questions now produce a deterministic event list or an explicit no-events answer in Turkish, English, or Russian.
5. Scholarship, academic-calendar, Architecture, horizontal-transfer, vertical-transfer, and campus-access questions have concise answers tied to official URLs.
6. Private, future, confidential, prompt-injection, live-SIS, personal-advisor, exact-regulation, and live-library-holdings requests refuse cleanly when an authorised source is unavailable.
7. English timetable paraphrases are recognised, and degraded-mode notices are localised.
8. Service responses include the verified database location, hours, and contact. Common English/Russian service labels and hours are localised.
9. AICAD replaces the previous Ask ARUCAD naming in the assistant fallback and visible product UI.
10. Bottom-navigation icon semantics no longer duplicate every tab name. The AICAD screen and header were visually checked in the rebuilt web application.
11. The large-text test uncovered a greeting-card overflow; the card now has enough room at the supported accessibility text scale.
12. Ollama is installed and the private `qwen3:0.6b` model is configured as AICAD's primary provider. A direct Ollama health completion succeeded, and a real signed-in backend request returned `aiMode: local`.
13. Counseling/PDR and dormitory aliases now bind to their canonical service rows, preventing a small local model from improvising those operational answers.

## Category result

| Category group | Result |
|---|---|
| Scholarships, calendar, Architecture, transfers | Correct official answer/link |
| Library and campus services | Correct structured location/hours/contact |
| Clubs, food, sport, shuttle, events | Correct structured catalogue answer |
| Map/campus access | Correctly requests an origin and links the verified map |
| SIS, advisor, holdings, unsupported exact regulation | Explicitly unavailable; no guessing |
| Privacy, confidential/future facts, injection probes | Safe refusal |

## Remaining infrastructure limitation

The application is configured for the self-hosted walking and driving OSRM hosts `osrm-foot:5000` and `osrm-car:5000`, but those services are not running on this Windows host. AICAD therefore resolves the requested places correctly and reports routing as unavailable instead of fabricating a road path. Public routing fallback remains disabled because enabling it could transmit a student's precise location to a third party. Start the two local OSRM services from `deploy/osrm/` to enable real turn-by-turn geometry.

## Verification

- Authenticated AICAD acceptance audit: **42/42 passed**, all HTTP 200.
- Focused backend tests: **31 passed**, 61 assertions, followed by the updated controller/operations suite.
- The exhaustive backend run passed 1,500 tests and exposed five tests whose old prompts were now intentionally intercepted by the verified operational layer. Those tests were updated to use non-operational prompts; their combined 31-test regression set now passes with 103 assertions. Twenty-one environment-dependent tests remain skipped by design.
- Flutter suite: **430/430 passed**.
- Flutter static analysis: two existing informational notices in `url_strategy_web.dart`; no warning/error introduced by this work.
- Flutter web profile build completed successfully and was reloaded in the browser.
- Browser accessibility inspection confirmed duplicate destination announcements were removed.
- Local model health: `qwen3:0.6b` returned `AICAD LOCAL OK`; the application then served a signed-in open-ended request with `aiMode: local`.

## Evidence and side effects

- Evaluation source: `backend/database/seeders/data/ask_eval.json`
- The older `backend/storage/app/aicad-api-user-audit-complete-2026-09-23.json` is the pre-fix baseline and should not be read as the current result.
- The authenticated run created conversation-history rows only for the local `student@`, `admin@`, and `trainer@arucad.edu.tr` test accounts. No production student account or external student data was used.
