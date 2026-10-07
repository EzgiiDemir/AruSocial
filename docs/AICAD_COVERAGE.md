# AICAD coverage: what it can answer from canonical data

Phase 4A, 2026-10-06. The live version of this matrix is on **Admin → AICAD Health → Coverage**
(`App\Services\Ai\AicadCoverage`). It is read-only and computed from the tables on every page view,
counts only. This page explains it and records the state when Phase 4A was finished. Counts are
from the local database, which mirrors the seeded campus catalogue. Production numbers will differ
where staff have entered data.

## How to read a row

| Column | Meaning |
|---|---|
| exists / structured / authoritative / current | Whether the data is there, is in fields rather than prose, comes from an owner of the information, and is in date |
| fact types | The SupportedFact types that carry it into a generated answer |
| status | `SUPPORTED`, `PARTIALLY_SUPPORTED` (some rows or some fields), `DATA_UNAVAILABLE` (field exists, empty), `SOURCE_UNAVAILABLE` (nothing holds it), `STALE`, `NOT_IMPLEMENTED` (no fact type) |
| gap | Who can fix it: `DATA_GAP` → staff data entry · `SOURCE_GAP` → no table/feed/page exists · `CODE_GAP` → engineering · `STALE_DATA` → the source must be updated · `NOT_SUPPORTED_BY_PRODUCT` → deliberately not held |

A status other than SUPPORTED is not an AI defect. AICAD answers it with "not found in current data". It
never answers it with a guess. ClaimVerifier also rejects any generated sentence that states such a value
anyway (see "Never uncertain" below).

## Before and after Phase 4A

| Pri | Capability | Before 4A | After 4A | Gap now | Detail now |
|---|---|---|---|---|---|
| P0 | academic_dates | STALE (only the 2025–2026 `academic_years` row; no date fact) | **SUPPORTED** | — | 2026–2027, 36 dated entries parsed from the official calendar page |
| P0 | required_documents | PARTIALLY_SUPPORTED | PARTIALLY_SUPPORTED | DATA_GAP | Only where an official page lists them |
| P0 | admission_requirements | NOT_IMPLEMENTED (score claims went through as UNCERTAIN) | NOT_IMPLEMENTED (score claims **rejected**) | SOURCE_GAP | No structured admission criteria anywhere |
| P0 | service_contacts | NOT_IMPLEMENTED | **PARTIALLY_SUPPORTED** | DATA_GAP | 10/10 services with an e-mail, **0/10 with a phone** |
| P0 | programme_language | SUPPORTED | SUPPORTED | — | 20/20 programmes |
| P0 | programme_other_attributes | NOT_IMPLEMENTED (length claims UNCERTAIN) | NOT_IMPLEMENTED (length claims **rejected**) | CODE_GAP | 18 programmes have an extracted duration with no fact type |
| P1 | food_opening_hours | DATA_UNAVAILABLE (free text only) | DATA_UNAVAILABLE (structured model ready) | DATA_GAP | 0/1 venues with hours |
| P1 | food_location | DATA_UNAVAILABLE (name match only) | DATA_UNAVAILABLE (`place_id` ready) | DATA_GAP | 0/1 venues reach a place |
| P1 | food_menu | DATA_UNAVAILABLE | DATA_UNAVAILABLE | DATA_GAP | 0 menus today or later |
| P1 | club_social_profile | DATA_UNAVAILABLE (read from **untrusted** description text) | DATA_UNAVAILABLE (canonical field only) | DATA_GAP | 0/19 clubs |
| P1 | club_contacts | NOT_IMPLEMENTED (no field) | DATA_UNAVAILABLE | DATA_GAP | 0/19 clubs |
| P1 | club_rooms | NOT_IMPLEMENTED (no field) | DATA_UNAVAILABLE | DATA_GAP | 0/19 clubs |
| P1 | staff_persons | NOT_IMPLEMENTED | NOT_IMPLEMENTED | DATA_GAP | 1/58 staff rows name a person; the rest are offices |
| P1 | service_opening_hours | PARTIALLY_SUPPORTED | PARTIALLY_SUPPORTED | DATA_GAP | 8/10 services |
| P1 | campus_locations | SUPPORTED | SUPPORTED | — | 24/24 places with coordinates |
| P1 | walking_routes | SUPPORTED | SUPPORTED | — | Routing service configured |
| P2 | announcements | SOURCE_UNAVAILABLE | SOURCE_UNAVAILABLE | SOURCE_GAP | Official notices are crawled pages only |
| P2 | events | DATA_UNAVAILABLE | DATA_UNAVAILABLE | DATA_GAP | 0 published upcoming events locally |
| P2 | sports_facilities | DATA_UNAVAILABLE | DATA_UNAVAILABLE | DATA_GAP | 0/5 facilities match a place |
| P2 | app_navigation | NOT_IMPLEMENTED (screen names unchecked) | **SUPPORTED** | — | 14/14 destinations labelled in tr, en, ru |

Totals (20 capabilities):

| | SUPPORTED | PARTIAL | DATA_UNAVAILABLE | SOURCE_UNAVAILABLE | STALE | NOT_IMPLEMENTED |
|---|---|---|---|---|---|---|
| Before | 3 | 2 | 6 | 1 | 1 | 7 |
| After | 5 | 3 | 8 | 1 | 0 | 3 |

DATA_UNAVAILABLE went up because three capabilities moved from NOT_IMPLEMENTED (there was no field to
fill) to DATA_UNAVAILABLE (the field exists and is empty). That is the intended direction. Nine of the
remaining non-supported rows are now plain data entry.

## Phase 4B: data completion

The Phase 4A state is frozen in `docs/coverage/phase4a_baseline.json`. It records the coverage matrix and the evaluation runs (retrieval #63; full answers #64/#67 with facts off, #65–#66 with facts on).

**Code coverage and data completeness are measured separately.** Code coverage says whether a fact type exists. Data completeness says how many records hold the value. AICAD Health → Coverage shows both, plus a **Coverage gaps** list: every record with a missing value, what it affects, and a link to the existing form where staff fill it in. Nothing is filled automatically.

| | Phase 4A | Phase 4B |
|---|---|---|
| Capabilities with a fact type (code coverage) | 17 of 20 | 19 of 22 (programme duration added; academic-year record and service aliases are newly measured rows) |
| Programme length | NOT_IMPLEMENTED | PARTIAL, 18/20 programmes (2 pages state no duration) |
| Values filled (data completeness, local data) | not measured | 105 of 191 |

### Data-entry report (local data, 2026-10-06)

| Entity type | Total | Complete | Missing | Highest-value missing field | Where to fill it |
|---|---|---|---|---|---|
| Offices (services) | 10 | e-mail 10/10 · phone 0/10 · hours 8/10 | 10 phones, 2 hours | **Phone** | Admin → Services → office → Phone |
| Food venues | 1 | place 0/1 · weekly hours 0/1 · menu today 0/1 | all three | **Campus place + weekly hours** | Admin → Food & Drink → venue |
| Clubs | 19 | Instagram 0/19 · e-mail 0/19 · room 0/19 | 57 values | **Instagram URL and e-mail** | Admin → Clubs → club → Contact & room |
| Sports teams | 10 (5 facility names) | campus place 0/10 | 10 | **Campus place** (create the Place first: no facility is a place record yet) | Admin → Places, then Sports → team |
| Staff | 58 | person records 1/58 | 57 | Person names, with a source | In-app admin |
| Academic year record | 1 | current 0/1 | 2025–2026 still active | **Add 2026–2027 and make it active** | In-app admin → Akademik Yıllar |
| Office aliases | 10 | 8/10 with aliases | 2 | Short names staff hear used | Admin → AICAD → Aliases |

**What filling them would do.** This was measured in a throwaway database with fictitious values; see `docs/coverage/phase4b_simulation.json`.
- All seven P1 data rows move to SUPPORTED.
- Data completeness goes from 42/151 to 121/151.
- In a nine-question battery, supported tasks go from 8/20 to 17/20 and fully unavailable answers from 4/9 to 1/9.

### Candidate values for staff to verify

Found in official pages. None was written anywhere; each needs staff confirmation.

| Candidate | Source | Note |
|---|---|---|
| Main switchboard **+90 392 650 65 55** | prospective.arucad.edu.tr/contact-us | Switchboard, not a per-office number |
| Öğrenci İşleri (apparently): switchboard ext. **1111/1112**, WhatsApp **+90 533 820 52 79**, e-mail **admissions@arucad.edu.tr** | Student Handbook 2026–2027 (TR), arucad.edu.tr/wp-content/uploads/2026/09/ | The handbook e-mail differs from the one recorded (ogrenciisleri@…): check which is current |
| GARDEN MENÜ ↔ place "The Garden" | Names only | Looks like the same place, but linking by name is a guess: confirm before setting the venue's place |

### Other sources examined
- **Admission requirements:** the only explicit list is on the international apply page (prospective.arucad.edu.tr/apply-now). Turkish and TRNC applicants follow the talent-exam and quota pages, with different criteria. No single stable list exists, so no extractor was built.
  - Smallest viable option: a staff-maintained "requirements per applicant type" record. A page-specific extractor for the international page is a second-best option.
- **Announcements:** no announcement source exists. The closest structures are:
  - `feed_posts.official`: staff-authored and pinnable, but with no validity window, and 0 official posts locally.
  - `admin_pages`: has publish and expiry dates, but these are content pages, not notices.

  Either would need a product decision, such as an "announcement" post type with valid from/until, before it could be temporary evidence. It stays SOURCE_UNAVAILABLE.
- **Academic year:** term dates come only from the official calendar. The stale record still drives:
  - academic-year tagging of new student events (`EventController`)
  - the event form's year list
  - evidence staleness checks (`TemporalEvaluator`)
  - the legacy prompt, which now labels it "ended, not the current year" and lists the official calendar instead

## Phase 4C: closure and staging readiness

The Phase 4B baseline is frozen in `docs/coverage/phase4b_baseline.json`, including per-case evaluation results.

### Data-completion checklist (AICAD Health → Data-completion checklist; each line opens its form)

| Pri | Item | Total | Complete | Missing | Affects |
|---|---|---|---|---|---|
| A | Office phone numbers | 10 | 0 | 10 | contact_phone |
| A | Food venue → campus place | 1 | 0 | 1 | food location, nearest food, route to food |
| A | Food venue weekly opening hours | 1 | 0 | 1 | opening hours, open now |
| A | Current academic year (2026–2027) active | 1 | 0 | 1 | event year tagging, evidence staleness |
| B | Club Instagram URLs | 19 | 0 | 19 | club social profile |
| B | Club e-mails | 19 | 0 | 19 | club contact |
| B | Club meeting places | 19 | 0 | 19 | club location, route to club |
| B | Sports team → campus place | 10 | 0 | 10 | sport location, route |
| C | Staff person records (with a source) | 58 | 1 | 57 | staff person (no fact type yet) |
| C | Office aliases | 10 | 8 | 2 | entity resolution |
| C | Daily menus and events | — | — | — | current menu (no admin screen; food API), current events |

Every actionable gap links to the existing edit screen:
- service phone → Services edit
- food venue place and hours → Food & Drink edit
- club Instagram, e-mail and room → Clubs edit
- sports place → Sports edit
- academic year → **Academic years**, a new admin resource

The in-app staff screens were removed in "Admin architecture v2", so academic years had no screen at all. It was listed as "still to build" in `ADMIN_PANEL.md`.

### Academic year
- Term dates come only from the official calendar page. Without it, no date is answered, and the stale row is never used. This is covered by a test.
- Still dependent on `academic_years`:
  - academic-year tagging of new student events (`EventController`)
  - the event form's year list
  - evidence staleness checks (`TemporalEvaluator`)
  - the legacy prompt line, which now labels an ended year "ended"
- **Staff action:** Admin → Academic years → add 2026–2027 and make it active. The 2025–2026 row is deactivated automatically, not deleted. Staff choose the dates; nothing is created automatically.
- Until then, the stale row shows as a warning in Data quality, in Readiness (`academic_year WARN`) and as checklist item A.

### Admission requirements: stays a CONTENT/SOURCE gap
The smallest correct model, conceptually (not built, because no authoritative structured data exists):

| Field | Meaning |
|---|---|
| applicant type | e.g. Turkish citizen (talent exam), TRNC citizen, international undergraduate, transfer (yatay geçiş), graduate |
| requirement set | an explicit list of requirements: documents, exam/score thresholds, age limits, portfolio, language level |
| validity period | the academic year or intake it applies to (valid from / until) |
| source / provenance | the official page or PDF and the date verified, with the staff member who confirmed it |

Today only the international undergraduate list is explicit (prospective.arucad.edu.tr/apply-now). The other applicant types are spread across talent-exam, quota and transfer pages with different criteria, so a generic answer would be wrong for most applicants.
- **Data needed:** one confirmed requirement set per applicant type, with its intake year.
- **Until then:** `required_documents` covers explicit document lists, and the verifier rejects any admission-score claim.

### Announcements: stays SOURCE_UNAVAILABLE

| Candidate | Why it is not an announcement source |
|---|---|
| `feed_posts.official` (+ pinning) | Staff-authored and official, but has no validity window, no "affects place/service", and is a social post type; 0 official posts locally |
| `admin_pages` (publish_at / expires_at) | Has a validity window, but these are content pages, not notices, with no affected place/service |
| `notifications` | Per-user inbox rows, not public notices |

**Minimum product decision needed:** an *announcement* type, either a new `post_type` on official feed posts or its own small table, with:
- `valid_from` / `valid_until`
- the affected service or place (optional)
- the official author status

With that, a temporary closure ("Kütüphane bugün kapalı") could become `official_announcement` evidence. That authority class already exists in `EvidencePolicy` and outranks the standing hours.

### Trusted navigation audit
There are 16 destinations: the 5 tabs, the 8 Discover tiles, My applications, Campus social activity, and Notifications. Checked against `campus_shell.dart`, `explore_screen.dart`, `profile_screen.dart` and the Notifications entry points:
- No destination is obsolete.
- The full-screen map and the building directory have no title string, so they aren't listed rather than given invented labels.
- Every label exists in tr/en/ru in `app_strings.dart`, and `AicadNavigationConfigTest` enforces it.
- Generated answers naming any other screen are removed (`ClaimVerifier`, `ui_destination`).

## Data entry that would move rows to SUPPORTED

These need no code change. The fields exist and AICAD reads them as soon as they are filled:

1. **Service phone numbers**: Services → contact field. Write them as they should be dialled (for example `+90 392 …`). AICAD repeats the digits verbatim.
2. **Food venue**: Food & Drink → venue → *Campus place* and *Weekly opening hours*. One row per day, and a day without a row is closed. Fill *valid from / until* for a term or holiday timetable.
3. **Clubs**: Clubs → *Contact & room* → official Instagram URL (`https://instagram.com/<handle>`), e-mail, website, room. Handles written in the description are deliberately **not** used.
4. **Daily menus** and **events**: the existing food and event screens.
5. **Sports facilities**: a Place for each facility name (Spor Salonu, Açık Saha, Tenis Kortu, …).
6. **People**: staff records are offices ("Öğrenci İşleri", "Mimarlık Danışmanı"). A "who is the head of X" answer needs person records with provenance. They must never be scraped in automatically.
7. **Service name aliases** (Admin → AICAD aliases): short and Russian forms such as "kariyer merkezi" and "студенческий отдел" do not resolve today.
8. **The `academic_years` record** is still 2025–2026 and still marked active. Term dates no longer depend on it, but temporal staleness checks do.

## Sources AICAD now reads

| Capability | Canonical source | Notes |
|---|---|---|
| Academic dates | The official page `https://arucad.edu.tr/lisans-akademik-takvim/` (indexed by the existing crawler) | Parsed deterministically by `AcademicCalendar`: a fixed month table and event vocabulary. A calendar whose last date has passed is STALE: reported as "latest published is …", never as the current year |
| Contacts | `services.contact`, `staff_profiles.email`, `clubs.email` | E-mails and phone numbers verbatim. A phone question without a recorded phone says so |
| Opening hours | `opening_hours` rows when present, else the free-text `hours` field | Rows decide open-now directly. They are summarised to "Hafta içi 08:00–16:00" only when that is exact |
| Venue / club place | `food_venues.place_id`, `clubs.place_id` | Venues without `place_id` still fall back to name matching |
| Club social profile | `clubs.instagram_url` | Description text is never trusted |
| App screens | `config/aicad_navigation.php` + the app's `translations` table | Labels in every locale come from the strings the app shows |

## Never uncertain

ClaimVerifier used to record these sentences as UNCERTAIN and keep them. They are now UNSUPPORTED
(removed) unless a fact backs them:

| Category | Rejected when |
|---|---|
| Calendar date ("5 Ekim", "October 5", "5 октября", "05.10.2026") | No academic-date or event fact has that day |
| E-mail / phone | Not the value of a contact fact |
| Titled person ("Prof. Dr. …", "Dr. …") | Always: no person facts exist |
| Programme length ("4 yıllık", "four years") | Always: no duration fact type |
| Admission score ("IELTS 6.0", "ortalama 2.5") | Always: no requirement facts |
| App screen ("Ayarlar sekmesi", "вкладка «Настройки»") | Not a trusted destination (a web page referral is not a screen) |
| Opening status, address, floor/room, documents, absolute negatives | As in Phase 3C.1 |

Reported gaps ("bulamadım", "could not find", "нет данных") stay PRESENTATION_ONLY in every language.
They state an absence; they don't deny that something exists.
