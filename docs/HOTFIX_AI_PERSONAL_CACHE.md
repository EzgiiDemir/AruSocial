# Hotfix: cross-user caching of personalized AI answers

Patch: `docs/patches/hotfix-ai-personal-answer-cache.patch`, applied to the
working tree. The Ask ARUCAD AI stack (`AiResponder`, `PersonalContext`,
`AskPromptBuilder` and the provider layer) is **not committed** in git yet.
The patch therefore targets the current working-tree code; it does not apply
to any commit.

## Root cause

`AiController` passed a cache basis of *the question text alone* for every
single-turn question. `AiResponder` keyed the shared answer cache on that
basis plus retrieval stamps, with no privacy scope. A signed-in student's
personal question ("profilim ne durumda?") was answered with their
PersonalContext in the prompt, then stored under that key. The next student
asking the same words was served it from cache.

This was reproduced: student B received student A's department and level.

## Fix

| Layer | Change |
|---|---|
| `AiResponder::generate` | `$privacy->carriesPersonalData` means **no cache key**, so no cache read and no write, whatever the caller passed. An unclassified call is treated as personal, as `AiResponder` already documents. |
| `AiResponder::cacheKey` | Namespace bumped to `ai:answer:v2:`. Every entry written before the fix is unreachable, and nothing else is flushed. |
| `AiController` | The cache basis is null for a personal question. The cache-acceptance callback now captures the built prompt by reference: a payload that turned out to carry personal data is never stored. |

The old callback was an arrow function, which captured `$systemPrompt` as
null, so its "never cache an ungrounded answer" check never ran. It runs now.

## Paths audited

- **PersonalContext:** the prompt includes it only when `isRelevant()` matches
  the same query the controller uses to set privacy, so they are consistent.
  The by-reference callback is a second guard.
- **Mixed public + personal** ("burs … ve kulüplerim"): personal, so not
  cached. Tested.
- **Follow-ups** ("nerede?" after a personal question): multi-turn
  conversations are never cached (`$isSingleTurn`). Tested.
- **DirectAnswer** (authenticated "my next appointment") and **AskOperations**
  return directly and never pass through the answer cache.
- **Role cohorts:** no non-personal prompt content depends on the user's role;
  `$me` reaches the prompt only through PersonalContext. A staff member's and
  a student's personal answers never cross. Tested.
- **Fact path (staff_only):** planned questions are not cached; fact-generated
  answers are never cached.
- **Logs and trace:** unchanged. The `ai.answer` log line has no question or
  answer; no new logging was added.

Observation, not a privacy issue: "bugün hangi etkinlikler var ve randevum ne
zaman?" is answered by the operational events fast path. That path drops the
personal half, and it is never cached.

## Tests

`tests/Feature/AiPersonalAnswerCacheTest.php` (7 tests) covers:
- identical personal questions answered per user
- an old-namespace entry, and a planted current-key entry, never read
- a mixed question not shared
- a follow-up never cached
- a public question still cached across users
- staff and student never sharing
- an unclassified call never cached

Two existing tests now declare their public question explicitly
(`AiCapacityTest`, `AicadStabilizationTest`).

## Deploy

1. Deploy the code (`DEPLOYMENT.md` §3). There is no migration and no config
   change.
2. **No cache flush is required.** The `v2` namespace makes all earlier
   entries unreachable, and they expire on their own (TTL
   `AI_CACHE_TTL_MINUTES`, default 360; the key also changes at midnight).
   Do not run `cache:clear` for this; it would drop unrelated caches.
3. `php artisan config:cache`, then `php artisan queue:restart` (standard).

**Smoke test** (two approved fixture students):
1. Both ask "profilim ne durumda?". Each sees their own data, and neither
   response's `aiMode` is `cached`.
2. Ask a public question twice (for example "kütüphane kaçta kapanır"). The
   second response's `aiMode` is `cached`.

**Rollback:** do not roll back to the pre-fix code; that reopens the leak. If
the fix itself misbehaves, set `AI_CACHE_ENABLED=false` (disabling the answer
cache is safe; only cost and latency rise) while it is investigated.

## Can past exposure be determined?

**Not from the privacy-safe logs.** The `ai.answer` log line records
`mode: cached` but not the question. On a cache hit no prompt is built, so it
logs `personal_context: false`, and a leaked personal hit is indistinguishable
from a public one.

**Whether production ran this code is not known here.** HEAD's `AiController`
calls Groq directly with no answer cache. The cache exists only in the
uncommitted working tree, so exposure is possible only if that code was
deployed outside git. Please confirm how production was deployed.

**The only evidence that could decide it** is the stored conversations
(`ask_messages`, which hold personal content). One query could count pairs of
different users who asked an identical PersonalContext-keyword question within
the cache TTL and received an identical assistant answer. Running it means
reading personal data, so it needs your data-protection approval; it has not
been run. No new logging of personal content was added.
