# AICAD Knowledge: routing, aliases, sources and diagnostics

Written for: ARUVERSE engineers and staff who maintain what AICAD knows.

Companion to [`AI_KNOWLEDGE_MAP.md`](AI_KNOWLEDGE_MAP.md) (what the sources are)
and [`LOCAL_AI_ARCHITECTURE.md`](LOCAL_AI_ARCHITECTURE.md) (model, privacy, prompt).

"Teaching" AICAD never means training the model. It means telling the
retrieval layer where information lives, and proving with a trace that it
found it.

## Request lifecycle

`POST /api/ai/query` → `AiController::query`. Each step writes a stage to the
request's `AskTrace` (stage names in brackets).

1. **History trim**: keep only the recent turns (`request`).
2. **Prompt-injection check** over every message; refusal ends here (`injection`).
3. **Follow-up resolution**: `FollowUpQuery` rewrites "oraya nasıl giderim?"
   using earlier turns (`follow_up`).
4. **Operational path**: `AskOperations`. Routes come from `RoutingService` and
   place/event cards from database rows. It answers without the model when it can (`operational`).
5. **Direct path**: `DirectAnswer`, the student's own records (`direct`).
6. **Prompt build**: `AskPromptBuilder` → `AruverseAgent::buildContext`:
   - `QueryPlanner` folds the question, matches `QueryPlanner::LEXICON` on
     word boundaries (with Turkish/Russian endings and one-typo tolerance),
     and adds a vote for every entity `EntityResolver` finds (`routing`, `entities`).
   - The selected tools read live rows (`tool.<name>`, `database`).
   - `KnowledgeBase` retrieves in two steps (`knowledge.semantic`,
     `knowledge.search`, `knowledge.candidates`):
     1. **Candidates.** Lexical: pages whose folded body holds a *discriminative*
        query stem (one regex alternation in PostgreSQL), plus pages whose folded
        title or URL holds any stem. Semantic: every passage is compared in PHP
        (no vector index), and only the closest `embeddings.max_candidates`
        pages may enter on similarity alone. Merged and deduplicated.
     2. **Ranking.** Each candidate is scored on word-start keyword matches,
        rarity-weighted, with title and path bonuses. It also gets its semantic
        similarity (kept even outside the shortlist), language, freshness,
        authority, page keywords and penalties. The Playground shows each component.
   - The char and token ceilings cut context from the end (`prompt.budget`,
     with each block marked kept / truncated / dropped). **Only sources that
     reached the prompt are returned as citations.** Every candidate and its
     fate is in the `citations` stage.
7. **Web research**, only when the indexed sources look weak (`web_research`).
8. **Model**: local first. Groq only for questions without personal data (`model`).
9. **Grounding**: unsupported figures, links or e-mails trigger one corrective
   retry, then the deterministic fallback (`grounding`).
10. **Response**: shape unchanged for Flutter (`final`). The `ai.answer` log
    line carries `trace_id`.

Stages carry `duration_ms` (planner and entity timings are under `routing.timings`;
`database.tool_ms`, `knowledge.semantic.duration_ms`, `knowledge.search.scoring_ms`
and `total_ms`, `prompt.budget.build_ms`, `model.duration_ms`, `grounding.duration_ms`).

**Answer cache.** Single-turn answers are cached for `ai.cache.ttl_minutes`. The
key includes the newest crawl time, the prompt rules, the institution profile
and `RetrievalVersion::stamp()`. That stamp changes on any save or delete of an
alias or a live campus row, on any change to embedded passages, and at midnight.
Most campus tables have no `updated_at`, so model events bump a counter.

Production records only cheap stage summaries. Candidate lists and database
rows are collected only on verbose (diagnostic) runs. Traces are never stored,
never contain the system prompt, and redact personal fields by key.

## Where knowledge belongs

| You want AICAD to know… | Put it in | Not in |
| --- | --- | --- |
| Events, places, clubs, menus, shuttle times, staff, offices | The app's own tables (admin resources). Read live. | Crawled pages or the prompt |
| That "gym" means Sports Center | **AICAD → Aliases** (`ai_entity_aliases`) | The system prompt |
| That a question word means a domain ("staj" → career) | `QueryPlanner::LEXICON`, with a routing test | Aliases (those name *rows*) |
| A page on an ARUCAD site that the crawler misses | **AICAD → Knowledge sources** (needs its domain enabled under *AICAD Crawl Sites*) | `config/knowledge.php` (system defaults only) |
| What a whole site or one page is about | *Crawl Sites* keys / `page_keywords` | Aliases |

Aliases feed `EntityResolver` (routing and agent row ranking) and
`PlaceResolver` (operational place cards and navigation). They are applied
on the next question; the cache is cleared on save.

## Debugging a bad answer

1. Open **AICAD → Search Playground** and run the question in *Retrieval only*
   mode. Use *Earlier questions* for follow-ups. No model is called.
2. **Follow-up**: is the retrieval query what you meant? If not, it's a `FollowUpQuery` problem.
3. **Paths**: did operational or direct answer it? If so, the model was never involved.
4. **Routing**: are the right domains there? A red *fallback* badge means nothing
   was recognised. Add a lexicon word (code) or an alias (admin).
5. **Entities**: is the right row resolved, and by `name`, `alias` or `fuzzy`?
6. **Database rows**: is the fact actually in the rows sent? If not, fix the data.
7. **Knowledge**: is the right page in the candidates, and which component
   decided its rank? *dimension mismatch* or *could not be embedded* means
   semantic search is silently off.
8. **Prompt budget**: was the block holding the answer `truncated` or `dropped`?
9. Only now switch to *Full answer*: check **model** and **grounding**. If
   everything above was right and the answer is still wrong, it's a generation
   problem.

For a page that "is crawled but never used", open **AICAD → Crawled pages**,
filter *Thin extraction* or *No embeddings*, and inspect it: the extracted
text, its passages and their embedding state. *Test search* shows where that
page ranks for a question and why.

## CLI

```bash
php artisan ask:diagnose "spor salonu nerede"                      # retrieval only
php artisan ask:diagnose "oraya nasıl giderim" --previous="Titan nerede?"
php artisan ask:diagnose "burs var mı" --full --as=you@arucad.edu.tr [--no-web]
php artisan ask:diagnose "gym nerede" --json                       # whole trace

php artisan ask:benchmark          # hit@1/3/5/10 per labelled page + routing cases
php artisan ask:benchmark --candidates   # every case's top 10 with score components
php artisan ask:eval               # answer-quality evaluation (existing)
php artisan knowledge:crawl        # scheduled 04:00 / 16:00
php artisan knowledge:embed        # scheduled 04:30 / 16:30
```

"Crawl now" and "Re-embed" in the panel are queued (`CrawlKnowledgeUrlJob`,
`EmbedKnowledgeDocumentJob`), so a queue worker must be running.

## Tunables

- `config/ai.php` → `routing.max_tools`, `routing.fallback_tools`,
  `trace.knowledge_candidates`, `task_planning.enabled`, `evidence.enabled`
  and `evidence.budget_chars` (1200, the task-evidence block),
  `supported_facts.mode` (off | staff_only | on) and `campus_timezone`
  (Europe/Nicosia).
- `config/knowledge.php` → `scoring.*` (every weight the ranking uses,
  including the language, freshness, stale and authority values that were
  literals before), `scoring.candidate_min_term_weight` (0.75),
  `embeddings.min_similarity` / `embeddings.weight` / `embeddings.max_candidates` (40),
  and `max_chars_per_page` (40,000. It was 8,000, which cut 82 HTML pages,
  including the programme pages' "Eğitim Dili" sections. Re-crawl after raising it).

The benchmark set is `backend/database/seeders/data/ask_benchmark.json`: `cases`
expect a page (title/URL substring, alternatives in `also`), and `routing_cases`
expect QueryPlanner tools. Measured 2026-10-02 on 2,423 pages / 16,842 passages:
hit@1 70.7%, hit@3 95.1%, hit@10 97.6%, routing 13/13, median 405 pages scored
and ~0.6–0.8 s retrieval per question.

Change a value only with the Playground or `ask:benchmark` open, before and
after.

## Permissions

All AICAD Knowledge screens use the `ai.prompt` resource: `read` to view and
run the Playground, `manage_settings` to add aliases or sources and to queue
crawls. Platform admins hold both.

## Evaluation and regression testing

> **Evaluation cases are tests, not training data.** Nothing in a case reaches
> the router, the retrieval or the prompt. If a case fails, fix the data, the
> alias, the lexicon or the code, and never write the question into a keyword
> list so the test passes. Doing that just builds another hidden prompt.

### Test case model (`ai_evaluation_cases`, admin → AICAD → AICAD Tests)

A case is a question, an optional locale, a **mode**, tags, optional earlier
turns (`previous_turns`, `[{role, content}]`), an optional `context_user_email`
fixture, notes, and a list of **assertions**.

- **Retrieval mode** runs Follow-up → QueryPlanner → EntityResolver →
  operational/direct → database tools → KnowledgeBase → ranking → PromptBudget.
  It never calls Ollama, Groq, web research or the on-demand page fetch. It
  takes about 1 s per case, so it's the suite to run constantly.
- **Full mode** goes through the real `POST /api/ai/query` path
  (`AskDiagnostics` → `AiController`) with the answer cache switched off, so it
  tests generation and grounding. It takes 10–20 s per case, so keep it small.
  It runs as `context_user_email`, otherwise the person who started the run,
  otherwise `AI_EVALUATION_USER`. If none is set, the case is skipped with that
  reason. If the model is unreachable, the case is skipped
  ("model unavailable"), never passed or failed.

Follow-ups are not special-cased. `previous_turns` go through the same
`FollowUpQuery` the API uses.

### Assertions

Each case uses only the assertions it needs. None compares a whole answer.

| Stage | Types |
| --- | --- |
| follow_up_resolution | `follow_up.rewritten {expect}`, `follow_up.contains {value}` |
| query_planning | `routing.domains_include/exclude {values}`, `routing.tools_include {values}`, `routing.fallback {expect}` |
| entity_resolution | `entity.resolves / not_resolves {entity_type, entity_id}`, `entity.ambiguous {expect}` |
| operational_data | `operational.answered {expect}`, `operational.place_ids_include {values}`, `operational.route {expect}` |
| live_database | `live.tool_rows {value: tool}` |
| knowledge_retrieval / source_ranking | `retrieval.source_in_top {match, n}`, `retrieval.source_absent {match, n}`, `citations.not_cited {match}` |
| prompt_budget | `prompt.source_included {match}`, `prompt.source_dropped {match}` |
| grounding | `grounding.passed {expect}`, `grounding.regenerated {expect}` |
| generation | `response.contains / not_contains {value}` (folded; `a\|b` = any) |
| structured_response | `response.ai_mode {values}`, `response.served_by {values: operational\|direct\|model}`, `response.warning {value}`, `response.has {value: places\|route\|events\|sources}` |

`match` is part of a page's title or URL (`/burslar/`, `Burslar`), with `|` for
alternatives. Prefer loose bounds ("in the top 3", "contains English") over exact
ones. Don't assert event ids (events change). Only assert canonical place, service
or club ids.

### Failure stages

A failed case is attributed to the **earliest** failing stage, in pipeline order:
follow_up_resolution → query_planning → entity_resolution → operational_data →
live_database → knowledge_retrieval → source_ranking → prompt_budget →
generation → grounding → structured_response. Some refinements are decided from
the evidence:

- An expected page absent from the top 10 is `knowledge_retrieval`; present but
  below N, it's `source_ranking`.
- Retrieved and ranked but cut by the budget is `prompt_budget`.
- A `response.*` miss on an answer that grounding withheld (rejected twice) is
  `grounding`, not `generation`.
- An assertion about a stage the request never reached (for example routing on
  an answer the operational path served) fails with "stage not reached".

### Running

```bash
php artisan db:seed --class=AiEvaluationCaseSeeder   # initial suite (idempotent, never overwrites edits)
php artisan ask:evaluate --retrieval-only           # fast profile
php artisan ask:evaluate --full --as=you@arucad.edu.tr
php artisan ask:evaluate --tag=regression --tag=ru
php artisan ask:evaluate --id=12 --id=40
php artisan ask:evaluate --compare=BASELINE_ID,CURRENT_ID   # exit 1 if anything newly fails
```

In the admin, use **AICAD Tests** to run one case, run selected cases, run all
retrieval cases, or run all full-answer cases. Runs are queued, so a queue worker
must be running. **AICAD Test Runs** shows a run's totals, metrics, failures
grouped by stage with expected and actual values, the planner, entities,
retrieval top 5, prompt fates, model and grounding evidence, and a link that
reopens the question in the Search Playground. **Compare runs** shows newly
failing, newly passing and still-failing cases, plus pass rate per category,
hit@1/3/5/10, prompt inclusion, irrelevant citations, latency median/p95, and
which fingerprints (commit, models, retrieval config, prompt) changed.

Each run records the git commit (read from `.git`, without executing git), the
environment, the local and embedding models, a **retrieval fingerprint** (scoring
config, routing config, lexicon, alias table, retrieval source files) and a
**prompt fingerprint**. If two runs differ, the fingerprints say what changed.

Retrieval metrics are kept separate on purpose. *hit@k* says where the labelled
page ranked. *Reached prompt* and *dropped by budget* say whether the model
actually saw it. *Found by keywords only* means no embedded passage matched,
which is what happens past a long document's first 12 passages.

### Save as test (Search Playground)

Run a question, inspect the trace, then click **Save as evaluation test**. The
form is pre-filled with the question, locale, earlier turns, mode and
*proposed* assertions:
- the strongest planner domains
- the resolved entity, or "ambiguous"
- place ids and a route for operational answers
- the top page as "in the top 3" and "reaches the prompt" (never a score)
- grounding, and the aiMode for full runs

Edit the proposal down to what the test should guarantee, then save.

### CI

The persisted suite is **not** part of `php artisan test`. That suite stays
hermetic: SQLite, no model, no network, and it includes the evaluation engine's
own tests (`AicadEvaluationTest`, `AicadStabilizationTest`). The evaluation suite
needs real campus data and a crawled corpus. In CI, run it against a staging
database snapshot:

```bash
php artisan migrate --force
php artisan db:seed --class=AiEvaluationCaseSeeder --force
php artisan ask:evaluate --retrieval-only          # no Ollama needed; exit 1 on failure
```

The embedding service (`:8801`) is optional. Without it, ranking is keyword-only
and a few cross-language cases may fail. Run that way, the run's
`knowledge.semantic` stage shows `query_embedded: false`. Run `--full` on a
machine with the model, before a release rather than on every push. Cases tagged
`model_sensitive` report failures without failing the exit code (unless
`--strict`). Nothing is ever re-run until it passes.

### Updating an expected result safely

1. Reproduce the case in the Search Playground (the run page links there).
2. Decide which is wrong: the system or the expectation. If the corpus or data
   genuinely changed (a page moved, an office renamed), update the assertion's
   `match` or id and say why in **notes**.
3. Prefer loosening a bound for a documented reason (top 3 → top 5) over
   deleting an assertion.
4. Never edit a case to pass while the behaviour it guards is still broken. Tag
   it `known_issue` or `known_gap` and leave it failing, so a later run shows the
   fix.
5. Run the suite and compare with the previous baseline before deploying.

## Quality layers added in Phase 2.5

### Query concepts (admin → AICAD → AICAD Query Concepts)

A concept is the layer between a student's wording and the sources. For
example, "dersler ne zaman başlıyor", "when does the semester start" and
"когда начинаются занятия" all map to `academic_calendar`. A concept row holds:
- whole-phrase **wordings** in any language (folded, a word ending allowed),
- target **domains** (the planner votes for them with reason `concept:<key>`),
- **retrieval terms** (added to keyword retrieval),
- **preferred page paths** (a page on one gets `concept_path`, title-hit sized,
  so it settles near-ties only).

Concepts only change routing and retrieval. They never supply an answer. The
`routing` stage shows matched concepts, and the `knowledge.search` stage shows
`concepts` and `expanded_terms`. Three ship with the migration:
`academic_calendar`, `language_of_instruction` and `programme_duration`. Keep the
list small. A concept is justified only when the question's wording genuinely
shares no words with the page that answers it.

An entity the question names by alias or typo also contributes its canonical
name as a retrieval term. For example, "kutupane" resolves to the library
service, so "kütüphane" is searched.

### Citation eligibility

A retrieved page with **no keyword evidence** (on the results by vector
similarity alone) is neither supplied to the model nor cited when structured
data answers (a resolved entity whose table returned rows). Otherwise it's kept
only at or above `knowledge.citations.semantic_only_min_similarity` (0.60,
the low end of this model's same-meaning range). Evidence: across the 45
labelled questions, every correct page had keyword evidence. Dropped pages are
listed in the `knowledge.eligibility` stage.

### Ambiguity before generation

When `EntityResolver` reports one phrase naming several rows of the same type
with no unambiguous winner, `AskOperations` answers deterministically: "I found
more than one possible match: A, B. Which one do you mean?", in the question's
language. It returns the candidates' place cards and an `AMBIGUOUS` warning
whose `choices` list `{type, id, name}`. The model is never asked to pick.

### Programme facts (`knowledge_facts`)

`KnowledgeFactExtractor` reads language of instruction and duration from the
admissions sites' programme field block ("Eğitim Dili İngilizce Eğitim Süresi
4 Yıl …"), only that form. Main-site FAQ prose is ignored because at least one
FAQ names the wrong department. Each fact keeps its source URL, the exact
passage and `verified_at`. The crawler extracts on every index;
`php artisan knowledge:extract-facts` backfills. The `programs` tool supplies
them as database data. `AnswerGrounding` flags a sentence that names a programme
with the opposite language from its fact. Only Turkish programme names carry
facts, because the English admissions pages have no field block.

### Grounding

Beyond URLs, emails, figures, dates and acronyms, grounding now checks
capitalised institution and building names (University, Üniversitesi, Enstitüsü,
Binası, Kampüsü…) against the sources, plus contradictions with programme facts.
When a draft fails grounding:
1. **Salvage before retry.** If exactly one sentence carries the unsupported
   claim and at least three grounded sentences remain, that sentence is dropped
   and the result re-checked. No second generation is needed.
2. Otherwise the existing corrective regeneration runs.
3. If that also fails, a looser salvage is tried (up to half removed, two left)
   before the fallback.

The `grounding` stage records `salvaged: before_retry | after_retry`. Each model
call is recorded as a `model.attempt` stage with tokens and latency. When the
fallback answers because grounding withheld the draft, it never claims the AI
is unavailable.

### Caches

The alias and concept indexes are cached under a key built from the table's
stamp (row count + newest `updated_at`) **and** the `RetrievalVersion`
counter. Any write path invalidates them: model events, Eloquent bulk updates,
query-builder bulk deletes, and same-second edits. A raw `DB::table()->update()`
that doesn't set `updated_at` is the one path this can't see; write retrieval
data through Eloquent.

### AICAD Health (admin → AICAD → AICAD Health)

A read-only page showing:
- queue backlog and oldest pending job (with a warning when no worker is
  taking jobs), and failed AICAD jobs over the last 7 days
- the model and embedder, as live probes on request
- index size and freshness, programme facts and aliases
- the last evaluation run
- **data-quality warnings**: stale academic year, sports facilities with no
  place, staff records that are offices rather than people, no programme facts

These explain answers no AI change can fix. They're shown to staff only, and no
data is ever invented to fill them.

## Task planning and evidence routing (Phase 3A)

```
message ─► mentions ─► preliminary entities ─► TaskPlanner ─► fast_path ─► existing AskOperations / DirectAnswer / agent
                                                    └► planned ─► EvidenceRequirements ─► ProviderRouter ─► TaskExecutor (DAG)
                                                                                                             └► final entities
                                                                       ─► existing answer path, with the routed tools and a task-outcome block
```

Code: `app/Services/Ai/Planning/`. Everything is **deterministic**: no model plans,
and no retrieved content reaches the planner. Planning runs after follow-up
resolution, in both the API and the Playground/evaluation path.

- **Contracts** (immutable value objects): `Mention`, `PreliminaryEntityCandidate`,
  `EntityResolution` (`RESOLVED | AMBIGUOUS | UNRESOLVED`, candidates, margin, reason),
  `Task`, `TaskPlan`, `EvidenceRequirement`, `ProviderRoute`, `TaskExecutionState`
  (`PENDING READY RUNNING COMPLETED FAILED BLOCKED SKIPPED`), `PlanningDecision`.
- **Ids are stable.** The planner names tasks `t1…`. Requirements are `t1.r1…`,
  and routes and states carry the same task id, so a future evidence or claim can
  be traced back to its task.
- **`MentionDetector`** finds entity mentions (with candidates from
  `EntityResolver`) and conversation references ("oraya", "there", "туда", "bu
  kulüp"). It never picks the final entity and never rewrites the message.
  `resolver_score` is the resolver's fixed per-method score (name 1.0, alias 0.9,
  typo 0.6, halved when ambiguous). It's an ordering signal, **not a probability**.
- **`TaskPlanner`** works out *what* is wanted. It splits the message into
  clauses and detects task types: `opening_hours`, `required_documents`,
  `location`, `route`, `current_menu`, `current_events`, `program_language`,
  `club_social_profile`. It also recognises the chain `find_food_places →
  filter_open_now → rank_by_distance → route`. A clause with no subject of its
  own shares the message's subject. One task means **fast_path** (the existing
  handler answers); two or more, or any dependency, means **planned**.
- **`RequirementBuilder`** works out what must be known: fact type, subject,
  temporal scope, authority. It has no provider names.
- **`ProviderRegistry` / `ProviderRouter`** work out where it can come from today:
  `campus_operational` (canonical campus rows), `structured_facts`
  (knowledge_facts), `official_knowledge` (KnowledgeBase), `routing_service`
  (RoutingService), `request_context` (the request's location). The first
  registered provider for the fact type wins. An unknown fact type is never
  routed, whatever asks for it.
- **`TaskExecutor`** walks the DAG wave by wave. A task runs only when every
  dependency COMPLETED. A failed or blocked dependency BLOCKS it, so no
  destination, coordinates or route is ever invented. Each task checks only
  what its provider actually holds, for example machine-readable hours via
  `OpeningHours`. Unstructured tasks (`required_documents`) are recorded as
  *delegated* to the existing knowledge retrieval, not as found (Phase 3B
  then decides from evidence whether they are actually satisfied). Final,
  task-specific resolution is recorded. For example, "öğrenci işleri" (a
  service) becomes `place:titan` for a route, with the reason.
- **Compatibility layer.** For a planned question, the routed providers' existing
  agent tools are added to the prompt, along with one block stating each task's
  outcome. A single-intent operational or direct answer that would cover only one
  task is set aside; its place cards and route still go in the payload. Refusals
  and the ambiguity clarification are never set aside. Planned answers bypass the
  answer cache, because "open now" changes within a day.
- **Trace stages:** `planning_mode` (with per-stage timings), `mentions`,
  `preliminary_entities`, `conversation_reference`, `task_plan`,
  `task_dependencies`, `evidence_requirements`, `provider_routes`,
  `task_execution`, `final_entities`, `planning_handoff`. The Search Playground
  shows them as a table.
- **Flag:** `AICAD_TASK_PLANNING_ENABLED=false` turns planning off entirely, and the
  previous behaviour returns without a code change.

**Evaluation assertions** (by task type, never by id): `plan.mode`,
`plan.task_types_include/exclude`, `plan.task_count`, `plan.depends_on
{value: task, target: prerequisite}`, `plan.requirements_include`,
`plan.provider_for {value: fact type, target: provider}`, `plan.resolution_status`,
`plan.final_entity`, `plan.task_state {value: task, target: state}`. Failures map
to the stages `task_planning`, `evidence_routing` and `task_execution`. Run
metrics add a `phase3a` block covering:
- fast-path preservation, planning-mode accuracy, task planning accuracy
- task coverage and requirement coverage (requested vs planned)
- dependency correctness, provider-routing accuracy
- entity resolution, blocking correctness

"Evidenced" tasks are measured by Phase 3B below, and "answered" tasks (claims
checked against facts) by Phase 3C.

## Evidence, temporal validity and conflicts (Phase 3B)

```
TaskExecutor ─► EvidenceCollector (thin adapters) ─► TemporalEvaluator ─► EvidencePolicy
             ─► ConflictResolver ─► EvidenceConsolidator ─► requirement coverage
             ─► task states re-derived ─► request outcome ─► EvidenceBudget (task-evidence block)
```

Code: `app/Services/Ai/Evidence/`. It runs inside `TaskOrchestrator`, after the
executor, only for a **planned** question. It is deterministic: no model is
consulted at any step.

- **`Evidence`** (immutable) is one source-backed observation for one
  requirement. Its id is `t1.r2.e1`, so it traces to its requirement and task.
  It carries: fact type, subject, value and value type; provider capability and
  implementation; source type, id, URL and title; authority class; scope
  (`generic | exception`); explicit `supersedes`; `published_at`,
  `observed_at`, `valid_from`, `valid_until` and `retrieved_at`; and a status.
  - **Status:** `AVAILABLE | UNAVAILABLE | NOT_APPLICABLE | STALE | ERROR`.
  - **Invariants:** an AVAILABLE item has a value. Any item with a value has
    provenance. A missing fact has no value and a reason, so it is recorded,
    never filled in.
  - **Data-gap reasons** (`no_authoritative_field`, `no_record`,
    `no_record_for_today`) are kept apart from `context_missing` (for example,
    no location shared), `subject_unresolved` / `subject_ambiguous`,
    `provider_error` / `routing_failed` and `routing_not_configured`.
- **Adapters** read what AICAD already holds and redo nothing. Subjects come
  from Phase 3A's final entities, and passages are KnowledgeBase's own top hits.
  - **campus_operational:** `services.hours`, `places.lat/lng`,
    `food_venues.hours`, today's `food_daily_menus` (valid for that date),
    published `events` (valid while listed), and a club's handle in its text.
  - **structured_facts:** `knowledge_facts` (`observed_at` = `verified_at`).
  - **official_knowledge:** KnowledgeBase hits. Each carries document id,
    chunk id, URL, title, locale, score, lexical and semantic parts, authority,
    page, `published_at` (Last-Modified), `retrieved_at` (crawl time), a
    600-char excerpt, and `untrusted: true`. A stale page is `STALE` and keeps
    its value.
  - **routing_service:** `RoutingService::route()`, only when the destination
    and the origin are both evidenced. Unconfigured gives `UNAVAILABLE`; a
    failure gives `ERROR`.
  - **request_context:** the request's location. It is redacted in the trace
    and never put in the prompt.
- **`TemporalEvaluator`** works from `valid_from` / `valid_until` only. The
  result is `CURRENT | FUTURE | EXPIRED`, or `HISTORICAL` for past events.
  With no period stated, it is `UNKNOWN`: standing hours and coordinates are
  UNKNOWN, and that is not a defect. `retrieved_at` and `published_at` are
  never treated as validity. It also diagnoses an academic year flagged active
  after its end date, without changing it.
- **`EvidencePolicy`** sets accepted authority classes per fact type, strongest
  first. There is no universal "database beats web":
  - hours: `official_announcement > authoritative_operational > official_document`
  - programme language: `structured_fact > official_document > course_document`
    (a syllabus is weak evidence for a whole programme)
  - coordinates: campus rows only
  - routing: the routing service only

  Expired or future evidence never counts. Neither does an exception without a
  current period. For `required_documents`, a passage counts only if it names
  documents and names the task's subject. This is a fixed tr/en/ru cue list for
  recall, not claim verification. A stale source ranks behind every live one.
- **`ConflictResolver`** works per requirement and per subject. Only comparable
  values about the same subject can conflict, so passages are support, never
  rivals. Rules, in order:
  1. `explicit_supersedes`
  2. `current_exception_over_generic`
  3. `authority`

  The result is `NO_CONFLICT`, `RESOLVED` (winners, losers and the rule), or
  `UNRESOLVED_CONFLICT`, where both sides are kept and none is chosen.
- **`EvidenceConsolidator`** collapses agreeing values and groups passages by
  document. It keeps the losers, the contested sides, the exclusions with their
  reasons, and the raw evidence ids. It is not a SupportedFacts layer.
- **Coverage and states.**
  - Each requirement is `satisfied`, `unavailable`, `errored`, `conflicting` or
    `not_applicable` (its task was blocked).
  - A task is COMPLETED only when every required requirement is satisfied.
    Otherwise it is FAILED, and its dependents are BLOCKED; "a provider ran" is
    not completion.
  - Phase 3A's own states stay in its `task_execution` stage.
  - `PlanningResult::effectiveStates()` is what the answer path uses.
- **Request outcome** (internal; the Flutter payload is unchanged):
  - `COMPLETE`: every task completed.
  - `PARTIAL`: some tasks did.
  - `UNAVAILABLE`: none did, and nothing broke.
  - `FAILED`: none did, because a provider faulted.

  Each unsatisfied requirement adds a class: `DATA_UNAVAILABLE`,
  `CONTEXT_MISSING`, `RESOLUTION`, `CONFLICT`, `NO_ELIGIBLE_EVIDENCE` or
  `SYSTEM_ERROR`.
- **`EvidenceBudget`** builds the task-evidence block within
  `AICAD_EVIDENCE_BUDGET_CHARS` (default 1200). Filling order:
  1. each task's outcome line (round-robin)
  2. each requirement's primary evidence (round-robin over tasks): the standing
     value with its source, a fenced excerpt of the winning document passage,
     or "bilgi yok (reason)"
  3. extra support, one per document first (source diversity)

  The block goes first among the context blocks, because the context budget
  cuts from the end and a long generic tool listing used to push out both it
  and the knowledge block. The excerpt uses the knowledge block's own fence and
  `(Kaynak: url)` marker, so it stays untrusted, citable and grounded even when
  the knowledge block is cut. It is atomic: dropped whole, never cut
  mid-fence. Every unit's fate (kept, truncated or dropped, with a reason) and
  its character span are traced. `prompt.budget` blocks now carry
  `kept_chars`, so "reached the model" is exact.
- **Knowledge ranking is paid once.** The prompt path reuses the collector's
  KnowledgeBase result through `EvidenceResult::knowledgeHitsFor()`, but only
  for the identical query and depth.
- **Trace stages:** `provider_evidence`, `temporal_evaluation` (with the
  academic-year check), `evidence_policy`, `evidence_conflicts`,
  `evidence_consolidation`, `requirement_coverage` (outcome, classes, per-task
  counts, timings) and `evidence_budget`. Every item carries its task,
  requirement and evidence ids. The Search Playground shows a per-task evidence
  table.
- **Flag:** `AICAD_EVIDENCE_ORCHESTRATION_ENABLED=false` restores Phase 3A
  exactly: no collection, the 3A task block, and the 3A states.

**Evaluation assertions** are matched by fact type (`value`), never by evidence
id:
- `evidence.available`
- `evidence.unavailable {target: reason?}`
- `evidence.source_type {target}`
- `evidence.source {match}`
- `evidence.temporal {target: CURRENT…}`
- `evidence.excluded {target: reason text?}`
- `evidence.conflict {target}`
- `evidence.winner_class {target: authority class}`
- `evidence.requirement {target: satisfied…}`
- `evidence.outcome {value: COMPLETE…}`
- `evidence.reaches_prompt`

Failures map to the stages `provider_evidence`, `temporal_evaluation`,
`evidence_policy`, `evidence_conflicts`, `requirement_coverage` and
`evidence_budget` (or `prompt_budget` when the block was cut later).

A failed case is also classed as one of:
- `DATA_UNAVAILABLE`: every failure is about a recorded data gap.
- `AI_GENERATION`: generation or grounding only.
- `PIPELINE`

The class is stored in the result snapshot, so no migration is needed. Run
metrics add a `phase3b` block, kept apart from `phase3a`:
- evidence recall
- evidence precision
- requirement satisfaction
- temporal accuracy
- conflict accuracy
- outcome accuracy
- observed task-evidence coverage
- prompt task coverage
- irrelevant-evidence rate (lower is better; the compare page reads it that
  way)

They also add `failure_classes` and `request_outcomes`.

**Known data gaps, kept visible by the `3b:` cases rather than papered over:**
- food venues have no hours and no place link
- clubs have no social-profile field and no room
- there is no daily menu or upcoming event on most days
- the 2025-2026 academic year is still flagged active

There is no source of temporary hour exceptions or announcements yet. The
exception, supersede and announcement paths are exercised by controlled
fixtures (conflict fixtures A–D), not production data.

## Supported facts and constrained generation (Phase 3C)

```
Evidence ─► CandidateFact ─► FactValidator ─► SupportedFact ─► AnswerPlan
        ─► fact-bounded prompt ─► model (one call) ─► AnswerGrounding + ClaimVerifier
        ─► regenerate once / withhold sentences ─► citations from used facts
```

Code: `app/Services/Ai/Facts/`. Facts and the answer plan are built for every
**planned** question, right after Phase 3B. The build is deterministic and
takes about 1–5 ms. **Generating** from them is behind
`AICAD_SUPPORTED_FACT_GENERATION_ENABLED` (default `off`; see Phase 3C.1 for `staff_only`). With it off,
Phase 3B generation is unchanged and the facts are only traced. Single-intent
questions and the operational/direct fast paths never build facts.

The rule: **the model is not the source of truth.** The system decides which
facts are supported; the model only words them.

- **`CandidateFact`** is a proposed value for one requirement. It carries the
  task and requirement ids, fact type, subject, value, exact evidence ids,
  method (`structured`, `derived:<rule>` or `pattern:<fact type>`), extractor
  and, for documents, the minimal supporting span. It is **not** a fact yet.
- **`SupportedFact`** has id `fact_t1_1`, its task, requirement and candidate,
  subject, value, normalized value, value type, evidence ids, provenance
  (source type, id, URL, title, authority, page), temporal status, authority
  class, validation `{method, reason}`, span, and the anchors used to recognise
  it in an answer. It has no confidence score. The student's own location is
  redacted.
- **Status per requirement:**
  - `SUPPORTED`
  - `INSUFFICIENT`: evidence exists but states no valid fact, which is the case
    3B could not see
  - `CONFLICTING`: an unresolved 3B conflict, or two validated values for one
    programme; no value is chosen
  - `STALE`: only expired or stale evidence
  - `UNSUPPORTED`: no evidence
  - `NOT_APPLICABLE`: the task was blocked
- **Deterministic (structured) fact types:** these convert directly and never
  go through document extraction.
  - `current_opening_hours`, plus `open_now` derived by `OpeningHours`
  - `place_coordinates`, `user_location`
  - `route_distance_m` and `route_duration_min`, derived from RoutingService
  - `current_menu`, `current_events`, `food_places`
  - `club_social_profile` from a canonical field
  - `program_language` from `knowledge_facts`
- **Document extraction** (`DocumentFactExtractor`) is deterministic and
  specific to the fact type. **No LLM call is used.**
  - `program_language`: "Eğitim/Öğretim dili: …", "language of instruction",
    "taught in …" and "язык обучения". The passage must name the programme the
    question named; a passage stating two languages yields nothing.
  - `required_documents`: an explicit header ("gerekli belgeler:", "kayıt
    belgeleri:", "required documents:" …) followed by a colon and list items,
    up to the end of that sentence. Mentioning documents is not listing them.

  Because a page cannot change the fact type, subject, provider or source,
  injected text can at most produce a list item, and validation rejects it.
- **`FactValidator`** has one rule set per fact type:
  - language: an allowed enum (en/tr), attributed to a programme
  - document lists: each item verbatim in the evidence, not a category word,
    not an instruction (`PromptInjection`)
  - hours: parsed time ranges
  - coordinates: in range, from a canonical place
  - routing numbers: bounded
  - social: URL or handle syntax, present verbatim in the canonical record
  - events: title and a parseable date
  - menus: at least one item

  A programme language extracted from a page that contradicts the structured
  programme fact is rejected; the structured fact is the stronger source.
- **`AnswerPlan`** gives each task one status: `supported`, `partial`,
  `insufficient`, `conflicting`, `stale`, `context_missing`, `unavailable` or
  `not_applicable`. Each task carries its fact ids, and the plan has an outcome
  (`COMPLETE | PARTIAL | UNAVAILABLE`). It is the contract, not prose.
- **Generation input** (`FactPromptBlock`, block `tool:facts`):
  - the AnswerPlan, plus each SupportedFact as `[fact_…] label: value`
  - document facts with their span, fenced as untrusted with the `(Kaynak: url)`
    marker
  - per-status guidance, for example "say it is not stated; do not invent a
    list"

  Raw tool rows and the retrieved-pages block are **not** sent. The model keeps
  its freedom over wording, order and tone.
- **Claim verification.**
  1. `AnswerGrounding` (links, e-mails, figures, dates, names, institutions,
     exam acronyms such as ЕГЭ, KnowledgeFact contradictions) runs against the
     fact-bounded prompt.
  2. `ClaimVerifier` adds fact-level checks:
     - times must be in a supported range
     - walking minutes and metres must match the routing facts
     - a stated language of instruction must match a supported fact
     - document names need a supported list
     - **street addresses** are rejected
  3. Sentences that only word things, or report a gap, are presentation, not
     claims.
  4. Each claim is attributed to fact ids through the facts' anchors. This is
     deterministic, with no structured output and no second model.
- **Unsupported claims:** one regeneration names what was unsupported and the
  verified tasks the draft skipped. It is never a third model call: if
  grounding already regenerated, verification goes straight to withholding.
  Withholding removes only the offending sentences, so verified tasks survive.
  If nothing is left, the deterministic fallback answers.
- **Citations follow used facts.** Each fact source lists the fact ids it
  backs, and the payload cites only sources behind a fact the final answer
  used. A page that produced no fact, or a fact the answer did not use, is
  never cited.
- **Trace stages:** `candidate_facts`, `fact_validation`, `supported_facts`,
  `requirement_fact_status` (with timings), `answer_plan`, `generation_input`,
  `generation_fact_usage` and `claim_verification`. The lineage is task →
  requirement → evidence → candidate → fact → claim. The Search Playground
  shows it per task.

**Evaluation assertions** (fact type as `value`; answer plan by task type):
- `fact.candidate`
- `fact.supported {target?: normalized value}`
- `fact.none`
- `fact.status {target}`
- `fact.source {match}`
- `answer_plan.task {target}`
- `answer_plan.outcome`
- `claim.uses_fact`
- `claim.no_unsupported`
- `claim.unsupported_caught`
- `claim.regenerated`
- `citation.from_fact {match}`
- `citation.absent {match}`

The `claim.*` and `citation.*` assertions apply only to answers generated from
facts. Run the `fact_generation`-tagged full cases with the flag on.

Metrics add a `phase3c` block, kept apart from 3A and 3B, with no composite
score:
- fact extraction recall and precision
- SupportedFact precision
- requirement factual satisfaction
- unsupported-fact rejection rate
- claim support rate
- unsupported-claim rate
- citation precision
- task answer coverage
- regeneration rate
- model calls per answer

**Known limits:**
- Claim detection is pattern-based. A wrong *paraphrase* of a supported fact
  ("open between 09:00–17:00 today" when it is currently closed) and vague
  speculation without checkable values are not caught.
- English programme names are not resolved to the Turkish programme facts.
- No source of announcements or hour exceptions exists yet.

## Rollout hardening (Phase 3C.1)

**Opening hours and "open now" are separate facts.**
- `current_opening_hours` is the schedule: `{text, opens, closes, days}`.
- `is_open_now` is a boolean with `{evaluated_at, timezone, reason}`. It is
  evaluated by `OpeningHours::evaluate()` in **campus time**
  (`AICAD_CAMPUS_TIMEZONE`, default `Europe/Nicosia`; the app clock is UTC).
  Before this, every open/closed judgement in 3A, 3B and 3C was made in UTC,
  2–3 hours behind Kyrenia.
- No `is_open_now` fact is created when the schedule does not say which days it
  covers (a bare `09:00–17:00` says nothing about Saturday), or when the hours
  are not machine-readable.
- "Şu an açık / currently open / сейчас открыто" (and "closed") must match the
  `is_open_now` fact. The hours alone never support them.

**Claim classes** (`ClaimVerifier`, deterministic, no LLM judge):
- `SUPPORTED_BY_FACT`
- `PRESENTATION_ONLY`: offers, transitions, a reported gap, advice to ask an
  office
- `UNSUPPORTED_FACTUAL`
- `UNCERTAIN`: a declarative campus-subject sentence with no fact and nothing
  checkable; recorded, not deleted

`UNSUPPORTED_FACTUAL` now also covers:
- **speculation** ("genellikle", "usually", "обычно" …) in a sentence no fact
  backs
- **absolute negatives** ("bulunmamaktadır", "does not offer" …)
- **floors and room numbers**

Sentences are no longer split after ordinals ("2. katında"), and document
nouns are word-bounded ("hekimlik" is not "kimlik").

**Negative claims.** A task with no facts carries
`absence: not_found_in_current_data`. The prompt tells the model to say it was
not found, never that it does not exist. `authoritatively_not_offered` requires
a source that states the absence; AICAD has none, so it is never assigned.

**Regeneration policy** (one at most):
- Removal comes first. Regenerate only when removal leaves nothing, the draft
  **contradicts** a SupportedFact (time, language, open-now), or removal drops a
  task whose facts **cannot be restated** by a template (for example a document
  list).
- The reason, claim counts and coverage are recorded: `coverage_generated`
  (what the draft attempted, including removed sentences),
  `coverage_after_removal` and `coverage_final` (after restatement).

**Restatement** (`FactSupplement`) uses approved tr/en/ru templates and
SupportedFact values only. It covers place, hours, open-now and programme
language. It is not a second generator.

**Citations:** only sources behind a fact that a **surviving** claim uses. The
trace records `citation_invariant_violations`, which should always be 0.

**Programme names.** `ProgrammeCatalog` groups programme facts into canonical
programmes, keyed by the folded Turkish subject. Alternative names are
`programme` aliases in the existing alias system. `AiProgrammeAliasSeeder`
loads the **official** English and Russian names taken from the crawled
programme-page titles. An alias that equals another fact subject (the English
page's "New Media and Communication") merges the two, so there are no duplicate
facts per language. "Graphic Design" is not an ARUCAD name and stays
unresolved.

**Rollout modes:** `AICAD_SUPPORTED_FACT_GENERATION_ENABLED` accepts `off`,
`staff_only` or `on` (`true`/`false` still work). Staff means existing
back-office access (`GranularPermissions::canAccessAdminPanel`), never a list
of e-mails. There is no percentage bucketing: the app has no stable bucketing
infrastructure, and none was invented.

**Fallback safety** (`SupportedFactsRollout`): the fact block is built
**before** the model call.
- If it fails and every task has its facts (`supported` or `context_missing`),
  the Phase 3B path answers. Its fallback reason is
  `SUPPORTED_FACT_PATH_ERROR`.
- If any task's data is missing, insufficient or conflicting, the
  deterministic fallback answers with **no model call**. Lack of facts is never
  a reason to try a looser prompt.
- A failure in claim verification withholds the unverified answer and records
  `request_outcome: FAILED`.

**Shadow comparison.** On the legacy path, the same deterministic claim check
runs on the legacy answer (`shadow_fact_check`), with **no second generation**.
Shadow generation (two model calls per request) was rejected on latency.

**Trace:** `generation_path` records:
- `supported_facts_mode`, `supported_facts_enabled`, `generation_path`,
  `fallback_reason`
- `generation_attempts`, `regeneration_reason`
- `unsupported_claims_removed`, `speculative_claims_removed`
- `final_claim_count`, `supported_claim_count`, `presentation_only_claim_count`,
  `uncertain_claim_count`
- `restated_fact_count`, `request_outcome`

**AICAD Health → SupportedFacts rollout** shows, over 30 days:
- the mode, and requests per path
- outcomes (COMPLETE, PARTIAL, UNAVAILABLE, FAILED)
- regenerations and their rate, removed and speculative sentences, withheld
  answers, restatements
- path errors, split by legacy versus deterministic fallback
- the shadow check
- model calls per request, and median/p95 latency (last 500 samples)

These are counters and latencies only; no question, answer or prompt is stored.

New assertions: `rollout.path {value}` and `claim.full_coverage`. The phase3c
metrics add task coverage after removal, restated facts, speculative claims
removed and technical fallbacks.

## Coverage and canonical data (Phase 4A)

The coverage matrix, gap classes and data-entry list are in
[AICAD_COVERAGE.md](AICAD_COVERAGE.md). The live matrix is on AICAD Health → Coverage.

**New fact types**
- `academic_date` (task `academic_dates`, provider `official_knowledge`): one structured entry
  `{kind, label, term, start, end, academic_year}` from `AcademicCalendar`. Just after a term
  starts, the entry also carries that term as `recent` context, so it is never a second,
  conflicting value. An ended calendar is STALE evidence and becomes no fact.
- `contact_email` / `contact_phone` (task `contact_details`, provider `campus_operational`):
  verbatim from the subject's canonical contact field (service, staff or club). FactValidator
  requires the value to be present in that record.

**Fast paths**
- DirectAnswer answers single-intent calendar questions with no model call, in tr, en and ru:
  classes start/end, registration, add–drop, finals, make-ups, orientation and holidays. Wording
  about clubs, events, scholarships or dormitories is excluded.
- The operational service card answers contact questions ("telefonu ne", "maili ne", "how can
  I contact", "почта"). It states a missing phone ("kayıtlı bir telefon numarası yok") and a
  missing responsible person ("müdürü kim"). It never gives a name.

**Trusted app navigation.** `config/aicad_navigation.php` lists the screens a student can open:
the five tabs (`campus_shell.dart`) and the Discover tiles (`explore_tiles.dart`), each by its
translation key. `AppNavigation` reads the labels from the published translations. The fact
prompt lists them, and ClaimVerifier rejects any other screen, tab or section. Update the
config when a screen is added or removed. The Flutter app has no route table to read from.

**Canonical fields** (migration `2026_10_06_100000_add_canonical_campus_fields`, additive and
nullable, no back-fill):
- `food_venues.place_id`
- `clubs.email`, `clubs.website`, `clubs.instagram_url`, `clubs.place_id`
- `opening_hours` (`subject_type`, `subject_id`, `day_of_week` 1–7, `opens`, `closes`,
  `timezone`, `valid_from`, `valid_until`)

All of these are editable in the venue and club forms. Club social profiles come only from
`instagram_url`. A handle in the description is not a verified account.

**Verifier categories** that are never left UNCERTAIN: dates, contacts, titled persons, programme
length, admission scores and app screens. See AICAD_COVERAGE.md for the full table.

**Evaluation.** 24 cases tagged `phase4a` (realistic phrasings, tr/en/ru, colloquial and
without diacritics). 20 run in retrieval mode and 4 in full mode under `fact_generation`. Date
assertions check the source and the path rather than specific days, so they stay valid as the
calendar advances. PHPUnit: `AicadCoverageTest`.

## Data completion (Phase 4B)

**New fact type `programme_duration`** (task `programme_duration`, provider `structured_facts`):
- The value comes from `knowledge_facts` attribute `duration`, the programme page's own field block. Prose is never used.
- FactValidator accepts only the forms the pages use ("4 Yıl", "2 yıl", "1-2 Yarıyıl") and normalises them to `4y` / `1-2s`.
- Two pages that disagree make the fact CONFLICTING.
- The planner keeps the task only when the message names a programme, so "how long" next to a route stays a walk.
- DirectAnswer answers a single duration question in tr/en/ru, citing the programme page and using the English or Russian programme name from the aliases.
- ClaimVerifier accepts a programme-length sentence only when a duration fact states that length.

**Contacts.**
- `services.phone` is a new validated field: digits plus `+ ( ) - .`, at least 7 digits, stored exactly as typed.
- Contact evidence is one value `{emails, phones}` per record, so an e-mail and a phone are two facts and never a conflict.
- The office card shows the phone when it is recorded.

**Places.**
- `sports.place_id` links a team to a campus place. Facility names are never matched to places.
- Named food venues, clubs and teams can be the subject of "where is it / take me there", through their canonical place only.
- DirectAnswer's dining answer uses structured weekly hours when present and adds "open now (campus time)", "open/closed tomorrow" and "where". Each part appears only when its data exists.

**Aliases.** `AiCampusAliasSeeder` (data in `database/seeders/data/aicad_campus_aliases.json`) adds 28 office aliases, each with its basis: official name, product wording, or a Russian case form. It is additive and idempotent, and leaves a locale alone once an operator manages it.

**Trusted navigation.** The list now includes Notifications and the profile's activity screen (16 destinations). `AicadNavigationConfigTest` fails if a key is missing from `app_strings.dart` in tr/en/ru, or if no screen uses it any more.

**Deploying 4B:**
1. `php artisan migrate`: `2026_10_07_100000_add_service_phone_and_sport_place` (additive, nullable).
2. `php artisan db:seed --class=AiCampusAliasSeeder` (renamed in 4C from `AiServiceAliasSeeder`; now offices and clubs)
3. `php artisan db:seed --class=AiEvaluationCaseSeeder`: adds the `phase4b` cases. Existing cases are never overwritten.

## Coverage closure (Phase 4C)

- **Open food place concept** (TaskPlanner):
  - An open word plus a food word plus an indefinite marker ("var mı", "bir yer", "any", "anywhere", "есть", "можно") starts the find → filter-open chain.
  - "Now" with an eating verb ("где сейчас можно поесть") counts as open-now.
  - A named venue with no marker ("yemekhane açık mı") keeps its single task.
- **Typed conversation references** (MentionDetector): a bare type noun in a case form ("kulübün", "kulübe", "the club's", "клуба"; "takımın", "the team's") refers to the latest entity of that type, in this message or an earlier turn. It never binds to another type. The message is never rewritten.
- **Sports short names** (EntityResolver):
  - Derived from each team's canonical name: without "ARUCAD", the "(Erkek)" qualifier and a trailing "takımı/kulübü".
  - A name inside a longer matched name of the same type doesn't count separately ("masa tenisi" ≠ "tenis").
  - A facility name names a place only when staff linked every team there to the same place.
- **Club aliases:** `AiCampusAliasSeeder` adds the official English club names (Student Handbook 2026–2027) and two Turkish forms from the official clubs page.
- **Programme language** uses the structured fact first. Passage retrieval is now only a fallback when the named programme has no structured fact (planned latency ~990 ms → ~5 ms). `AicadSupportedFactsTest` was updated deliberately for this: the old contradicting page is no longer even a candidate, instead of being retrieved and rejected.
- **Admin → Academic years:** a new resource on the content-resource pattern. Making a year active deactivates the others, the same rule as the API.
- **Readiness** gained `campus_aliases` and `academic_year` checks.
- **Staging fixtures:** `php artisan aicad:staging-fixtures [--remove]` (see `AICAD_STAGING_PACKAGE.md`).

## Staging readiness findings (Phase 4D)

The controller smoke pack (`php artisan ask:smoke`, real HTTP, staging only) was validated locally with SupportedFacts both off and on. It found these issues, each fixed at its layer and covered by tests and evaluation cases:

| Finding | Layer | Fix |
|---|---|---|
| "…GARDEN MENÜ adlı açık yemek yeri mevcuttur" passed on the venue-name fact, with no hours | CLAIM VERIFICATION | Where open status was asked, an unqualified "açık / open / открыто" (no time, no day, not a question) is an open-now claim and needs `is_open_now` |
| A plan with no supported fact still called the model, which invented "@arucad_photography" and a room | GENERATION policy | Outcome UNAVAILABLE → `FactSupplement::absence()`: the system says what is not in the current data; no model call (`aiMode=deterministic`; counter `deterministic_absence_answers`). The text passes the same grounding and claim check, and the trace records it |
| "Fotoğraf Kulübü" read as a "photo" document; an honest gap sentence was removed | CLAIM VERIFICATION | The document check runs only when documents were asked |
| Stray CJK text in an answer | GENERATION (caught at verification) | A sentence in a script the answer languages don't use is unsupported |
| After removal, a partial answer didn't name the venue or the gap | GENERATION | A `food_places` restatement template, and `forUnstatedGaps()` states unavailable tasks when the answer reports no gap |
| A department head named by the legacy model | none: grounded | The official programme page states "Bölüm başkanı …". This is a sourced basis for a future person fact (department heads from the programme field block) |

The absence and gap templates name nothing the facts don't (no institution name), and use the recognised gap forms in every language ("bulamadım / bulunamadı", "couldn't find / not found", "не найдено").

## Not built yet

Saved traces beyond the evaluation snapshot, repeated model trials per case, and automatic
relevance scoring of citations. Remaining after Phase 4A:
- an announcements / hours-exception source
- person records for staff
- structured admission requirements
- making fact-bounded generation the default once its full-answer runs stay clean
