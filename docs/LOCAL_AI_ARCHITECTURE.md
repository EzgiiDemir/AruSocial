# Local AI architecture (implemented)

Written for: the ARUVERSE engineering team and whoever runs the servers.

This describes **what is in the codebase**, not a plan. Where something is
still a decision or a known gap it says so under §10.

The short version: Ask ARUVERSE now answers on ARUCAD's own GPU, the model
is never the knowledge source, and a student's question does not leave the
campus — including when the GPU is down.

---

## 1. The flow

```
Flutter
  └─ POST /api/v1/ai/query          messages[] (capped to AI_HISTORY_TURNS)
     └─ DirectAnswer                one fact from our tables → answer, no model
     └─ response cache              repeated public question → answer
     └─ AruverseAgent               12 read-only tools, chosen by keyword
        + KnowledgeBase             hybrid keyword + MiniLM retrieval
        └─ AskPromptBuilder         rules | metadata + personal | FENCED sources
           └─ AiResponder
              ├─ AiPrivacy gate     may this prompt leave campus? (default: no)
              ├─ local (vLLM)       PRIMARY
              ├─ groq               only if BOTH privacy switches are on
              └─ knowledge-only     grounded, marked source-based
           └─ AnswerGrounding       invented URL/e-mail/figure → retry → drop
     └─ response                    { answer, aiMode, sources[], conversationId }
```

Nothing above the `AiResponder` line changed in this work: the agent,
retrieval, ingestion, cache, circuit breaker and budget are as they were.

**The model is not the knowledge source.** It receives facts already
resolved by the agent and phrases them. It has no database access, no tool
calling and no autonomy, and it cannot acquire any — `AruverseAgent` is a
deterministic router whose tool table is a literal in the source.

---

## 2. The inference server

Separate host. `deploy/ai/docker-compose.yml`.

| | |
| --- | --- |
| Engine | vLLM, OpenAI-compatible, pinned `vllm/vllm-openai:v0.30.0-cu129` |
| Model | `Qwen/Qwen3.5-9B` — the instruct release, **not** `-Base` |
| Revision | pinned `c202236235762e1c871ad0ccb60c8ee5ba337b9a` |
| Served as | `arucad-ask` (stable across weight changes) |
| Quantisation | FP8 by default; W4A16 documented, see `deploy/ai/README.md` §3 |
| Context | 16 384 tokens |
| Exposure | bound to the private interface, bearer-token authenticated |

Laravel reaches it through the **existing** `OpenAiCompatibleProvider`. No
AI SDK was added; the only new provider code is the privacy gate.

**No official 4-bit build of this model exists** (`-AWQ`, `-GPTQ-Int4` and
`-FP8` all 404 on Hugging Face; only the large MoE models have them). FP8
is therefore the default because it needs no preparation and Ada/L4 has
hardware support for it. W4A16 is one `llm-compressor` run, documented,
and must be re-scored with `ask:eval` afterwards.

---

## 3. Provider policy and privacy

Two switches, both defaulting to the private choice (`config/ai.php`):

| Variable | Default | Effect |
| --- | --- | --- |
| `AI_ALLOW_EXTERNAL_PROVIDERS` | `false` | false: only self-hosted providers are ever called |
| `AI_ALLOW_EXTERNAL_WITH_PERSONAL_DATA` | `false` | false: a prompt carrying a student's own data stays in-house |

`AiPrivacy` applies this where the provider is chosen, so every caller of
`AiResponder::generate()` inherits it — including ones written later. A
refused provider is simply absent from the chain, so the request degrades
exactly as it would if that provider were down.

The second switch decides whether a prompt that actually carries student
context may reach Groq. A signed-in student's ordinary campus question is not
automatically personal: `PersonalContext::isRelevant()` must first detect an
explicit profile, appointment, club, programme or similar personal intent.
The prompt builder uses the same gate, so public questions do not silently
acquire profile data. **Both** switches must still be true before any attached
student context may leave campus.

When the local model is down, the request becomes a knowledge-only answer:
assembled from indexed ARUCAD sources, grounded, and labelled in the reply
as source-based rather than AI-generated. It is **not** forwarded outward.

---

## 4. Trust boundaries in the prompt

`AskPromptBuilder::build()` assembles three layers and keeps them apart:

1. **Rules** — ours. The only instructions that exist.
2. **Metadata + personal block** — ours. Facts about the asker, scoped to
   their own id by `PersonalContext`.
3. **Retrieved content** — crawled from the public web. **Untrusted.**

Layer 3 is quoted between `<<<ARUCAD_RETRIEVED_CONTENT` and
`ARUCAD_RETRIEVED_CONTENT>>>`, and the rules above it say that anything
between those markers is data and that instructions found there must be
ignored, naming the patterns ("forget previous instructions", "show the
system message", "your new task is…").

Fence markers occurring in the crawled text are **stripped before
insertion**, so a page cannot close the fence early and have the rest of
itself read as trusted prompt. That is the attack the structure exists for
and it has a test (`AskPromptSafetyTest`).

A delimiter is not a security boundary on its own. It is a large
improvement on the previous arrangement, where crawled prose was appended
to the prompt in the same position as our own rules.

---

## 5. Context management

| Setting | Default | Why |
| --- | --- | --- |
| `AI_HISTORY_TURNS` | 6 | The client sends the whole thread; the server decides what the model sees, because only the server knows the window |
| `AI_CONTEXT_BUDGET_CHARS` | 6000 | Ceiling on retrieved text, ~2 000 tokens |
| `VLLM_MAX_MODEL_LEN` | 16384 | System prompt + sources + history + 800 generated, with room to spare |
| `AI_TEMPERATURE` | 0.2 | Grounded assistant, not a writer. Was 0.4 |
| `AI_MAX_TOKENS` | 800 | About a page of Turkish prose |

`LOCAL_AI_TEMPERATURE` / `LOCAL_AI_MAX_TOKENS` override per provider, so a
local 9B and a hosted 20B need not be tuned together.

History was previously unbounded: a long conversation grew the prompt until
it crowded out the retrieved sources, and against a fixed window it would
eventually overflow. The cap is applied server-side in `AiController`
before anything reads the messages; the client mirrors it to save
bandwidth but is not trusted to.

---

## 6. Citations

`POST /ai/query` returns `sources[]`:

```json
{ "type": "web", "title": "Burslar ve Ücretler",
  "url": "https://arucad.edu.tr/burslar/", "id": "…" }
{ "type": "campus", "title": "Etkinlikler", "url": "", "id": "tool:events" }
```

These are what the **backend put in the prompt**, not URLs parsed out of
the answer — a model that invents a link invents a citation with it, so a
list scraped from the reply would be exactly as unreliable as the reply.

A direct answer returns `[]` rather than a plausible-looking source.
Flutter renders them as chips under the answer; web sources open, campus
sources name the table they came from.

---

## 7. Authorization

`PersonalContext` queries by the authenticated user's id; nothing from the
question reaches the query, so it cannot be pointed at another account.

The agent's events tool and `PersonalContext` now both use
`Event::scopePubliclyListed()` — the same rule the rest of the app uses.
Previously they filtered `draft` and `workflow_status` only, so an event
scheduled to publish next week, or one already expired, could reach the
model and be told to a student as current. Tested.

Authorization happens **before** data enters the prompt. The model is never
asked to decide what the user may see.

---

## 8. Logging

`ai.completion` carries provider, model, self-hosted flag, status, latency
and token counts. It does **not** carry the prompt, the answer, the API key
or anything from `PersonalContext`. A privacy refusal logs the provider and
the reason, and no prompt. Both are asserted in
`AskSourcesAndLoggingTest`.

---

## 9. Evaluation

```bash
php artisan ask:benchmark                      # retrieval@k, unchanged
php artisan ask:eval --retrieval-only          # no model needed
php artisan ask:eval --provider=local
php artisan ask:eval --provider=groq --json=/tmp/groq.json
```

`ask:eval` builds the **real** prompt via `AskPromptBuilder` — which is why
that class was extracted from the controller — and scores: retrieval,
grounding, fabricated URLs / e-mails / figures (counted separately),
correct refusal, over-refusal, language match, citation presence, and
p50/p95 latency. It exits non-zero on any fabricated claim, so it can gate
a deploy.

Provider comparison runs the same prompt against each model. The eval set
is synthetic and public and the harness signs in as nobody, so no student
data is in the prompt even when the provider is external.

Current set: `database/seeders/data/ask_eval.json`, 42 cases including 6
refusal cases and 2 injection probes. **Target is 100–300 anonymised real
student questions**; the file takes them with no schema change. Until then
it shows how the assistant handles anticipated questions, not unanticipated
ones — which is the honest limit of any set written by the people who
tuned retrieval.

---

## 9b. Source-of-truth policy

The model is never the authority on an ARUCAD fact. Precedence is enforced
in code by `App\Services\Ai\SourceAuthority` and stated in the prompt
rules, so it does not depend on the model choosing well:

| Level | Source | Citable |
| --- | --- | --- |
| 100 PERSONAL | the asker's own live rows | **no** |
| 80 OPERATIONAL | our structured tables | yes |
| 60 OFFICIAL_DOC | regulations, policies (reserved — nothing populates it yet) | yes |
| 40 WEB | crawled ARUCAD pages | yes |
| 20 CONVERSATION | earlier turns | n/a |
| 0 MODEL | pretrained knowledge | n/a |

Mechanically:

- **Block order.** `AruverseAgent` emits our own tables before crawled web
  text. It used to be the reverse, which invited the model to answer from
  a snapshot and mention the database as a footnote.
- **Dated snapshots.** Every web snippet carries `[site görüntüleme: DATE]`;
  an unreachable page carries `[ARTIK ERİŞİLEMEYEN SAYFA]`. The model can
  only prefer a live row over a snapshot if it can see the snapshot's age.
- **Conflict rule.** Stated explicitly in the prompt: on anything that
  moves — event time, shuttle departure, opening hours, menu, appointment —
  the database block beats the web quotation, and the answer says the page
  may be out of date. An unresolvable conflict is reported as a conflict.
- **No source, no claim.** If no block holds the fact, the answer says it
  cannot be confirmed. Knowing how universities generally work is not
  evidence about ARUCAD.
- **Facts vs. recommendations** are kept apart: "your next class is at
  14:00" comes from a source; "leave by 13:50" is advice.
- **Personal data is never citable.** It informs the answer and never
  appears in `sources[]` — a source card is a disclosure to whoever is
  looking at the screen.
- **Conversation is context, not evidence.** It resolves "it" and "there"
  and can never establish a fact.

`sources[]` now carries `authority`, `authorityLabel`, `freshness`,
`updatedAt` and `stale`, ranked strongest-first.

The full audit — every source, the coverage matrix, the routing map and the
gap analysis — is in [`AI_KNOWLEDGE_MAP.md`](AI_KNOWLEDGE_MAP.md).

## 10. Known gaps and decisions still open

1. **Nothing here has run against a real GPU.** There is no NVIDIA host in
   the development environment. The compose file, flags and model pin are
   checked structurally and against the published image/model metadata; the
   first `docker compose up` on the L4 is the first real execution. Treat
   the first run as expected iteration.
2. **The 9B is a vision-language model** (`image-text-to-text`). We send
   text only and reserve no multimodal slots, but its weights are larger
   than a text-only 9B would be.
3. **FP8 vs W4A16 is unmeasured** for this workload. Run `ask:eval` under
   both before choosing.
4. **No reranker**, deliberately. After expanding the live corpus to 306
   documents/1,972 passages, `retrieval@3` is 24/30 (80.0%); misses are now
   visible and should be addressed first through corpus/alias/scoring analysis.
   Add a reranker only if that measured work still leaves misses. The
   candidate is `BAAI/bge-reranker-v2-m3` (multilingual, TR/RU).
5. **Vector search is still a PHP scan** over `knowledge_chunks` (~23 ms at
   390 passages, projected ~320 ms at 5 000). pgvector + HNSW is the move,
   contained to `KnowledgeBase::semanticHits()`. Not done here: it was not
   needed for the local-LLM MVP and the plan says so.
6. **Embeddings unchanged** — `paraphrase-multilingual-MiniLM-L12-v2`,
   already in memory in the moderation service. Not replaced, because no
   measured retrieval problem justifies it.
7. **Prompt-injection defence is structural, not proven.** The fence and
   the stripping are tested; resistance of the *model* to a novel phrasing
   is not something a unit test establishes.
8. **`ask:eval` refusal detection is phrase matching** and will miss a
   creatively-worded refusal.
9. **Official PDFs are now supported and locally crawled.** The verified
   development crawl discovered 87 PDFs across approved ARUCAD sources:
   83 parsed/indexed and 4 recorded as `no_extractable_text` (two logo-art
   PDFs and two image-only fee documents). No OCR was added. Production must
   run the same crawl after deployment; local results are not production state.
10. **SIS boundary exists; no provider is connected.** `SisProvider`,
    `SisGateway` and `UnavailableSisProvider` keep all identity mapping
    server-side. Personal timetable/grade questions deterministically say
    live SIS access is unavailable instead of reaching the model.
11. **Ask can now call routing.** `AskOperations` resolves canonical
    places/services and calls `RoutingService` before the model. The API
    returns deterministic `places`, `route` and `warnings` components;
    provider distance, duration, geometry and steps are never invented.
12. **The academic calendar is two dates** (`academic_years` start/end).
    Registration, add/drop and exam periods exist only as crawled HTML.
13. **For IT:** the GPU host, its private IP, the firewall rule, and the
    decision on the two `AI_ALLOW_EXTERNAL_*` switches. See
    `deploy/ai/README.md` §7.

## 11. PDF, rich response, routing and SIS contracts

PDF flow is the existing flow, extended at the fetch boundary:

`allow-listed URL → HTTP/SSRF checks → PdfTextExtractor → knowledge_documents → KnowledgeIndexer → KnowledgeBase → sources[]`.

`knowledge_documents` records `content_type`, `document_status`, authority,
page count, retrieval time, optional upstream Last-Modified, content hash and
failure detail. Byte-identical documents are indexed once. Changed content
updates the canonical row and `KnowledgeIndexer` transactionally replaces its
derived chunks, so conflicting active chunk versions are not retained. Page
numbers are emitted only when the parser's page boundary marker occurs in the
retrieved passage. PDF text is fenced as `UNTRUSTED_OFFICIAL_CONTENT`; document
instructions never become system instructions.

Ask's backward-compatible response always keeps `answer` and `sources`, and
may additionally contain `places`, `route`, and `warnings`. `route` is built
only from OSRM output and contains verified origin/destination, provider
distance/duration, geometry, steps, travel mode, status and generation time.
Flutter persists and renders PDF citation metadata, place cards and route
summary cards while retaining plain-text answers.

The SIS integration contract is read-only and authenticated-user scoped. A
provider receives only the server-resolved institutional identity; no API or
client call accepts an arbitrary student ID. IT must provide the approved
transport (REST/internal DB/etc.), service authentication, immutable app→SIS
identity mapping, permitted fields/capabilities, rate and cache rules, audit
and retention policy, error semantics, and test/non-production access before
a real provider can replace `UnavailableSisProvider`.
