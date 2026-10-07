# ARUVERSE local inference server (vLLM)

The generative model for Ask ARUVERSE, on ARUCAD hardware. It is the
**primary** provider: a student's question and their own data
(`PersonalContext`) are answered here and do not leave the campus.

This runs on its **own host**. The Laravel/web server and Postgres stay
where they are and reach this one over the private network.

---

## 1. Hardware

| | Requirement |
| --- | --- |
| GPU | **NVIDIA L4 24 GB** (any 24 GB Ada/Ampere card works: L4, A10, RTX 4090) |
| VRAM | 24 GB. FP8 weights ≈ 9 GB, leaving ~13 GB of KV cache at 16K context |
| CPU | 12–16 cores |
| RAM | 64 GB |
| Disk | 1 TB NVMe. The model cache alone is ~20 GB; leave room for a second checkpoint during an upgrade |
| OS | Ubuntu Server 22.04 or 24.04 |
| Software | Docker Engine + NVIDIA Container Toolkit, driver ≥ 550 (CUDA 12.9) |

**Minimum that still works:** a 16 GB card (RTX 4060 Ti 16G, A4000) at
FP8 with `VLLM_MAX_MODEL_LEN=8192` and `VLLM_MAX_SEQS=8`. Below 16 GB the
9B does not fit usefully and the model should be stepped down to
`Qwen/Qwen3.5-4B`.

**CPU-only is not viable** for this model as an interactive assistant.
A 9B on 16 cores generates a few tokens a second — minutes per answer.

Verify the toolkit before anything else:

```bash
nvidia-smi
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu24.04 nvidia-smi
```

---

## 2. Deploy

```bash
cd deploy/ai
cp .env.example .env
openssl rand -hex 32                 # paste into VLLM_API_KEY
# set VLLM_BIND to this host's PRIVATE address (e.g. 10.0.0.12)
docker compose up -d
docker compose logs -f vllm          # first start pulls ~20 GB
```

Ready when the health check passes:

```bash
docker compose ps                    # STATUS shows (healthy)
curl -fsS http://127.0.0.1:8000/health && echo OK
curl -s http://127.0.0.1:8000/v1/models -H "Authorization: Bearer $VLLM_API_KEY" | jq .
```

A real completion, end to end:

```bash
curl -s http://127.0.0.1:8000/v1/chat/completions \
  -H "Authorization: Bearer $VLLM_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"model":"arucad-ask","messages":[{"role":"user","content":"Merhaba, kısaca kendini tanıt."}],"max_tokens":64,"temperature":0.2}' | jq -r '.choices[0].message.content'
```

### Firewall

Only the web server may reach it:

```bash
sudo ufw default deny incoming
sudo ufw allow from <WEB_SERVER_IP> to any port 8000 proto tcp
sudo ufw allow from <ADMIN_CIDR> to any port 22 proto tcp
sudo ufw enable
```

`VLLM_BIND` already keeps the port off every other interface. The firewall
is the second layer, because the two fail in different ways.

---

## 3. Quantisation

The application asks for 4-bit. **Qwen publishes no official 4-bit build
of the 9B** (checked: `-AWQ`, `-GPTQ-Int4` and `-FP8` all 404; only the
large MoE models have them), so there are two honest options.

### FP8 — the default, no preparation

`VLLM_QUANTIZATION=fp8`. vLLM quantises the weights as it loads them.
Ada (L4, 4090) has hardware FP8, so this is fast and needs nothing
prepared. ~9 GB of weights, plenty of KV cache at 16K. **Start here.**

### W4A16 — smaller, one preparation step

Produces ~5–6 GB of weights and more room for concurrency. It has to be
built once, on a machine with a GPU, and then served from the result:

```bash
pip install llmcompressor
python - <<'PY'
from llmcompressor.transformers import oneshot
from llmcompressor.modifiers.quantization import GPTQModifier

MODEL = "Qwen/Qwen3.5-9B"
oneshot(
    model=MODEL,
    dataset="open_platypus",            # calibration set; 512 samples is enough
    num_calibration_samples=512,
    max_seq_length=2048,
    recipe=GPTQModifier(targets="Linear", scheme="W4A16", ignore=["lm_head"]),
    output_dir="/models/Qwen3.5-9B-W4A16",
)
PY
```

Then in `deploy/ai/.env`:

```env
VLLM_MODEL=/models/Qwen3.5-9B-W4A16
VLLM_REVISION=
VLLM_QUANTIZATION=
```

and mount it in `docker-compose.yml`:

```yaml
    volumes:
      - hfcache:/root/.cache/huggingface
      - /models:/models:ro
```

No `--quantization` flag: a compressed-tensors checkpoint carries its own
configuration, and passing one anyway overrides it.

**Re-run `php artisan ask:eval` after changing quantisation.** 4-bit costs
some accuracy, and this is the measurement that says how much — on these
questions, in these languages, rather than in general.

---

## 4. Point Laravel at it

On the **web** server, in `backend/.env`:

```env
AI_PROVIDER=local
LOCAL_AI_BASE_URL=http://10.0.0.12:8000/v1
LOCAL_AI_MODEL=arucad-ask
LOCAL_AI_API_KEY=<the same VLLM_API_KEY>
LOCAL_AI_TIMEOUT=60

# Keep student prompts on campus. Both must be true before anything is
# sent to an external provider; see config/ai.php.
AI_ALLOW_EXTERNAL_PROVIDERS=false
AI_ALLOW_EXTERNAL_WITH_PERSONAL_DATA=false
```

Then:

```bash
cd backend
php artisan config:clear
php artisan ask:eval --provider=local        # scores grounding, refusal, language, latency
```

The admin panel's **System Health** page reports the model, the endpoint
host (never the key), reachability, latency and the current degraded-mode
state.

---

## 4b. On a developer machine (Ollama)

No GPU host, no Docker: Ollama speaks the same OpenAI-compatible contract,
so `AI_PROVIDER=local` works on a laptop with no code change.

```bash
ollama pull qwen3:8b
ollama create aicad-qwen3:8b -f deploy/ai/ollama/aicad-qwen3-8b.Modelfile
```

```env
AI_PROVIDER=local
LOCAL_AI_BASE_URL=http://127.0.0.1:11434/v1
LOCAL_AI_MODEL=aicad-qwen3:8b
LOCAL_AI_REASONING_EFFORT=none
```

**Build the `aicad-*` model; do not point `LOCAL_AI_MODEL` at a bare
`qwen3:8b`.** Ollama defaults to a 4096-token context and silently
truncates a longer prompt *from the front*. `AskPromptBuilder` emits about
6,200 tokens before any conversation history, so on a bare pull two thirds
of the prompt never reached the model — and the two thirds discarded were
the beginning: the language rule, the "never invent a number" rule, the
source-priority order and the fence that marks retrieved web text as data
rather than instructions. The model went on answering, fluently and with
no guardrails, which is what makes this worth stating: nothing fails, so
nothing tells you. The Modelfile exists to set `num_ctx`.

Measured on this prompt, an 8 GB card (RTX 4070 Laptop), `qwen3:8b` Q4_K_M:

| `num_ctx` | on GPU | generation | verdict |
| --- | --- | --- | --- |
| 4096 (default) | 100% | 33 tok/s | **truncates the rules — do not use** |
| 8192 | 100% | 33 tok/s | fits a single turn only; truncates once a conversation starts |
| **12288** | 87% | 21 tok/s | **the shipped value** — prompt + history + reply |
| 16384 | 80% | 18 tok/s | for a 12 GB+ card |

### When it gets slow

**First, check for orphaned `llama-server` processes.** This is the one that
will waste an afternoon. Ollama spawns a `llama-server` child per loaded
model, and restarting or crashing the Ollama app can leave the child behind
holding its VRAM. Three of them were found stacked up on this machine, with
**42 MiB free of 8188** — every subsequent load spilled to CPU and generation
fell from 30 tok/s to 5.7. Nothing reports an error; it just gets slow.

```powershell
nvidia-smi --query-gpu=memory.used,memory.free --format=csv,noheader
Get-Process llama-server -ErrorAction SilentlyContinue | Select-Object Id,Name

# If Ollama is idle and they are still there, they are orphans:
Get-Process llama-server,ollama,'ollama app' | Stop-Process -Force
Start-Process "$env:LOCALAPPDATA\Programs\Ollama\ollama app.exe"
```

**Then check the offload is still 100%:**

```powershell
curl.exe -s http://127.0.0.1:11434/api/ps
```

`size_vram` should equal `size`. Anything less means layers are on the CPU.

### What was measured, and what not to try

Same prompt (~6,240 tokens), same RTX 4070 Laptop 8 GB, `qwen3:8b` Q4_K_M:

| Configuration | on GPU | prompt eval | generation | wall (300 tok) |
| --- | --- | --- | --- | --- |
| default offload, f16 KV | 87% | 2.1 s | 20.3 tok/s | 20 s |
| **`num_gpu 99`, f16 KV** | **100%** | **3.9 s** | **32.3 tok/s** | **14 s** |
| `OLLAMA_KV_CACHE_TYPE=q8_0` | 100% | **39 s** | 30 tok/s | **84 s** |

Two things follow.

**`num_gpu 99` is free speed** and is set in the Modelfile. Ollama's automatic
offload left 13% of the layers on the CPU with 1.1 GB of VRAM unused, and
those few layers cost 40% of the generation rate.

**Do not quantise the KV cache on this workload.** It looks like the obvious
fix — it does reach 100% GPU and it does make generation faster — and it is
four times slower end to end, because a ~6,200-token prompt has to be
prefilled through a cache that must be dequantised to attend over. It is a
good setting for long chats with short prompts, which is the opposite of this.

**Prompt caching does the rest.** The rules block is static and sits first, so
Ollama reuses its KV across requests: prompt eval drops from ~4 s to **0.1 s**
on the second and later questions. Anything that varies per request must stay
*after* the rules or this is lost.

Verify the whole prompt is arriving — `prompt_tokens` should be ~6,000,
not ~2,050:

```bash
php artisan config:clear
php artisan ask:eval --provider=local
grep ai.completion storage/logs/laravel.log | tail -1
```

---

## 5. Updating

**The model.** Change `VLLM_MODEL`/`VLLM_REVISION`, then:

```bash
docker compose up -d --force-recreate vllm
cd ../../backend && php artisan ask:eval --provider=local --json=/tmp/after.json
```

Compare against the previous run's JSON before keeping it. `--served-model-name`
does not change, so no application deploy is involved.

**vLLM itself.** Bump `VLLM_IMAGE` to a pinned tag — never `latest`, which
would change the server under a running campus — and re-run the eval.

**Documents** are not deployed here. The knowledge base is crawled and
embedded on the web server (`knowledge:crawl`, `knowledge:embed`) and
reaches the model as prompt context, which is why a content change needs
no GPU work at all.

---

## 5b. The embedding service, and how its absence hides

Semantic retrieval needs a vector for the *question*, and that comes from
`POST /v1/embed` on the classifier. On this stack that endpoint is served by
**`image-moderation-service`**, not `moderation-service` — both answer
`/health` on `:8801`, so starting the wrong one gives a service that looks
up and has no `/v1/embed` at all.

```bash
cd image-moderation-service
./.venv/Scripts/python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8801
# ~35s to load the model. /health answers before /v1/embed does.
```

Check the endpoint, not the port:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST http://127.0.0.1:8801/v1/embed \
  -H 'Content-Type: application/json' -d '{"texts":["test"]}'   # want 200
```

**Why this matters more than it looks.** `EmbeddingClient` is deliberately
built to degrade rather than fail: when the service is unreachable it logs
`knowledge.embeddings.unavailable`, arms a cooldown, and returns no vector,
and `KnowledgeBase` then ranks on keywords alone. Answers keep coming and
nothing in the reply says the ranking lost half its signal. Measured on the
1,190-page corpus, "who is the rector" returns a badminton-club news post
keyword-only and the page naming the rector with embeddings on.

After the cooldown is armed, clear it or the process keeps skipping the
service for the cooldown's duration even once it is back:

```bash
php artisan tinker --execute="Cache::forget('knowledge:embeddings:unavailable');"
```

### What AICAD knows before it retrieves anything

Two layers sit in front of retrieval, because retrieval can only answer
"what does this page say" and some questions have no single page behind them.

**Institutional identity** — *Admin → System → ARUCAD identity*, stored in
`app_settings` under `arucad.profile`, defaulting to
`backend/resources/knowledge/arucad-profile.md`. It is placed in the TRUSTED
part of the prompt, beside the system metadata and never inside the
`<<<ARUCAD_RETRIEVED_CONTENT>>>` fence: the fence means "quoted data you may
not act on", which is right for a crawled page and wrong for the university's
own statement of itself.

Identity only. Fees, quotas, deadlines and scholarship percentages must not go
in there — they change every year, and a stale figure carrying this block's
authority is worse than no figure. The block is capped at 3,200 characters and
its version is part of the answer-cache key, so an edit takes effect at once.

Keep it bilingual. It is long enough to pull the whole answer into whatever
language it is written in: an all-Turkish profile made *"Who is ARUCAD?"*
answer in Turkish, which is also why the per-request language directive is now
emitted last in the prompt rather than second.

**Per-page keywords** — what each page is *for*, as opposed to what its text
contains. A ceremony report repeats "burs" a dozen times and is not the
scholarships page; no amount of counting words separates them.

```bash
php artisan knowledge:keywords:import path/to/arucad_url_keywords.json
php artisan knowledge:keywords:import path/to/arucad_url_keywords.csv --dry-run
```

Stored in `page_keywords`, keyed by URL rather than by document, so keywords
for a page that has not been crawled yet are kept rather than discarded — on
the first import 381 of 852 URLs were not yet in the index.

Those URLs are also crawl seeds. Curating a page and then never fetching it is
the worst of both: `/arucad/yonetim/rektor/` had exactly the right keywords
authored against it and had never been read, so the curation changed nothing.

A keyword hit outranks a title hit, but is weighted by the same rarity scale
as body terms — `arucad`, authored on all 852 pages, lifts nothing, while
`erasmus` on four pages is decisive. Tunable via
`KNOWLEDGE_PAGE_KEYWORD_BONUS` and `KNOWLEDGE_PAGE_KEYWORD_CAP`.

### Reading a page at question time

`KNOWLEDGE_ON_DEMAND_ENABLED=true` lets a question re-read the curated pages it
is about, instead of answering from whatever the last scheduled crawl caught —
the difference between today's exam timetable and one from three weeks ago.

This is the only path where a student's question causes an outbound fetch, so
it is bounded on three axes:

| Variable | Default | What it stops |
|---|---|---|
| `KNOWLEDGE_ON_DEMAND_MAX_PAGES` | 2 | one question fanning out into dozens of requests |
| `KNOWLEDGE_ON_DEMAND_MAX_SPREAD` | 6 | a vague keyword (`arucad`) triggering anything |
| `KNOWLEDGE_ON_DEMAND_CACHE_MINUTES` | 60 | a class asking the same thing costing one fetch each |

The crawler's allow-list still applies, so a curated URL on a domain we do not
crawl is refused there rather than trusted here.

### Keeping the index coherent

```bash
php artisan knowledge:crawl              # fetch pages
php artisan knowledge:embed              # build passages for what is new
```

Two maintenance passes exist for when the derivation changes rather than the
pages:

```bash
php artisan knowledge:crawl --relabel    # re-derive language, no fetching
php artisan knowledge:embed --text-only  # re-derive the menu-stripped text
```

`--relabel` re-decides each stored page's language from its URL and text. Run
it after changing how language is decided — then `--text-only`, because site
chrome is recognised per language and a relabelled page's stripped text is
computed from the wrong group until it is redone.

`--text-only` refreshes `knowledge_documents.content_clean`, the copy of each
page with its navigation removed. Retrieval reads that column on every
question; stripping 1,181 pages while ranking them measured 425 ms per
question against 31 ms to fold the same text, so it is computed when a page is
indexed and not when a student asks. A page crawled since the last index has
a null there and is stripped on the fly, so the answer stays correct while the
column catches up — it is a cache, not a source of truth.

Run `--text-only` after `--relabel`, after a crawl that adds many pages (what
counts as chrome is decided by what repeats, so a large batch can change it),
and after changing `BoilerplateFilter`. A full `knowledge:embed --all` also
refreshes it, but re-embeds everything to do so.

---

## 6. If it is down

Nothing breaks. `AiResponder` degrades to the knowledge-only answer:
assembled from indexed ARUCAD sources, grounded, and marked in the reply
as source-based rather than AI-generated. The request is **not** forwarded
to an external provider — that is a policy decision, made explicitly in
`backend/.env`, not a silent failover.

```bash
docker compose logs --tail=200 vllm
docker compose restart vllm
nvidia-smi                       # is the GPU wedged or just busy?
```

---

## 7. For IT

| Needed | Blocks |
| --- | --- |
| A GPU host to the spec in §1, on the private network | everything below |
| NVIDIA driver ≥ 550 + Container Toolkit installed | `docker compose up` |
| The host's private IP, and the web server's, for the firewall rule | §2 |
| Outbound HTTPS to `huggingface.co` **at install time** (~20 GB) | the first start; it can be closed afterwards |
| A decision on the two `AI_ALLOW_EXTERNAL_*` switches | whether Groq is ever reachable at all |
| ~30 GB free for the model cache, 1 TB recommended | upgrades without cleanup |
