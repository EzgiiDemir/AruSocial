# ARUVERSE self-hosting — implementation report

Written for: the ARUVERSE engineering team. What was **implemented** (not
planned) to move ARUVERSE onto ARUCAD-owned infrastructure and minimise
external dependencies.

## 1. What was implemented

- **AI provider abstraction (local-first).** `config/ai.php` + `App\Services\Ai\*`.
  An OpenAI-compatible provider layer with a self-hosted `local` provider
  (Ollama/vLLM/LM Studio) preferred over an optional `groq` fallback. When a
  local endpoint is set, ARUVERSE answers with **no external LLM**. `AiController`
  no longer calls Groq directly — it goes through `AiProviderManager::active()`.
- **ARUVERSE Agent.** `App\Services\Agent\AruverseAgent` — a controlled intent
  router with an explicit, fixed, read-only tool table (knowledge, places,
  events, clubs, sports, services, food, shuttle, career). It picks the sources
  relevant to a question, gathers grounded facts, cites website source URLs, and
  the model only phrases them. Not autonomous; cannot grant itself tools.
- **Crawler hardening + features.** `App\Services\Knowledge\*`:
  SSRF guard (`UrlSafety`: allow-list + public-IP resolution, blocks
  private/loopback/link-local), redirect cap, sitemap discovery, broken-link
  tracking (`fail_count`, `last_error`), and deleted-page detection (404/410 →
  `is_stale`, kept for history). Unchanged pages are not rewritten.
- **Search ranking.** `KnowledgeBase` now scores title/heading, content, URL
  path, language relevance, freshness, demotes stale pages, and suppresses
  duplicate content — all on our own DB, no external search/RAG.
- **Admin integrations panel.** Registered the internal services with **real
  health checks**: `local_ai`, `agent`, `notifications`, `logging`,
  `site_knowledge`, plus a real SMTP socket test for `mail`. WordPress is marked
  **Disabled (not in use)**; Sentry and FCM relabelled as optional/delivery-only.
- **OSRM.** Public third-party fallback is now opt-in
  (`ROUTING_ALLOW_PUBLIC_FALLBACK`, default **false**). A self-hosted OSRM that is
  down yields an honest 501, never a silent external call.

## 2. Changed / new files

New: `config/ai.php`, `config/knowledge.php` (crawler),
`app/Services/Ai/{AiProvider,AiHealth,OpenAiCompatibleProvider,LocalAiProvider,GroqProvider,AiProviderManager}.php`,
`app/Services/Agent/AruverseAgent.php`,
`app/Services/Knowledge/{SiteKnowledgeCrawler,HtmlTextExtractor,KnowledgeBase,UrlSafety}.php`,
`app/Models/KnowledgeDocument.php`, `app/Console/Commands/CrawlSiteKnowledge.php`,
two `knowledge_documents` migrations, `integration_states` migration + model +
`IntegrationRegistry`/`IntegrationsController` + Filament page,
tests (`SiteKnowledgeCrawlerTest`, `AiProviderAndAgentTest`, `AdminIntegrations*`).
Modified: `AiController` (provider+agent), `IntegrationRegistry` (internal
services, WordPress disabled, SMTP test), `RoutingService`/`config/services.php`
(OSRM opt-in), `routes/console.php` (crawl schedule), `sql/schema.sql`,
`docs/API_CONTRACT.md`, `HealthController` (earlier: URL leak fix).

## 3. Status by area

| Area | Status |
|---|---|
| **Crawler** | Self-hosted. Scheduled twice daily; sitemap + link discovery; SSRF-guarded; broken/deleted tracking; source URL preserved. |
| **Search** | Self-hosted keyword+ranking over our DB (title/heading/URL/lang/freshness/dedup). No external search. Hybrid/semantic left as an optional future step (would need a self-hosted embedding model to stay dependency-free). |
| **Agent** | Implemented, controlled, explicit fixed toolset, cites sources, grounded ("only use provided sources"). |
| **Local AI** | Implemented. Set `LOCAL_AI_BASE_URL` (+ optional `AI_PROVIDER=local`) and ARUVERSE runs without Groq. Groq is now optional. |
| **SMTP** | Config-driven to ARUCAD's own server; real socket connection test in the panel; credentials only from `.env`; never shown/logged. Queue+retry+mail-log already exist. |
| **Notifications** | Core is ARUCAD-owned: in-app inbox + Laravel Reverb (self-hosted WebSocket) for realtime. **Firebase removed as the core.** |
| **Firebase** | Not the core. Remains available ONLY as the OS-level delivery channel for push while the app is fully closed (see §4). |
| **OSRM** | Self-hosted endpoint via `ROUTING_BASE_URL`; public fallback opt-in/off; unavailable → honest error, no fabricated route. |
| **Sentry / logging** | Sentry optional (empty DSN = off). Local logging always on; `logging` integration surfaces it in the panel. System works fully without Sentry. |
| **Admin integrations** | Real health checks (not just env-presence) for testable services; internal services listed; secrets masked; WordPress Disabled. |

## 4. Unavoidable platform dependency (stated plainly)

**OS-level push while the app is fully closed** must traverse Apple (APNs) and
Google (FCM) — those transports are controlled by Apple/Google and cannot be
self-hosted. ARUVERSE now owns everything else about notifications (creation,
targeting, templates, inbox, history, realtime via Reverb); Firebase/APNs is
reduced to the final delivery hop only, and the app degrades to the in-app
inbox when it is absent.

## 5. External dependency audit (#11)

| Dependency | Class | Action |
|---|---|---|
| Local AI (Ollama/vLLM) | ARUCAD self-hosted | **Added** as preferred AI |
| Crawler / Search / Agent / Notifications core / Logging | ARUCAD self-hosted | Ours |
| OSRM (self-hosted) | ARUCAD self-hosted | Public fallback made opt-in/off |
| Groq | Optional external | Now fallback only; local preferred |
| OpenAI moderation | Optional external | Already off by default |
| Sentry | Optional external | Optional adapter; off by default |
| open-meteo (weather) | Optional external, keyless | Kept; degrades to null on failure |
| Microsoft Entra | Unavoidable (identity) | Required only if MS login is kept |
| APNs / FCM transport | Unavoidable (OS push) | Delivery hop only; not core |
| AWS SES/SQS, Postmark, Resend, Slack | Unused / config default | Not active (queue=database, mail=SMTP) |
| WordPress | Removed from scope | Disabled in panel; architecture kept |

## 6. Manual infrastructure steps (not code)

- Stand up a local OpenAI-compatible LLM and set `LOCAL_AI_BASE_URL` (+ model).
- Run a self-hosted OSRM and set `ROUTING_BASE_URL`.
- Set ARUCAD SMTP `MAIL_*` in the server `.env`.
- Run `php artisan knowledge:crawl` once (then it self-schedules).
- Run `php artisan reverb:start` (systemd) for realtime notifications.
- Run the queue worker for mail/notification retry.
