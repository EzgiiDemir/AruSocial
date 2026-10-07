# Ask ARUVERSE — making it stronger, and surviving the whole campus

Written for: the ARUVERSE product owner and engineering team.

Two questions this answers:

1. The assistant is not good enough. Free Groq is a shared ceiling and a
   local Ollama would eat the box that also runs moderation. What else?
2. A lot of students will use this at once. How do we not fall over?

They have the same answer more often than you would expect: **the quality
problem is a retrieval problem, not a model problem, and retrieval is also
the cheap path under load.** A bigger model answering from the wrong three
paragraphs is still wrong, and it costs more per student.

Section 6 is the ordered work. Much of it has since been built — see the
**Implementation report** at the end for what landed, what it measured,
and what it did not fix. The plan itself is left as it was written, so
the reasoning can be checked against the result.

---

## 1. What already exists

Worth saying plainly, because the plan builds on it rather than replacing
it, and because several things people usually ask for are already here.

| Capability | Where |
|---|---|
| Provider abstraction, OpenAI-compatible | `config/ai.php`, `app/Services/Ai/*` |
| Groq primary, optional local fallback, deterministic last resort | `AiResponder` |
| Circuit breaker (opens instantly on quota, 15 min cooldown) | `AiCircuitBreaker` |
| Answer cache, 6 h, single-turn questions only | `AiResponder`, `ai.cache` |
| Context ceiling, 6 000 chars handed to the model | `ai.context_budget_chars` |
| Grounding check — invented links/figures are rejected and retried | `AnswerGrounding` |
| Knowledge-only mode — answers from ARUCAD sources with no LLM at all | `CampusAskFallback` |
| Site crawler, twice daily, 6 allow-listed public domains | `SiteKnowledgeCrawler` |
| Read-only agent with a fixed tool table | `AruverseAgent` |
| Per-user rate limit, 20 AI requests/minute | `AppServiceProvider` |

That is a good skeleton. What is missing is not resilience — it is **what
the assistant knows** and **what bounds the whole system rather than one
user**.

---

## 2. Why the assistant feels weak

Four specific causes, all fixable without a larger model.

### 2.1 Retrieval is keyword overlap

`KnowledgeBase::relevant()` scores documents by counting how often a
stemmed query term appears in the body, with bonuses for title, URL path,
language and freshness. It says so in its own docstring, and the reasoning
was sound at the time: no embedding model was available without adding an
external dependency.

That is no longer true. **The moderation classifier already holds a
multilingual sentence-embedding model in memory** —
`sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, loaded at
`:8801` for the text-moderation margins. It is resident, pinned, CPU-only,
27 ms per text, and it speaks Turkish, English and Russian.

So semantic retrieval costs us one new endpoint on a service that is
already running. It is the single largest quality gain available here, and
it closes the item left open in
`docs/AI_KNOWLEDGE_AND_SELFHOSTING_TODO.md` §4b.

### 2.2 The corpus is small and capped

`KNOWLEDGE_MAX_PAGES` is 120 and the crawl covers the public marketing
sites. A student asking about a specific course, a form, or an office
procedure is asking about a page that may not be in the index at all.

### 2.3 The agent knows the campus but not the student

`AruverseAgent::tools()` exposes ten tools: knowledge, places, events,
clubs, sports, services, food, shuttle, directory, staff. Every one of
them is **global** campus data.

There is no tool for *this student's* schedule, appointments, club
memberships, saved places or XP. So "when is my next appointment", "which
of my clubs meets today", "am I registered for that event" cannot be
answered, and those are exactly the questions that make an assistant feel
personal. This is a deliberate-looking gap rather than a designed one, and
it is the second-largest quality gain.

### 2.4 Navigation answers are structurally unavailable

`ROUTING_BASE_URL` is empty, so `POST /routing/directions` returns
**501**. Ask ARUVERSE can tell a student what a building is and where it
sits; it cannot say how to walk there. Until an OSRM-compatible endpoint
exists (`deploy/osrm/`), no amount of model quality changes that answer.

---

## 3. Making it stronger — three tiers, cheapest first

The architecture is already tiered; this sharpens each tier.

### Tier 0 — answer without an LLM where the answer is a fact

Menu hours, shuttle times, an office's location, a deadline: these have
one correct answer that lives in our own database. Every one served from a
template is a question that costs no tokens, cannot hallucinate, and
answers in milliseconds under any load.

`CampusAskFallback` already does this as a *degraded* mode. Promote it: for
a matched intent, answer from data first and use the LLM only to phrase
it — or not at all.

**Expected effect:** the most-asked questions stop reaching the provider.
On a campus, question frequency is extremely top-heavy.

### Tier 1 — semantic retrieval on the model we already run

1. Add `POST /v1/embed` to the classifier service, returning the mean-pooled
   MiniLM vector. Same model object, same process, no new weights, no new
   RAM.
2. Store a vector per knowledge document (a `float[384]` as JSON or
   `bytea`; 120–1 000 documents does not need pgvector).
3. Retrieval becomes: embed the question once (~30 ms), cosine against the
   stored vectors, keep the keyword score as a secondary signal (hybrid
   beats either alone on short queries).
4. Cache the query vector alongside the answer cache.

This also fixes a load problem described in §4.3: today retrieval loads
every document *with its full text* into PHP on every request.

### Tier 2 — the LLM, on grounded context only

Unchanged from today's design: the model phrases an answer from retrieved
sources and is checked by `AnswerGrounding`. With Tiers 0 and 1 doing more
work, a smaller and cheaper model is sufficient here — which is what makes
§5 affordable.

### Also in scope

- **Personal tools for the agent** (§2.3), each scoped to
  `$request->user()->id` at the query, never by a model-supplied id. The
  agent is a read-only intent router; that property must survive.
- **Corpus expansion** (§2.2): raise `max_pages`, add seeds. Public pages
  only — `sis.arucad.edu.tr` stays out of the allow-list.
- **Routing** (§2.4): stand up OSRM or accept that navigation answers stay
  at 501.

---

## 4. What will actually break under load

Four structural findings from reading the code. The first is the one that
worries me.

### 4.1 The classifier serialises every request

In `image-moderation-service/app/main.py`, both `/v1/moderate/text` and
`/v1/moderate/image` are declared `async def` and then call blocking
PyTorch inference directly. In FastAPI an `async def` handler runs **on the
event loop**, so a blocking call inside it stops the loop: requests are
served one at a time, and the second request's latency is the first one's
latency plus its own.

This matters because **moderation is synchronous on the request path**.
Every chat message, post, comment, review and profile edit waits for this
service. At 27 ms per text one worker tops out near 35 texts/second in the
best case, and each image is 100–220 ms on top of that.

Fix, in order of effort:

1. Declare the handlers `def` instead of `async def` — FastAPI then runs
   them in its threadpool and PyTorch releases the GIL during inference.
   One-line change, largest single win.
2. Run uvicorn with `--workers N` (each worker is a full copy of the
   weights, so N is bounded by RAM: roughly 1.4 GB of weights plus runtime
   per worker).
3. Pin `torch.set_num_threads()` so workers do not fight over cores.
4. Bound the queue and return 503 rather than accumulating latency —
   Laravel already fails closed on 503, which is the correct behaviour.

### 4.2 Nothing bounds AI usage globally

The `ai` limiter is 20 requests per minute **per user**. There is no
global cap. Five hundred students at that limit is 10 000 requests per
minute aimed at one API key. The circuit breaker is the only thing that
reacts, and it reacts *after* the provider has already started refusing —
which is a reactive control, not a budget.

Needed: a global concurrency limit (how many AI calls may be in flight at
once) and a daily token/request budget, both degrading to knowledge-only
mode instead of erroring. The degraded path already exists and is already
labelled to the student.

### 4.3 Retrieval reads the whole corpus per request

`KnowledgeBase::relevant()` runs `KnowledgeDocument::query()->get()` — all
documents, full body text, into PHP memory, scored in a loop, on every AI
request that reaches Tier 2. At 120 pages that is tolerable; it is also
exactly the thing that stops being tolerable when the corpus grows (§2.2)
and the traffic grows at the same time. Tier 1 replaces it with a vector
comparison over a small numeric column.

### 4.4 Cache, queue, sessions and rate limits all sit on Postgres

`CACHE_STORE=database`, `QUEUE_CONNECTION=database`,
`SESSION_DRIVER=database` in the production template. The rate limiter
writes to the cache store on **every single request**, so at peak the
database takes a write per request before any real work happens.

This is a defensible default at small scale and a poor one at campus
scale. Redis on the same host removes it for a small amount of RAM, and
the application supports it by configuration alone — no code change.

### Also worth knowing

- Image moderation is synchronous (`IMAGE_MODERATION_ASYNC=false`). That
  is the right default today, and the config comment names the exact
  condition to flip it: tens of uploads per minute, and a supervised queue
  worker. Both become true at campus scale.
- Two student screens poll: appointment booking every 15 s, the shuttle
  sheet every 30 s. Cheap per request, but they are floor traffic that
  never stops while a screen is open.
- Reverb is a single long-lived process holding every websocket. Its
  connection ceiling has never been measured here, and it currently has no
  nginx route at all (see `docs/SERVER_SUPPORT_REQUEST_ARUVERSE.md` §9).

---

## 5. If free Groq is not the answer

Four options, with the reasoning rather than a recommendation dressed as a
fact. Every one of them is a configuration change, because the provider
layer already speaks the OpenAI-compatible contract.

**The arithmetic first.** One Ask question costs roughly 1 800 input tokens
(the 6 000-char grounding budget) plus ~300 output tokens. So:

```
6 000 questions/day  ≈ 11M input + 1.8M output tokens/day
                     ≈ 330M input + 54M output tokens/month
```

Tier 0 and the answer cache remove a large share of those before they are
sent. At the order of magnitude small open models are priced at (cents to
a dollar per million tokens — **verify current prices, do not trust this
sentence**), that is tens of dollars a month, not thousands.

| Option | What it costs | What it buys | Honest drawback |
|---|---|---|---|
| **A. Groq, paid tier** | One env var. Per-token billing. | Same code, same models, the rate ceiling moves | Still an external dependency; content leaves ARUCAD |
| **B. Another hosted OpenAI-compatible provider** | `GROQ_BASE_URL` + key, or a new provider class | Azure OpenAI is notable: ARUCAD already has a Microsoft tenant, so procurement and data handling may already be settled | Same dependency question, different vendor |
| **C. Self-hosted small model on its own GPU host** | A GPU box (24 GB class) + vLLM; `LOCAL_AI_BASE_URL` | Nothing leaves ARUCAD; fixed cost, no per-question price | Not on the app server — CPU inference of a 7B model is too slow to be usable, which is the concern that started this. It is a second machine, not a second process |
| **D. No LLM** | Nothing | Knowledge-only mode already works, is grounded, cited, and never hallucinates | Answers read like a search result, not a conversation |

**My recommendation:** A, plus the Tier 0/1 work, and keep D as the
permanent degraded mode it already is. The reason is not that A is
elegant — it is that the per-question cost after caching is small enough
that buying a GPU to avoid it is paying capital to solve a problem that
tens of dollars a month solves, while adding a machine to operate. C
becomes the right answer when ARUCAD decides student questions may not
leave the institution; that is a policy decision, not an engineering one,
and the code is already ready for it.

---

## 6. The work, in order

Effort figures are rough and assume one engineer.

### P0 — before a real student load

| # | Work | Effort | Why now |
|---|---|---|---|
| 1 | Classifier handlers `def` + workers + thread pinning + bounded queue (§4.1) | 0.5 day | Single biggest crash risk; moderation is on the request path |
| 2 | Global AI concurrency + daily budget, degrading to knowledge-only (§4.2) | 1 day | One key, one ceiling, no bound today |
| 3 | Redis for cache, sessions and the rate limiter (§4.4) | 0.5 day | Removes a DB write per request |
| 4 | Load test with real scenarios and written acceptance thresholds (§7) | 2 days | Everything above is a hypothesis until measured |
| 5 | Decide and configure the AI provider (§5) | 0.5 day + procurement | Free-tier limits are a launch blocker |
| 6 | Reverb nginx route + a measured connection ceiling | 0.5 day | Realtime currently cannot connect at all |

### P1 — quality, within the first weeks

| # | Work | Effort |
|---|---|---|
| 7 | `/v1/embed` on the classifier + stored vectors + hybrid retrieval (§3 Tier 1) | 2–3 days |
| 8 | Tier 0 deterministic answers for the top question intents | 2 days |
| 9 | Personal agent tools, scoped to the authenticated user (§2.3) | 2 days |
| 10 | Corpus expansion: raise `max_pages`, add seeds, verify the allow-list | 0.5 day |
| 11 | Semantic answer cache (cache on meaning, not exact string) | 1 day |

### P2 — when the numbers say so

| # | Work | Trigger |
|---|---|---|
| 12 | Move the classifier to its own host | Its CPU stays above ~60% at peak |
| 13 | `IMAGE_MODERATION_ASYNC=true` | Sustained tens of uploads per minute, worker supervised |
| 14 | CDN or nginx-level caching for media and the web bundle | Bandwidth, not CPU, becomes the limit |
| 15 | Postgres read replica / pgbouncer | Connection count approaches `max_connections` |
| 16 | OSRM for navigation (§2.4) | Product decision |

---

## 7. How to know it works — the load test

No capacity claim in this document is measured. Before launch, run these
and write the numbers down.

Model the load rather than guessing a number:

```
peak RPS ≈ students × daily-active-share × peak-hour-share
           × requests-per-session ÷ 3600
```

Scenarios to run against a staging copy:

| Scenario | What it stresses |
|---|---|
| Feed browsing, 200 concurrent users | PHP-FPM, Postgres reads, the N+1s nobody has audited |
| Chat, 50 messages/second | The classifier (§4.1), Reverb, the DB write path |
| 20 image uploads/minute | Classifier image path, disk, the 12 MB limit |
| 100 Ask questions/minute | Cache hit rate, the global budget (§4.2), provider latency |
| All four at once | The interaction, which is where real outages live |

Acceptance thresholds worth committing to before the run, so the result
cannot be rationalised afterwards: p95 API latency under 500 ms, p95 chat
send under 1 s, zero 5xx under nominal peak, and a clean degradation —
not an error page — at 2× peak.

---

## 8. What this plan does not solve

- **Moderation recall is 55% on unseen text** (`docs/MODERATION_V4.md`).
  More traffic means proportionally more that gets through. Nothing here
  changes that; it is a model-capability limit, and the human queue
  (`docs/MODERATION_RUNBOOK.md`) is the mitigation.
- **Two trained moderators** is the documented minimum. Volume scales with
  students; the queue does not drain itself.
- **Grounding is a check, not a guarantee.** `AnswerGrounding` catches
  invented links, addresses and figures. It cannot catch a fluent, wrong
  paraphrase of a real page.
- **No numbers here are measured.** Every latency and every cost in this
  document is an estimate from reading the code, and item 4 exists to
  replace them with real ones.

---

# Implementation report — 21 September 2026

What of the plan above is now built, what it measured, and what it did
not fix. Everything here is in the tree and covered by tests;
`docs/AI_AND_SCALE_PLAN.md` above remains the plan, this is the record.

## Built

| # | Item | Where |
|---|---|---|
| 1 | Classifier handlers no longer block the event loop | `image-moderation-service/app/main.py` |
| 2 | `/v1/embed` on the classifier, from the model already resident | same |
| 7 | Passage index + hybrid (keyword + semantic) retrieval | `KnowledgeChunk`, `KnowledgeIndexer`, `KnowledgeBase` |
| — | Site-chrome removal from crawled pages | `BoilerplateFilter` |
| — | Diacritic-insensitive matching, TR/EN/RU term expansion | `TextFold`, `CampusVocabulary` |
| 9 | Personal context for the signed-in student | `PersonalContext` |
| — | Time-aware events, ranked places with real location detail | `AruverseAgent` |
| 8 | Tier 0: campus lookups answered with no model call at all | `DirectAnswer` |
| 2 | Campus-wide concurrency + daily budget, degrading to knowledge-only | `AiBudget`, `AiResponder` |
| — | Capacity and degradation state on `/api/v1/health` | `HealthController` |

## Measured

**Classifier concurrency.** Eight concurrent text-moderation calls on the
same machine, before and after making the handlers synchronous so FastAPI
runs them in its threadpool:

| | total | slowest request |
|---|---|---|
| `async def` (on the event loop) | 0.29 s | 0.28 s |
| `def` (threadpool) | **0.08 s** | **0.08 s** |

Moderation sits on the request path of every post and message, so the
slowest-request figure is what a student feels.

**Classifier capacity.** The saturation curve, one uvicorn worker on a
20-core development machine, measured 21 September 2026:

| workload | concurrency | req/s | median | p95 |
|---|---|---|---|---|
| text moderation | 1 | 74.8 | 13 ms | 16 ms |
| text moderation | 2 | **102.9** | 19 ms | 20 ms |
| text moderation | 4 | 83.3 | 39 ms | 79 ms |
| text moderation | 8 | 72.6 | 102 ms | 144 ms |
| text moderation | 16 | 74.3 | 217 ms | 257 ms |
| text moderation | 32 | 57.4 | 545 ms | 651 ms |
| embeddings | 4 | **79.4** | 47 ms | 63 ms |
| embeddings | 32 | 57.4 | 503 ms | 796 ms |

Read the shape, not the peak. Throughput is flat from concurrency 2 to
16 while latency rises almost exactly in proportion — the signature of a
saturated service. Past about 4 concurrent requests nothing is gained
and everything waits longer.

Two things follow. **More uvicorn workers will not help on a machine
like this**: PyTorch already spreads one request across the cores, so a
second worker competes with the first rather than adding capacity. Only
set `torch_threads` (to cores ÷ workers) if you do run several. And
**the classifier is not the thing to worry about at campus scale**: 75
texts/second is 270,000 moderated items an hour, which a 3,000-student
campus would have to sustain at 90 posts and messages *per second* to
exhaust — roughly one message per student every thirty seconds, without
pause.

This measures the local bottleneck on a laptop, and says nothing about
`AI_MAX_CONCURRENT`, which bounds outbound calls to a provider whose own
limits are not measured here. That number remains a starting point.

**Per-request cost of the assistant**, measured against the real
75-page / 390-passage corpus, 21 September 2026:

| component | median | max |
|---|---|---|
| Retrieval (embed call + scan of every passage) | 42.5 ms | 60.6 ms |
| Agent context (all selected tools, includes retrieval) | 41.2 ms | 83.0 ms |
| Personal context (the student's own rows) | 2.5 ms | 2.8 ms |
| **Direct answer (no model, no retrieval)** | **1.4 ms** | 1.5 ms |

Sequential, and deliberately so: this machine has no php-fpm and PHP's
built-in server is single-threaded on Windows, so a concurrency test
here would have measured the dev server rather than anything real. What
one request costs is the input the arithmetic actually needs.

**What that means for 3,000 students.** A retrieval question costs about
42 ms of our own CPU before any model call, so one PHP worker serves
roughly 24 of them a second and four workers roughly 95. If every one of
3,000 students asked a question in the same minute — 50 questions a
second, which is far beyond realistic — it would occupy about two
workers. A direct answer costs 1.4 ms, thirty times less, and most
questions are direct answers. The per-user throttle (20/minute) caps the
worst a single account can do.

So **we are not the constraint; the provider is** — which is exactly why
the direct-answer path, the cache and the budget exist.

**One number to watch as the corpus grows.** Of that 42 ms, roughly 19
is the embedding call and 23 is scanning 390 passages in PHP — about 60
microseconds each. At 5,000 passages the same scan would cost ~320 ms,
which is the point where the vector index this plan calls premature
stops being premature. Watch `assistant.indexedPassages` on
`/admin/system-health`.

**Embedding separation**, measured on the real model over campus
questions: same meaning 0.60-0.70, the same question across languages
0.51-0.64, unrelated 0.21. The populations overlap in the middle (a
loosely related pair reached 0.39 while a true cross-lingual match
scored 0.37), which is why the similarity floor is 0.25 — low enough to
drop noise, not high enough to pretend it can decide relevance on its
own.

**Retrieval, before and after**, against the real 78-page corpus:

| Question | Before | After |
|---|---|---|
| `kutuphane` (no diacritics) | Dikey Geçiş Sınavı | **Kütüphane** |
| `library opening hours` | Prospective ARUCAD | **Library + Kütüphane** |
| `What scholarships are available?` | Prospective ARUCAD | **Burslar ve Ücretler** |
| `Какие есть стипендии?` | Uluslararası Olanaklar | **Burslar ve Ücretler** |
| `academic calendar` | — | **Lisans Akademik Takvim** |
| `mimarlık bölümü hakkında bilgi` | Endüstriyel Tasarım | **Mimarlık** |

## Does it answer correctly? A number, not an opinion

`php artisan ask:benchmark` runs 30 labelled campus questions across
Turkish, English and Russian and checks whether the page that answers
each one is in the top 3 results. Against the live corpus:

```
Corpus: 75 pages, 390 passages, semantic ON
retrieval@3: 30/30 (100.0%)
  tr 22/22   en 6/6   ru 2/2
```

This measures the half that can be measured. A model given the right
page can still phrase it badly; a model given the wrong page cannot be
right except by luck, so retrieval is what decides whether the rest has
a chance. The command prints its own caveat, which is the honest one:
the set was written against this corpus by the same person who tuned
retrieval, so it shows that known topics are reachable — not how the
assistant handles a question nobody anticipated. A set drawn from real
student questions is what would make it trustworthy.

Two cases scored as misses on the first run and were not: the matcher
used `mb_stripos`, and Turkish dotted capital `İ` does not case-fold to
`i` in PHP, so "İletişim - ARUCAD" could not be recognised as the page
it plainly was. Fixed by folding both sides. A measurement that cannot
read its own results is worse than none.

## The fast path, and what it refuses

`DirectAnswer` answers four question shapes straight from our own
tables, in the language they were asked in, with no model call: where a
place is, what is on today, canteen hours, and the student's own next
appointment. On a campus these are a large share of the traffic, they
each have exactly one correct answer, and a looked-up fact cannot be
hallucinated.

What keeps it from turning the assistant into a vending machine is what
it declines — which is most things:

| Question | Path | Why |
|---|---|---|
| "Meditation nerede?" | direct | one fact, one entity |
| "Rodin nerede?" (no address or description held) | model | knowing the name is not knowing the answer |
| "Meditation nerede, nasıl giderim, kaça kadar açık?" | model | a second clause is a conversation |
| "Hangi bölümü seçmeliyim?" | model | advice, not a lookup |
| "çok kötüyüm, kimse beni istemiyor" | support path | never a catalogue row |

The third row is the one that was measured wrong first: with neither an
address nor a description the fast path produced "Kütüphane is on
campus", which is confident, instant and useless. It now declines and
lets retrieval try.

## Four bugs the real data found

Worth recording, because none would have shown up against fixtures.

1. **Unbounded keyword counts.** A long page saying "student" thirty
   times outscored the page about enrolment. Term contributions now
   saturate.
2. **Site chrome swamping the index.** Every page's opening passage was
   the same menu, so every embedding of it was nearly identical.
   Removing it is what made `kutuphane` findable.
3. **Invalid UTF-8 in crawled pages.** One stray byte made `json_encode`
   refuse a whole batch, and the failure cooldown then skipped every
   remaining page — 52 of 78 pages silently unindexed.
4. **`str_split` cutting a character in half.** Splitting an over-long
   sentence on bytes produced text Postgres rejected outright. The
   sanitising upstream could not catch it, because the breakage happened
   after it.

## Not fixed, and why

- **The model does not translate.** "What scholarships are available?"
  scores **0.115** against the Turkish title "Burslar ve Ücretler" —
  below three unrelated pages. Cross-language retrieval therefore rests
  on `CampusVocabulary`, a fixed list of about fifty campus topics in
  three languages. It is deterministic and free; it is also only as good
  as its entries, and a topic nobody added will not bridge. Translating
  the question with the LLM would fix it properly and would cost a model
  call per question, which is the opposite of the goal.
- **Corpus coverage is now the limit, not retrieval.** "yurt ve
  konaklama" returns the wrong page because the corpus has no
  accommodation page — only passing mentions. That is a crawl seed to
  add (`config/knowledge.php`), not a ranking problem.
- **`AI_MAX_CONCURRENT=8` is still a starting point.** The load test
  above measured the *classifier* — the local bottleneck — and found it
  comfortable at campus scale. It did not measure Groq, whose own
  concurrency limits decide this number, nor PHP-FPM, nor Postgres under
  a real mixed workload. Item 4 of the plan (the full-stack load test
  with acceptance thresholds) still stands.
- **Items 3, 5, 6 of P0 remain open**: Redis for cache/sessions/rate
  limiting, the provider decision, and the Reverb nginx route.

## Running it

```bash
# Once, after deploying: index whatever is already crawled.
php artisan knowledge:embed --all

# Thereafter the crawl indexes changed pages itself, and the scheduler
# catches anything missed while the classifier was down.
#   knowledge:crawl  04:00, 16:00
#   knowledge:embed  04:30, 16:30
```

Both need the classifier running. Without it the assistant keeps working
on keyword retrieval, and `/api/v1/health` reports
`assistant.semanticSearch: "off"`.
