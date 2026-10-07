# AI Knowledge Map

Written for: the ARUVERSE engineering team and whoever decides which
systems the assistant may connect to next.

**What the assistant knows, where that knowledge comes from, and how it is
authorized.** Audited from the repository, not from a specification. Where
something does not exist it says NOT IMPLEMENTED rather than describing how
it would work.

Companion to [`LOCAL_AI_ARCHITECTURE.md`](LOCAL_AI_ARCHITECTURE.md), which
covers the model, the privacy gate and the prompt.

---

## A. Existing sources

Every source the assistant can read today. "Tool" is the key in
`AruverseAgent::tools()`; a source with no tool is not reachable by the
assistant at all, whatever else it may power in the app.

| Source | Type | Where | Tool | Authority | Freshness | Visibility | Languages | AI status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Student's own profile, appointments, clubs, upcoming events | DB | `users`, `appointments`, `club_members`, `events` | `PersonalContext` | PERSONAL (100) | live | USER_PRIVATE | data-neutral | **Integrated**, scoped by session user id |
| Places / buildings | DB | `places` | `places` | OPERATIONAL (80) | live | PUBLIC | TR + EN rows | **Integrated** |
| Events | DB | `events` | `events` | OPERATIONAL | live | PUBLIC (publish rules) | TR | **Integrated** |
| Clubs | DB | `clubs`, `club_members` | `clubs` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Sports | DB | `sports` | `sports` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Service offices (10) | DB | `services` | `services` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Food venues + daily menu | DB | `food_venues`, `food_daily_menus` | `food` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Shuttle routes **and departure times** | DB | `shuttle_routes` | `shuttle` | OPERATIONAL | live | PUBLIC | TR | **Integrated** (times added in this pass) |
| Campus directory — 141 offices/rooms | DB | `directory_entries` | `directory` | OPERATIONAL | daily sync | PUBLIC | TR | **Integrated** (`campus:sync-360-directory`) |
| Staff — 58 profiles | DB | `staff_profiles` | `staff` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Academic year boundaries | DB | `academic_years` | `calendar` | OPERATIONAL | semester | PUBLIC | TR | **Integrated** — start/end dates only |
| Consultations / advising slots | DB | `consultations`, `staff_availability_slots` | `consultation` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Career opportunities | DB | `career_opportunities` | `career` | OPERATIONAL | live | PUBLIC | TR | **Integrated** |
| Counselling signposting | code | `SupportIntent` | `support` | OPERATIONAL | static | PUBLIC | TR/EN/RU | **Integrated** — signposting only, never records |
| Crawled ARUCAD web pages — 75 docs / 392 passages | Index | `knowledge_documents`, `knowledge_chunks` | `knowledge` | WEB (40) | 2×/day crawl | PUBLIC | TR/EN/RU detected | **Integrated**, hybrid keyword + MiniLM |
| Admin-approved crawl domains | DB | `crawl_sources` | — | config | admin-edited | INTERNAL | — | **Integrated** — allow-list source of truth |
| Routing / navigation | Service | `RoutingService` → OSRM | — | OPERATIONAL | live | PUBLIC | n/a | **Available but NOT wired to the assistant** |
| Live crowd presence | DB | `campus_presences` | — | OPERATIONAL | 10-min window | PUBLIC (aggregate) | n/a | **Not wired** |
| Ask conversation history | DB | `ask_messages` | — | CONVERSATION (20) | live | USER_PRIVATE | any | **Integrated as context only** — never a fact |
| Model pretrained knowledge | Model | vLLM | — | MODEL (0) | frozen | — | TR/EN/RU | **Never authoritative for ARUCAD facts** |

Indexed corpus by domain (dev database): `arucad.edu.tr` 26, `aday` 27,
`apply` 10, `prospective` 3, `kibrisaday` 3, `academics` 1, `quality` 1,
`qualityhub` 1, `broadcast` 1, `360` 2.

### Explicitly excluded

Not in the AI context, by design:

| Data | Why |
| --- | --- |
| Counselling/clinical records | HIGHLY_SENSITIVE. The `support` tool signposts the service; it never reads a case |
| Moderation cases, appeals, strikes, violations | STAFF_RESTRICTED, and about other people |
| `admin_audit_log`, `moderation_audit_log` | Security logs are not general context (§3 of the brief) |
| Chat messages, DMs, group chats | Other people's private conversation |
| Feed posts by other students, stories | User-generated, not institutional truth |
| Another student's anything | `PersonalContext` queries by session user id only |
| API keys, tokens, `.env` | Never in a prompt or a log |

---

## B. Coverage matrix

| Domain | Status | Evidence |
| --- | --- | --- |
| PERSONAL (profile, own appointments, own clubs) | **COMPLETE** | `PersonalContext` |
| EVENT | **COMPLETE** | `events` tool + `publiclyListed()` |
| PLACE / MAP (what is where) | **COMPLETE** | `places` + `directory` tools |
| SHUTTLE | **COMPLETE** | routes + departure times |
| FOOD | **COMPLETE** | venues + today's menu, declines when unset |
| CLUB / SPORT | **COMPLETE** | structured tables |
| STAFF / DIRECTORY | **COMPLETE** | 58 staff, 141 offices |
| STUDENT_AFFAIRS, ADVISING, COUNSELING, CAREER, LIBRARY, INTERNATIONAL, DORM, IT, ACCESSIBILITY, LOST_FOUND | **COMPLETE for "what/where/contact/hours"** | 10 rows in `services` |
| CAREER opportunities | **COMPLETE** | `career_opportunities` |
| WEB_KNOWLEDGE | **COMPLETE** | 75 pages, retrieval@3 = 30/30 |
| SCHOLARSHIP / FINANCE | **PARTIAL** | Web pages only; no structured fee table. Amounts guarded by `AnswerGrounding` |
| CALENDAR | **PARTIAL** | `academic_years` holds term start/end only. Registration, add/drop, exam periods exist only as crawled HTML — not as dates that can be counted against |
| NAVIGATION / ROUTE | **PARTIAL** | OSRM is live and correct, but the assistant cannot call it. "How do I get from A to B" is answered as prose, not a route |
| REGULATION | **NOT IMPLEMENTED** | **73 PDFs** at `/yonetmelikler/`, none indexed — the crawler accepts `text/html` only |
| SIS / COURSE / TIMETABLE / GRADES | **NEEDS EXTERNAL ACCESS** | No course, enrolment or timetable table exists anywhere in the schema |
| LIBRARY holdings / availability | **NEEDS EXTERNAL ACCESS** | No library system integration |
| SOCIAL MEDIA | **NOT IMPLEMENTED** | No connector |
| DRIVE / ONEDRIVE | **NOT IMPLEMENTED** | No connector |
| EMAIL | **NOT IMPLEMENTED** | `email_logs` is outbound delivery records, not a mailbox. Correctly excluded |
| LOST_FOUND listings | **PARTIAL** | Office exists; no item listings table |
| DORM availability | **PARTIAL** | Office exists; no live availability source |

---

## C. Source routing map

`AruverseAgent` keyword-scores the question, runs the matching tools plus
knowledge, and orders the blocks by authority. Representative routes:

| Question | Sources, in precedence order | Authorization | Freshness | Fallback |
| --- | --- | --- | --- | --- |
| "What's on tonight?" | `events` → web | public | live | "no events found" |
| "When is my appointment?" | `PersonalContext` | session user only | live | "check Appointments" |
| "Where is Student Affairs?" | `services` → `directory` → `places` → web | public | live | web page |
| "How do I get from the library to X?" | `places` (coords) → **routing engine (not wired)** | public | live | prose only — see gap G3 |
| "When is the next shuttle?" | `shuttle` → web | public | live | timetable |
| "What are the scholarship rules?" | web | public | 2×/day | refuse |
| "What does regulation X say?" | **nothing** | — | — | refuse (correct today) |
| "What classes do I have?" | **nothing** | — | — | refuse (correct today) |
| "Who is my advisor?" | `consultation` (service, not the person) | public | live | refuse for the named person |

Precedence is enforced in code (`SourceAuthority`), not left to the model:
blocks are ordered, sources are ranked, and the rules state the conflict
policy explicitly.

---

## D. Gap analysis

Each tied to code, not to a general wish.

**G1 — PDF pipeline and development crawl complete.** The prior audit found
**approximately 73** regulation links. The bounded live development crawl
discovered 87 PDFs across all approved sources, parsed/indexed 83, and recorded
4 image/no-text documents without indexing them. Production corpus state still
depends on running the same authorized crawl after deployment.

**G2 — SIS adapter boundary only.** No `courses`, `enrolments`, `timetable`
or `grades` source exists. The disabled provider and authenticated-user gateway
are implemented; live capabilities still require an approved ARUCAD source.

**G3 — Routing is reachable.** `AskOperations` resolves multilingual
place/service names and calls `RoutingService`; failure returns a verified
destination plus an unavailable warning, never an estimated route.

**G4 — The calendar is two dates.** `academic_years` holds only term start
and end. "How many days until finals" has no structured answer.

**G5 — Web snapshots vs. live pages.** Mitigated this pass (blocks ordered,
dates labelled, conflict rule stated) but a page edited between crawls is
still stale for up to 12 hours.

**G6 — Structured response started.** Place and route components plus warnings
are live and Flutter-rendered. Deterministic event/contact components and the
generic schedule/table contract remain.

---

## E. Source-of-truth policy

The precedence, in one place, enforced by `App\Services\Ai\SourceAuthority`:

| Level | Source | Rule |
| --- | --- | --- |
| 100 PERSONAL | The asker's own live rows | Only authority for their own data. **Never citable** — informing an answer is not the same as being shown as a source |
| 80 OPERATIONAL | Our structured tables | Beats crawled text on anything that moves: event times, shuttle departures, opening hours, menus |
| 60 OFFICIAL_DOC | Regulations, policies | Allow-listed parsed PDFs; outranks HTML summaries |
| 40 WEB | Crawled ARUCAD pages | A snapshot. Carries `[site görüntüleme: DATE]`; an unreachable page carries `[ARTIK ERİŞİLEMEYEN SAYFA]` |
| 20 CONVERSATION | Earlier turns | Resolves "it" and "there". **Never evidence** |
| 0 MODEL | Pretrained knowledge | **Never an authority for any ARUCAD fact** |

**Conflict:** higher authority wins; the answer notes that the page may be
out of date. An unresolvable conflict is stated as a conflict, never
silently resolved.

**No source, no claim.** If no block contains the fact, the answer says it
cannot be confirmed and points at where to check. General knowledge of how
universities work is not evidence about ARUCAD.

**Facts vs. recommendations** are kept distinct in the rules: "your next
class is at 14:00" is a fact from a source; "leave by 13:50" is advice.

---

## F. PDF ingestion (implemented)

Implemented with the single `smalot/pdfparser` dependency. There is no OCR:
image-only PDFs are stored with `no_extractable_text` health and no chunks.

The minimum that fits the existing pipeline:

1. **Accept PDFs at the fetch boundary.** `application/pdf` is accepted only
   after the same allow-list/SSRF/excluded-path checks, capped by
   `KNOWLEDGE_MAX_PDF_BYTES`.
2. **One new class**, `PdfTextExtractor`, mirroring `HtmlTextExtractor`:
   bytes in, plain text out, empty string on failure. Nothing else changes.
3. **Reuse the existing row shape.** `knowledge_documents` already has
   `url`, `title`, `content`, `content_hash`, `fetched_at`, `language`,
   `is_stale`. Add only `document_type` (`html`|`pdf`) and `page_count`.
   Chunking, embedding, retrieval and citation work unchanged.
4. **Authority.** PDFs from an allow-listed domain are `OFFICIAL_DOC` (60),
   above crawled HTML — a regulation outranks a summary of it.
5. **Cite the page number.** Prefix each chunk with `s. N` so a citation
   can say which page it came from.
6. **Same safety envelope:** allow-list, SSRF checks, size and page caps,
   and the text goes inside the same untrusted fence.

Cost: one dependency, one class, one migration and two config keys. Corpus
completion still requires an authorized live crawl and review of failures.

**Do not** ingest Word files in the same step — `/yonetmelikler/` links no
`.doc`/`.docx`, so it would be speculative work.

---

## G. What would require external access

| Integration | Needs | Boundary to build |
| --- | --- | --- |
| SIS (courses, timetable, enrolment, advisor) | ARUCAD SIS API + service credentials | `SisConnector` interface, per-student scoped, never cross-student |
| Library holdings | Library system API | `LibraryCatalogConnector` |
| Official social media | Channel API tokens | Read-only, authority **below** web; never user-generated posts |
| Drive / OneDrive | Tenant app registration, delegated permissions | Source permissions must carry into retrieval |
| Email | Per-user OAuth | Never a global corpus; user-scoped search only |

None of these are faked. There is no stub pretending to be a SIS.
