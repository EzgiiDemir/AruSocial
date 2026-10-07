<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ARUVERSE AI providers
    |--------------------------------------------------------------------------
    |
    | Production strategy: THE LOCAL MODEL IS PRIMARY. A vLLM server on
    | ARUCAD's own hardware (see deploy/ai/) answers every question, so a
    | student's prompt and their PersonalContext never leave the campus.
    |
    | Flow: primary (Local AI) → optional external fallback (ONLY when the
    | privacy policy below permits it) → deterministic knowledge-only answer.
    |
    | Every provider speaks the OpenAI-compatible /chat/completions contract,
    | so which one answers is configuration, never calling code.
    |
    | Groq was primary until the local server existed. It is still supported
    | — set AI_PROVIDER=groq — but it is no longer the default, and it is
    | never reached with personal data unless someone deliberately turns that
    | on (see 'privacy' below).
    |
    */

    // Primary provider. 'local' — a self-hosted vLLM/Ollama endpoint.
    'provider' => env('AI_PROVIDER', 'local'),

    // Optional fallback, tried ONLY when configured AND allowed by 'privacy'.
    // Empty disables fallback entirely.
    'fallback' => env('AI_FALLBACK_PROVIDER', 'groq'),

    /*
     * Where a question is allowed to be answered.
     *
     * The local model is primary precisely so that student questions stay
     * on ARUCAD hardware. If it is down, the honest degradation is the
     * knowledge-only answer below — NOT quietly posting the same prompt,
     * with the student's department, appointments and club memberships
     * attached, to a third-party API.
     *
     * Both switches default to the private choice. Turning them on is a
     * policy decision, which is why it is one explicit variable each and
     * not a side effect of some other setting.
     */
    'privacy' => [
        // Master switch. False: only self-hosted providers are ever called,
        // whatever AI_PROVIDER / AI_FALLBACK_PROVIDER say.
        'allow_external' => filter_var(
            env('AI_ALLOW_EXTERNAL_PROVIDERS', 'false'),
            FILTER_VALIDATE_BOOLEAN
        ),

        /*
         * Even with external providers allowed, a prompt carrying a named
         * student's own data stays in-house unless this is also on.
         *
         * AiController classifies only prompts that actually attach profile,
         * appointment, club or other student context as personal. Ordinary
         * campus questions may use an allowed external fallback without
         * transmitting that context. Turning BOTH switches on remains the
         * deliberate statement "student context may be sent to a third
         * party" — a choice that must never happen as a side effect.
         */
        'allow_external_with_personal_data' => filter_var(
            env('AI_ALLOW_EXTERNAL_WITH_PERSONAL_DATA', 'false'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    'providers' => [

        // Optional, self-hosted, OpenAI-compatible endpoint (Ollama:
        // http://host:11434/v1, vLLM: http://host:8000/v1). NOT required.
        // With no base URL it is simply "not configured" and skipped.
        'local' => [
            'base_url' => rtrim((string) env('LOCAL_AI_BASE_URL', ''), '/'),
            // vLLM accepts any bearer when started with --api-key; empty is
            // fine on a private network. A local provider is "configured"
            // on its base URL alone.
            'api_key' => env('LOCAL_AI_API_KEY', ''),
            'model' => env('LOCAL_AI_MODEL', ''),
            'verify_ssl' => filter_var(env('LOCAL_AI_VERIFY_SSL', 'false'), FILTER_VALIDATE_BOOLEAN),
            // Longer than Groq's: a 4-bit 9B on one L4 generates slower than
            // a hosted cluster, and cutting it off mid-answer to save five
            // seconds trades a good answer for a degraded one.
            'timeout' => (int) env('LOCAL_AI_TIMEOUT', 60),
            // Per-provider generation overrides; null falls back to the
            // shared defaults below. A local model may want a different
            // temperature from a hosted one without changing both.
            'temperature' => env('LOCAL_AI_TEMPERATURE'),
            'max_tokens' => env('LOCAL_AI_MAX_TOKENS'),
            // Ollama's Qwen3 models otherwise spend the whole token budget
            // in an internal reasoning trace and may return an empty answer.
            // Its OpenAI-compatible endpoint supports reasoning_effort=none.
            'reasoning_effort' => env('LOCAL_AI_REASONING_EFFORT', 'none'),
        ],

        // Primary production provider.
        'groq' => [
            'base_url' => rtrim((string) env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'), '/'),
            'api_key' => env('GROQ_API_KEY', ''),
            'model' => env('GROQ_CHAT_MODEL', 'openai/gpt-oss-20b'),
            'verify_ssl' => filter_var(
                env('GROQ_VERIFY_SSL', env('APP_ENV') === 'local' ? 'false' : 'true'),
                FILTER_VALIDATE_BOOLEAN
            ),
            'timeout' => (int) env('GROQ_TIMEOUT', 25),
            // gpt-oss / qwen are REASONING models: by default their chain of
            // thought ("We need to answer in Turkish… let's check the source")
            // leaks into the answer. 'hidden' makes Groq return ONLY the final
            // reply, and low effort keeps it fast and cheap for short campus Q&A.
            'reasoning_format' => env('GROQ_REASONING_FORMAT', 'hidden'),
            'reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low'),
        ],

    ],

    /*
     * Generation defaults.
     *
     * This is a grounded university assistant: the facts are supplied in
     * the prompt and the model's job is to phrase them. 0.2 keeps it from
     * paraphrasing a fee or a date into something that was never in the
     * source, which is the failure mode that actually hurt here (see
     * AnswerGrounding). Raise it for conversational warmth only with the
     * grounding eval in front of you.
     *
     * 800 tokens is about a page of Turkish prose — enough for a
     * structured comparison, short enough to bound latency and KV cache.
     */
    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 800),

    /*
     * How many conversation turns reach the model.
     *
     * The Flutter client sends the WHOLE thread, which was unbounded: a
     * long conversation grew the prompt until it crowded out the retrieved
     * sources, and on a local model with a fixed context window it would
     * eventually overflow outright.
     *
     * Six messages — three exchanges — was set for the immediate follow-up
     * ("peki ücretler?" means the previous turn's subject) and is too short
     * for the thing students actually do: mention their department, their
     * year or what they are trying to decide early on, and expect the
     * assistant to still have it five questions later. Twelve keeps roughly
     * a whole short consultation in view, which is still far inside the
     * window once the system prompt and the retrieved sources are counted.
     *
     * Counted in messages, oldest dropped first, the newest user message
     * always kept.
     */
    'history_turns' => (int) env('AI_HISTORY_TURNS', 12),

    /*
     * A ceiling on any single message.
     *
     * `history_turns` bounds how many turns a client may send; this bounds how
     * big one of them may be, and the client controls both. PromptBudget can
     * drop older turns to fit the window but never the newest one, so a single
     * very long message would push the rules out of context regardless. 8,000
     * characters is several pages — far more than a question — and a paste
     * longer than that is either an accident or an attack.
     */
    'max_message_chars' => (int) env('AI_MAX_MESSAGE_CHARS', 8000),

    // Transient-failure retry (rate limit / 5xx / timeout). Not for quota or
    // auth errors, which will not succeed on retry.
    'retries' => (int) env('AI_RETRIES', 1),
    'retry_backoff_ms' => (int) env('AI_RETRY_BACKOFF_MS', 400),

    // Circuit breaker: stop hammering Groq while it is quota-exhausted /
    // rate-limited / down. After `failure_threshold` transient failures the
    // circuit opens for `cooldown_seconds`, during which Groq is skipped
    // entirely and requests go straight to the fallback chain. A quota error
    // opens it immediately (quota will not recover in seconds).
    'circuit' => [
        'failure_threshold' => (int) env('AI_CIRCUIT_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('AI_CIRCUIT_COOLDOWN_SECONDS', 120),
        'quota_cooldown_seconds' => (int) env('AI_CIRCUIT_QUOTA_COOLDOWN_SECONDS', 900),
    ],

    // Knowledge-Only degraded mode: when NO AI provider can serve a request,
    // still answer from Site Knowledge + internal data (grounded, never
    // hallucinated), clearly marked as source-based. On by default so an
    // outage degrades instead of going offline.
    'allow_knowledge_only_fallback' => filter_var(
        env('AI_ALLOW_KNOWLEDGE_ONLY_FALLBACK', 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // Reduce Groq usage: cap the grounding context handed to the LLM so entire
    // pages are never sent. The agent + knowledge base already select and
    // de-duplicate; this is the final ceiling in characters.
    'context_budget_chars' => (int) env('AI_CONTEXT_BUDGET_CHARS', 6000),

    /*
     * The context window, and who gets to decide what falls out of it.
     *
     * Every runtime truncates an over-long prompt by dropping the OLDEST
     * tokens — which in this prompt are the rules. Measured on Ollama's
     * default 4096 window, a 6,229-token prompt reached the model as 2,050
     * tokens with the language rule, the no-invented-numbers rule and the
     * prompt-injection fence missing, and nothing reported a problem.
     *
     * So the application budgets instead. `max_prompt_tokens` is what the
     * prompt may occupy; PromptBudget drops retrieved sources first and then
     * the oldest conversation turns, and never the rules. Set it to the model
     * window minus `max_tokens` minus a little headroom:
     *
     *     aicad-qwen3:8b  num_ctx 12288 - 600 generation - ~700 headroom
     *
     * Raise both together when the model window grows; raising this alone
     * just moves the truncation back to the runtime.
     */
    'context' => [
        'max_prompt_tokens' => (int) env('AI_MAX_PROMPT_TOKENS', 11000),
    ],

    /*
     * Answer the cheap questions without a model at all.
     *
     * "Where is the library", "what is on today", "when is my
     * appointment" each have one correct answer in our own tables. On a
     * campus these are most of the traffic, so serving them directly is
     * the single largest reduction in model usage available — and a
     * looked-up fact cannot be hallucinated.
     *
     * Only short, unambiguous questions take this path; see
     * App\Services\Ai\DirectAnswer. Turn it off to send everything to
     * the model, which is slower, costlier and no more correct.
     */
    'direct_answers' => [
        'enabled' => filter_var(env('AI_DIRECT_ANSWERS', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * The campus-wide ceiling on model calls.
     *
     * `throttle:ai` bounds one student to 20 requests a minute and says
     * nothing about what everyone does together: three thousand students
     * at that limit is 60,000 requests a minute aimed at one key. These
     * two numbers are what keep a shared free quota alive for a whole
     * campus, and exceeding either degrades to knowledge-only mode
     * rather than failing.
     *
     * `max_concurrent` is the one that matters minute to minute: a
     * lecture ending puts hundreds of questions into the same few
     * seconds, and without it every one of them occupies a PHP worker
     * waiting on a provider that answers a handful at a time. 8 is a
     * starting point, not a measurement — raise it once the load test in
     * docs/AI_AND_SCALE_PLAN.md has run against real hardware.
     *
     * `daily_requests` is the quota guard. 0 disables it. Set it below
     * the provider's real daily allowance, leaving room for the day's
     * remaining hours.
     */
    'budget' => [
        'enabled' => filter_var(env('AI_BUDGET_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'max_concurrent' => (int) env('AI_MAX_CONCURRENT', 8),
        'daily_requests' => (int) env('AI_DAILY_REQUESTS', 0),
    ],

    /*
     * Which personal-data systems are actually connected.
     *
     * Read by App\Services\Ai\PersonalDataCapabilities. Everything backed by
     * a student information system is false, because ARUCAD's SIS is not
     * integrated with this assistant — and a student asking for their
     * timetable gets told exactly that, in their own language, rather than a
     * plausible invention or a request to supply their own timetable.
     *
     * Flip a row to true ONLY when there is a real authenticated integration
     * behind it. These flags are the assistant's statement about what it can
     * see; a true here with nothing behind it is the system lying about
     * itself. When one does flip, the capability stops being answered by the
     * deterministic layer and flows on to whatever serves it.
     *
     * The last four are already connected — they come from our own tables via
     * PersonalContext — and are listed so the registry describes the whole
     * picture rather than only the gaps.
     */
    'capabilities' => [
        // NOTE: `timetable` and `enrolment` are NOT read from here. They are
        // served by App\Services\Sis\SisProvider, so PersonalDataCapabilities
        // asks the gateway whether it is available rather than duplicating the
        // switch. Bind a real provider in AppServiceProvider and they light up.
        'grades' => filter_var(env('AI_CAPABILITY_GRADES', 'false'), FILTER_VALIDATE_BOOLEAN),
        'exams' => filter_var(env('AI_CAPABILITY_EXAMS', 'false'), FILTER_VALIDATE_BOOLEAN),
        'attendance' => filter_var(env('AI_CAPABILITY_ATTENDANCE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'advisor' => filter_var(env('AI_CAPABILITY_ADVISOR', 'false'), FILTER_VALIDATE_BOOLEAN),
        'transcript' => filter_var(env('AI_CAPABILITY_TRANSCRIPT', 'false'), FILTER_VALIDATE_BOOLEAN),
        'balance' => filter_var(env('AI_CAPABILITY_BALANCE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'discipline' => filter_var(env('AI_CAPABILITY_DISCIPLINE', 'false'), FILTER_VALIDATE_BOOLEAN),
        'library_loan' => filter_var(env('AI_CAPABILITY_LIBRARY_LOAN', 'false'), FILTER_VALIDATE_BOOLEAN),

        // Served today from our own tables by PersonalContext.
        'profile' => filter_var(env('AI_CAPABILITY_PROFILE', 'true'), FILTER_VALIDATE_BOOLEAN),
        'appointments' => filter_var(env('AI_CAPABILITY_APPOINTMENTS', 'true'), FILTER_VALIDATE_BOOLEAN),
        'clubs' => filter_var(env('AI_CAPABILITY_CLUBS', 'true'), FILTER_VALIDATE_BOOLEAN),
        'events' => filter_var(env('AI_CAPABILITY_EVENTS', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    // Response cache for repeated, non-personalized questions. Keyed on the
    // normalized question + language + a knowledge-freshness stamp, so a new
    // crawl naturally invalidates stale answers. Multi-turn (conversation
    // history) requests are never cached.
    /*
     * Reading the open web at question time.
     *
     * OFF by default, and off is a real state rather than a broken one: with
     * no key the assistant says it cannot search instead of implying it
     * searched and found nothing. Those are different facts for a student
     * deciding whether to go and look themselves.
     *
     * Needs a Brave Search API key (https://brave.com/search/api/). Nothing
     * else about this feature is Brave-specific; see
     * App\Services\Web\WebSearchProvider.
     *
     * The caps matter more here than anywhere else in the product, because
     * this is the one path where a student's question causes requests to
     * hosts we did not choose.
     */
    'web_research' => [
        'enabled' => filter_var(env('AI_WEB_SEARCH_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // 'tavily' (default) or 'brave'. Tavily returns extracted page
        // text with each result; Brave returns links only and the research
        // service then fetches them itself.
        'provider' => env('AI_SEARCH_PROVIDER', 'tavily'),
        'key' => env('TAVILY_API_KEY', env('BRAVE_SEARCH_KEY', '')),
        // Tavily only: 'basic' is one pass, 'advanced' is slower and deeper.
        'depth' => env('AI_WEB_SEARCH_DEPTH', 'basic'),
        // How many of the ranked results are actually opened and read.
        'max_pages' => (int) env('AI_WEB_MAX_PAGES', 3),
        // Per page, so three pages cannot crowd out ARUCAD's own sources in
        // the context window.
        'chars_per_page' => (int) env('AI_WEB_CHARS_PER_PAGE', 1200),
        'timeout' => (int) env('AI_WEB_TIMEOUT', 8),
        'max_bytes' => (int) env('AI_WEB_MAX_BYTES', 2000000),
        // A class asking the same thing in the same hour costs one fetch.
        'cache_minutes' => (int) env('AI_WEB_CACHE_MINUTES', 60),
    ],

    'cache' => [
        'enabled' => filter_var(env('AI_CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        'ttl_minutes' => (int) env('AI_CACHE_TTL_MINUTES', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query routing (QueryPlanner)
    |--------------------------------------------------------------------------
    |
    | How many live database tools one question may pull into the prompt, and
    | which run when the planner recognises no domain at all. A fallback is
    | flagged in the trace, so a miss is visible instead of looking like an
    | answer. The vocabulary itself lives in QueryPlanner::LEXICON and the
    | operator-maintained aliases in ai_entity_aliases.
    |
    */
    'routing' => [
        'max_tools' => (int) env('AI_ROUTING_MAX_TOOLS', 4),
        'fallback_tools' => ['places', 'events', 'services'],
    ],

    /*
    | Diagnostics (Search Playground, `php artisan ask:diagnose`): how many
    | knowledge candidates a verbose trace lists with their score breakdown.
    */
    'trace' => [
        'knowledge_candidates' => (int) env('AI_TRACE_KNOWLEDGE_CANDIDATES', 10),
    ],

    /*
    | Evaluation (`php artisan ask:evaluate`, admin → AICAD Tests): the user a
    | full-answer case runs as when the case names none and nobody signed in
    | started the run (the answer path is authenticated). Empty: such cases
    | are skipped with that reason rather than run as someone arbitrary.
    */
    /*
    | Task planning (Phase 3A): compositional questions are decomposed into
    | tasks with dependencies, evidence requirements and provider routes
    | before answering. Single-intent questions stay on the fast paths either
    | way. A rollout switch, not a second architecture: false restores the
    | previous behaviour without a code change.
    */
    'task_planning' => [
        'enabled' => filter_var(env('AICAD_TASK_PLANNING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Evidence orchestration (Phase 3B): for a planned question, every
    | evidence requirement is answered by provider evidence with provenance,
    | temporal validity, a per-fact policy and conflict resolution; task
    | states follow evidence coverage. `budget_chars` bounds the task-evidence
    | block given to the model. false restores Phase 3A behaviour.
    */
    'evidence' => [
        'enabled' => filter_var(env('AICAD_EVIDENCE_ORCHESTRATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'budget_chars' => (int) env('AICAD_EVIDENCE_BUDGET_CHARS', 1200),
    ],

    /*
    | Supported facts (Phase 3C). Facts and the answer plan are always built
    | when Phase 3B runs (deterministic, sub-millisecond). The mode decides
    | whether a planned question is GENERATED from them: a fact-bounded
    | prompt, claim verification against the facts, and citations limited
    | to the facts the answer used.
    |   off         Phase 3B generation (the rollback)
    |   staff_only  only for users with back-office access (existing roles)
    |   on          every planned question
    | true/false are still accepted (on/off).
    */
    'supported_facts' => [
        'mode' => (static function (): string {
            $raw = strtolower(trim((string) env('AICAD_SUPPORTED_FACT_GENERATION_ENABLED', 'off')));

            return match (true) {
                in_array($raw, ['staff_only', 'staff'], true) => 'staff_only',
                filter_var($raw, FILTER_VALIDATE_BOOLEAN) || $raw === 'on' => 'on',
                default => 'off',
            };
        })(),
    ],

    /*
    | The campus clock, for "is it open now". The application runs in UTC;
    | ARUCAD is in Kyrenia.
    */
    'campus_timezone' => env('AICAD_CAMPUS_TIMEZONE', 'Europe/Nicosia'),

    'evaluation' => [
        'user_email' => env('AI_EVALUATION_USER', ''),
    ],

];
