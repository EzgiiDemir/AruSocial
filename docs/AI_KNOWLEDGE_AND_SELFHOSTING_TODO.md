# Ask ARUVERSE knowledge bot + self-hosting — status & TODO

Written for: the ARUVERSE engineering team. Covers what was built now, the
integration gap from the admin panel, and an honest plan for "run our own
systems instead of external providers."

---

## 1. Done in this change — the self-hosted knowledge bot

A crawler that reads the **public** ARUCAD websites into our own database and
feeds Ask ARUVERSE current content. No external search/RAG/crawler service.

| Piece | File |
|---|---|
| Crawl config (URLs, allowed domains, excluded paths) | `backend/config/knowledge.php` |
| Crawler | `backend/app/Services/Knowledge/SiteKnowledgeCrawler.php` |
| HTML→text | `backend/app/Services/Knowledge/HtmlTextExtractor.php` |
| Retrieval into the AI prompt | `backend/app/Services/Knowledge/KnowledgeBase.php` |
| Storage | `knowledge_documents` table + `KnowledgeDocument` model |
| Command | `php artisan knowledge:crawl` (`--force` to ignore freshness) |
| Schedule | twice daily (04:00, 16:00) in `routes/console.php` |
| Admin visibility | registered as integration `site_knowledge` in the panel |
| AI wiring | `AiController::systemPrompt()` injects matched snippets, with source URLs |

**How it stays current:** the scheduler re-crawls twice a day; a page whose
text is unchanged is skipped, so runs are cheap. When a student asks Ask
ARUVERSE something, the top matching page snippets are added to the prompt with
their source URL, so answers cite the live site.

**Scope guarantees (tested):** only the 6 allow-listed public domains; never a
path under `/wp-admin`, `/login`, `/account`, etc.; `sis.arucad.edu.tr` is not
allow-listed at all. The bot reads only what a visitor sees — never student or
account data.

**Verified:** 8/8 crawler tests pass; a live crawl of `arucad.edu.tr` and
`aday.arucad.edu.tr/neden-arucad/` extracted real text and retrieval returned a
correctly-sourced snippet. Test data was cleared afterward.

**To turn it on in production:** run `php artisan knowledge:crawl` once (or wait
for the schedule). Until then the `site_knowledge` integration shows *Not
Configured* — honestly, because no pages are stored yet.

### First real crawl — do this on the server
```bash
php artisan knowledge:crawl        # ~60–120 pages, a few minutes
php artisan tinker --execute="echo App\Models\KnowledgeDocument::count();"
```
Rollback: `php artisan migrate:rollback --step=1` drops only `knowledge_documents`.

---

## 2. Integration gap (from the admin panel, today)

| Integration | Status | Missing |
|---|---|---|
| campus_directory (360) | Connected | — |
| groq (Ask ARUVERSE LLM) | Connected | — |
| routing (OSRM) | Connected | — |
| openai_moderation | Connected | (off by default, by design) |
| **site_knowledge** | Not Configured | run first crawl (new, this change) |
| entra | Not Configured | tenant/client id (or set in panel) |
| wordpress | Not Configured | site URL + API token |
| local_moderation | Not Configured | classifier binary path |
| fcm (push) | Not Configured | Firebase service account |
| mail (SMTP) | Not Configured | SMTP host/user/pass |
| sentry | Not Configured | DSN |

---

## 3. apply.arucad.edu.tr inspection (public REST, no login)

The authenticated wp-admin login was **blocked by the environment's security
guard** (it refused to run a command containing the pasted password). I did not
work around it. Instead I read the site's **public** WP REST API, which is more
useful for integration planning:

- Site: "ARUCAD Application Platform", WordPress.
- REST namespaces: `wp/v2`, `su-cmb/v1`, `oembed`, site-health, block-editor.
  **There is no `wpforms/v1` namespace** and no forms/applications REST route.
- Post types exposed: only core WP (`posts`, `pages`, `media`, templates…).

**Implication for our `wordpress` integration:** our code expects a WPForms /
token endpoint that this site does not currently expose over REST. So either
(a) a plugin must expose the application data over REST with a token, or (b) our
integration should target the standard `wp/v2` content it *does* expose. This is
a real decision, listed in the TODO below. **No change was made to the WP site.**

---

## 4. "Run our own systems" — TODO, with honest constraints

Ordered by how cleanly they can actually be self-hosted.

### 4a. Fully self-hostable — recommended to build
- [ ] **Own SMTP mail.** We do not need an external email API. Point
  `MAIL_MAILER=smtp` at ARUCAD's own mail server (`mail.arucad.edu.tr` or the
  `support@arucad.edu.tr` mailbox's server) in `.env`. The code already supports
  this — it's configuration, not new code. The `mail` integration flips to
  Connected once host/user/pass are set. *Needs: SMTP host, port, and the
  mailbox credentials (do not commit them).* 
- [ ] **Own content/knowledge (done above).** Extend crawl coverage as new
  public sections appear — just add seed URLs, discovery finds the rest.
- [ ] **Own routing (already ours).** OSRM is self-hosted; see `deploy/osrm/`.
- [ ] **Own moderation (already ours).** Local classifier + text engine already
  run on our infrastructure; OpenAI moderation is off by default.

### 4b. Partially self-hostable — decision needed
- [ ] **WordPress bridge.** Decide (a) expose application data via a REST plugin
  + token, or (b) consume standard `wp/v2` content. Then finish the
  `wordpress` integration against whichever we pick. *(§3)*
- [x] **Search optimization / freshness.** ~~If we want ranked, semantic
  retrieval later, add embeddings — but that reintroduces an external model
  unless we self-host one.~~ **Done, and the blocker named here turned out
  not to exist:** the moderation classifier already keeps a multilingual
  sentence model in memory, so retrieval embeddings cost a loopback call
  and no second model. Retrieval is now hybrid (keyword + semantic) over
  chunked passages — see `docs/AI_AND_SCALE_PLAN.md`, Implementation
  report, for the measured before/after and the one thing it does NOT
  fix (this model does not translate; cross-language search rests on a
  fixed campus term list).

### 4c. Cannot be fully self-hosted — important to know
- [ ] **Push notifications (Firebase/FCM).** The *server* that decides what to
  send is already ours (`fcm` integration, our own inbox fallback), but the
  final delivery hop to a phone **must** go through Apple (APNs) and Google
  (FCM/Play) — those transports are controlled by Apple and Google and cannot be
  replaced by our own server. Realistic goal: keep the decision/logic in-house,
  use FCM/APNs only as the delivery pipe. "Eliminate Firebase entirely" is not
  possible for real push; the honest alternative is in-app inbox only (already
  built) plus optional email (4a).
- [ ] **Microsoft Entra sign-in.** If we keep "log in with your ARUCAD
  account", identity is Microsoft's by definition. Self-hosting it means running
  our own IdP and dropping Entra — a much larger decision, probably not wanted.

### 4d. The "AICAD agent" ask
The crawler + `KnowledgeBase` retrieval + `AiController` is the agent's
foundation: it now answers from live DB data **and** current website content
with citations. A fuller "agent" (multi-step tool use, actions) can be layered
on `AiController` later; flagged as a separate, larger piece rather than
half-built now.

---

## 5. Security note
The `apply` wp-admin password and the `support@` mailbox password were shared in
chat. **Rotate both** once this work is integrated. Neither is stored anywhere in
this repo; SMTP credentials belong only in the server's `.env`.
