# ARUCAD Social Life — Mobile UX Design System

This is the durable design contract for the app, written down so it doesn't need to be re-explained every session. It was authored by the product owner after reviewing live screenshots of Home, Discover, Social, Score and Profile against the product vision in the main [README](../README.md). Treat this file, not a fresh feature list, as the source of truth for how screens should be structured and how they relate to each other.

**The core shift this document makes**: the goal is no longer "add more features" — the feature surface (Service Detail, Building Directory, Ask ARUCAD, Admin Panel, moderation) is already broad. The remaining risk is that each new feature gets designed in isolation and the app stops feeling like one product. Every change from here should be checked against this system before it's checked against anything else.

**Implementation status**: the sections below are marked ✅ **Built** where the app now does this for real, and 🔲 **Gap** where it's still the target, not yet the state. Built-but-real doesn't mean built-with-real-backend-data everywhere — e.g. the Social Now block reads real feed data, but a member count is a disclosed deterministic estimate, same pattern as `campusOnlineCount`. Check the code comment at each feature before assuming more than what's marked.

## 1. The shell (never changes)

```
┌──────────────────────────────┐
│ STATUS BAR                   │
│ HEADER                       │
│ CONTENT                      │
│ BOTTOM NAV                   │
└──────────────────────────────┘
```

Bottom navigation is fixed at **5 tabs**, always: `Ana Sayfa · Keşfet · Sosyal · Skor · Profil`. Ask ARUCAD is **not** a 6th tab — it lives in the Home search bar and other contextual entry points, cross-cutting rather than tab-owned.

### Header per tab
- **Home**: `ARUCAD` (logo, left) · Score chip (right, tappable → Score).
- **Discover**: `Kampüsü Keşfet` · search/filter on the right if needed.
- **Social**: `Sosyal` · `+` compose action on the right.
- **Score**: `Skor`, no trailing action.
- **Profile**: `Profil` · settings gear on the right.

## 2. Color system

ARUCAD's real official brand palette (verified against ARUCAD's own visual identity guide) is **Red #E4002B, Blue #10069F, Yellow #FBE122, Gray #4B4F54** — and that palette is **not being replaced**. It's scoped:

- **Logo**: ARUCAD's real logo, real official colors, untouched, everywhere the logo appears.
- **App UI** (buttons, chips, selected states, backgrounds): charcoal / graphite / deep navy / slate / one refined blue accent — **not** the logo's red/yellow. This was a deliberate manager decision (move the *app's* dominant color away from red/yellow), not a rejection of ARUCAD's brand — the two rules coexist: brand identity stays on the logo, app chrome stays calm/mature. Don't reintroduce red/yellow as UI chrome, and don't touch the logo's own colors either.
- **Score level ramp**: its own gray → slate → blue → deep blue → premium-blue progression (below) — visually related to the app-UI palette, not the logo palette.

**Score level color** ✅ Built (`levelColor()` in `arucad_theme.dart`, applied on Score's level chip and Profile's level text/XP bar) — a level reads as *progress toward the deepest, most premium tone*, not a traffic-light gradient:

| Level | Color |
|---|---|
| 1 | Graphite |
| 2 | Slate |
| 3 | Muted blue |
| 4 | Deep navy |
| 5+ | Premium blue |

The signal to the student should be "the deeper/more saturated the blue, the higher the level" — never a red→yellow→green childish gamification ramp.

## 2b. Typography ✅ Built

ARUCAD's real official visual identity guide specifies **Montserrat** as primary and **Oswald** as secondary/display (academic-context faces like Century Gothic/Times New Roman aren't relevant to a mobile app). This is now a locked design-system rule, not a generic system-font stand-in:

- **Montserrat** — body text and headings, app-wide. Wired via `ArucadTextStyles.textTheme()` (`google_fonts` package, `GoogleFonts.montserratTextTheme(base)`), which stamps every `TextTheme` role including `bodyMedium` — what `Scaffold`/`Material` actually uses for its ambient `DefaultTextStyle`. Because of how `Text` merges against that ambient style, this one change gives every existing `Text(..., style: TextStyle(fontWeight: ..., fontSize: ...))` call across the app real Montserrat automatically, without needing to touch each call site.
- **Oswald** — occasional short display labels only (level chips, status badges), via `ArucadTextStyles.display(...)`. Not for body copy or long headings.
- A prior attempt at `google_fonts` was reverted for a web-build "constant-eval" error; re-tested with `google_fonts: ^6.2.1` (resolved to 6.3.3) and both `flutter build web --release` and `flutter build apk --release` now succeed. If a future package upgrade reintroduces that error, that's the thing to check first.
- `google_fonts` fetches the actual font files over the network on first use per device, then caches them — this needs connectivity at least once; the package fails soft (falls back to the platform default font) rather than crashing if the fetch fails, so this is a real, disclosed trade-off, not a silent gap.

## 2c. Score vs. Campus Journey ✅ Built

A single "how good a student are you" composite score (e.g. "you're a 7/10") reads as exclusionary in a university app — deliberately not built. Instead, two separate, honestly-scoped concepts on Score:

- **Score (Yıl Skoru)**: the existing XP/level card — a game-like progress mechanic, unchanged.
- **Campus Journey** (`_CampusJourneyCard` in `quests_screen.dart`): a real usage *summary*, not a score — 4 neutral categories (Explore/Connect/Participate/Contribute) each mapped to real `ActivityKind`s (checkIn / comment+like / eventJoin / review respectively) for the current year, shown as bars relative to each other (not against a fixed 100%, and with no overall composite number) — explicitly framed in-app as "Skor XP'yi ölçer; bu ise bu yıl kampüsü nasıl kullandığını gösterir."

## 3. Home — Timeline, in this exact order

Home is a live timeline, not a map. Map only ever appears as a hand-off card, never embedded as the hero.

```
HEADER
GLOBAL SEARCH / ASK ARUCAD
NEAR YOU
CAMPUS PULSE
TODAY
SOCIAL NOW          ← currently the weakest/missing block, see below
FOR YOU
SERVICES / HELP snapshot
```

### Global search
One search box, one line of placeholder copy naming what it searches: yer, etkinlik, kulüp, servis, kişi, oda, yemek, spor, and "Ask ARUCAD'a sor." This single field is meant to be the primary interaction pattern for finding *anything* in the app — not just places/events.

### Near You ✅ Built
Never a dead empty state. If there's nothing physically nearby right now, still give a second action:

```
YAKININDA
Şu an yakınında başlayan bir etkinlik yok.
Bugün kampüste 3 etkinlik var.
[ BUGÜNÜ KEŞFET ]
```

### Campus Pulse ✅ Built
Always name what "yoğun" is measured from in one line (e.g. "Son aktivite/check-in verilerine göre") — since these numbers are demo/estimated (see README's Known Limitations), never present them as if they were live people-counting.

### Today
One event = one card = title, time, place, "N going", **one** primary CTA (`Katıl`). Everything else lives behind the tap into Event Detail, not stacked into the card.

### Social Now ✅ Built
The single biggest gap identified in the Home review — Home read as *places + events*, not *social life*. Now a "Kampüste Şimdi" block sits between Today and For You (`home_screen.dart`), pulling the 3 most recent real `getFeed()` posts (icon inferred from real post kind: check-in/photo/announcement) with a "Sosyali Gör" action that switches to the Social tab. Not a second Instagram feed — just a real, compact pointer to one.

### For You vs. Nearby — keep these semantically separate
"Sana Özel" cards must be justified by something other than distance (interest, past check-ins, past event attendance) or they should just be labeled "Yakınındaki Yerler" instead. Don't call distance-sorted results "personalized."

### Services/Help snapshot ✅ Built
A few of the most-used services as a shortcut grid (Student Affairs, Library, IT, PDR, Career, International) + `[ TÜM HİZMETLER ]`, so university services are one tap from Home, not three.

## 4. Event Detail

```
← Geri
[image]
Poster Workshop
14:00 · Atelier
18 going

Hakkında
Kimler katılıyor
Organizatör

[ KATIL ]
[ KONUMA GİT ]
```

360/Map only appears here if relevant to the specific event, as a secondary block, not a primary action.

## 5. Discover — a discovery engine, not a catalog

The test for Discover: does a section answer "what can you *do* with this?", not just "here's a list"? Order:

```
HEADER
SEARCH
CAMPUS MAP (hand-off card, not embedded)
CAMPUS PULSE
CREATIVE CAMPUS
COMMUNITIES / CLUBS
SPORTS
FOOD
SERVICES
HELP
CAREER
INTERNATIONAL
```

Don't put all of these above the fold at once — scroll depth is fine, a wall of 12 equal-weight sections at the top is not.

### Creative Campus
This is ARUCAD's actual differentiator from a generic university app — Workshop / Exhibition / Talk / Screening / Open Studio / Performance. Give it real visual weight.

### Communities/Clubs ✅ Club Detail built
Tapping a club now opens Club Detail (§8) — member estimate, next real matching event if one exists, and a real `[ KATIL ]` join toggle. The club *list* itself (`_ClubTile` in Discover) still just shows name/category/description; promoting it above Sports/Food in the scroll is still 🔲 **Gap**.

### Food ✅ Built (partially seeded)
A "Yemek" section (`CampusFoodVenue`, Admin Panel → Yemek tab) — today's menu, hours, admin-editable, same `AdminContentStore` pattern as everything else. Only **The Garden** is seeded, since it's the one food-serving spot already verified in `poi_config.dart` — "Pool Cafe" was the design reference's suggestion, not a confirmed real venue, so it isn't invented here; add it for real from the Admin Panel once confirmed.

## 6. Service Detail — template

Already substantially built (`ServiceDetailScreen`) — keep new services conforming to this exact shape:

```
← Kampüs Hizmetleri
STUDENT AFFAIRS

Ne için yardımcı olabiliriz?
[ Ders Kaydı ] [ Öğrenci Kimlik Kartı ] [ Belge/Evrak ] [ Akademik Süreç ]

Çalışma Saatleri
Konum
Yetkili / İlgili kişiler

[ İLETİŞİME GEÇ ]   [ 360 ]   [ KONUMLA ]
```

Every service question in Ask ARUCAD should resolve to this same screen, not a separate answer format.

## 7. Building → Floor → Room → Person (360/Directory)

```
MAIN CAMPUS
[ 360° GÖR ]
Floors: 1 · 2 · 3
  ↓ select a floor
FLOOR 2
Room 201 — Career
Room 202 — International
Room 203 — Student Affairs
  ↓ select a room
ROOM 202 — International Office
People: …
[ 360 ]  [ SERVICE ]  [ ROUTE ]
```

`DirectoryEntry`/`BuildingDirectoryStore` already model this; the browsing UI should grow toward this drill-down shape as real data is entered (it currently lists flat, grouped only by building — see `BuildingDirectoryScreen`).

## 8. Social — the most active tab

```
SOSYAL                    +
Stories
Feed filters: Tümü · Kampüs · Check-in   ✅ Built (reduced set, see below)
Feed
```

`+` opens a sheet: Gönderi / Story / Check-in / Event paylaş.

**Feed filters — ✅ Built, deliberately reduced from the original 7.** `FeedKind` (`post`/`checkIn`/`announcement`) is a real typed field now, so **Tümü / Kampüs / Check-in** are real, functional filters. **Topluluklar** is *not* included as a filter chip — it'd need a club-tagged-content model this prototype doesn't have, and a filter chip that doesn't actually filter anything would break the "don't fake a feature" rule this whole codebase follows. Add it once that data exists, not before.

**Official vs. student content** ✅ Built — `FeedPost.official` (true only for admin/ARUCAD-published content) drives a "RESMİ" badge, a school-icon avatar, and a primary-color card border in `_PostCard`, so an ARUCAD Events post reads differently from a student's check-in at a glance.

**Keşfet (people) + Follow/Block + Mesajlar** ✅ Built — `SocialGraphStore` is a real, persisted follow/block relationship (per device — no shared social graph without a backend, see `docs/PUBLISH_READINESS.md` P0 #9b); a Keşfet tab lists real ARUCAD classmates (the leaderboard roster) with Follow/Block, and the same actions are reachable from any post's "…" menu. `ChatStore` gives real per-peer message threads (send/read genuinely persists) — honestly labeled as single-device, since there's no backend to actually deliver a message to someone else's phone yet.

**Own profile posts** ✅ Built — Profile's "Gönderilerim" shows a real grid of the signed-in student's own posts, with a "Paylaş" entry point sharing the same compose sheet as Social's feed (`compose_post_sheet.dart`).

A **Topluluklar** (Communities) block inline in Social's feed (not just Discover) is still a 🔲 **Gap** — same reasoning as the filter chip above.

### Club Detail ✅ Built (`lib/features/clubs/club_detail_screen.dart`)
```
Photography Club · 64 members     ← estimate, same disclosed pattern as campusOnlineCount
Hakkında                          ← real CampusClub.description
Yaklaşan Etkinlikler              ← only shown if a real event's title/category loosely
                                      matches the club name — omitted otherwise, not faked
[ KATIL ]                         ← real local join-state toggle (AppSettingsStore),
                                      persists on-device, no membership-roster backend
```
"Members" and "Posts" lists from the original mockup are **not** built — they'd need a real roster/content-tagging backend.

## 9. Score — mature, not game-like

```
SKOR
2,760
LEVEL 6 · Campus Contributor
██████████████░  Next level +240 XP

Nasıl kazandın?
Event +20 · Workshop +30 · Community +20 · Volunteer +50

ACHIEVEMENTS
Creative Explorer · Community Builder · Event Regular · Campus Helper · Global ARUCAD
```

- **Liking a post earns 0 XP** ✅ Built. Keep it that way; don't reintroduce passive-action XP.
- Leaderboard belongs at the bottom of Score, **not** on Home — already true; Home doesn't show it.
- A LEVEL chip using `levelColor()` on Score's Yıl Skoru card ✅ Built. An Achievements block (named badges, not just numeric XP) is still 🔲 **Gap**.

## 10. Profile

```
Avatar · Ezgi · Program/Department
2,760 Score

Activity
Achievements
Communities

Kampüse Hoş Geldin (First 30 Days)
My Academic Life
Settings
```

Admin entry point (`Yönetim Paneli`) lives here, role-gated — unchanged from current behavior.

### First 30 Days — dual placement ✅ Built
Surfaces in Profile **and** as a Home card, gated by `AppSettingsStore.onboardingStartedAt()` — an honest proxy for "day 1" (first time onboarding was ever read on this device, since there's no real enrollment-date field). Auto-hides from Home once the checklist is complete or 30 days have passed; still reachable from Profile afterward either way.

```
KAMPÜSE HOŞ GELDİN
3 / 13 tamamlandı
```

## 11. Ask ARUCAD

Entry: Home search bar + Discover. Never a 6th tab, never voice.

```
Ask ARUCAD
[ Sana nasıl yardımcı olayım? ]
Popular: Student Affairs · Library · PDR · Career · Campus Map · Today's Events
```

**Answer format is Answer → Action, not a paragraph.** Example — "Öğrenci işleri nerede?":

```
STUDENT AFFAIRS
Main Campus · Floor 1
Şu konularda yardımcı olur: Student ID · Registration · Documents
[ İLETİŞİME GEÇ ]  [ KONUMLA ]  [ 360 ]
```

This should render as the *same* Service Detail action row described in §6, not a bespoke chat-bubble layout — one template, reused everywhere a service is the answer.

## 12. Campus Map

A separate service the app hands off to — never embedded as a tab or a Home hero.

```
Search · Layers (Places / Events / Services / Heatmap / Shuttle)
[ Current Location ]
```

Heatmap legend should always name its metric (e.g. "Check-in activity"), consistent with the Campus Pulse rule in §3.

## 13. Admin — desktop-first, not a mobile tab set

Everything in this section is architecture/roadmap, not a mobile screen to build:

- Student-facing app: mobile-first (`sociallife.arucad.edu.tr`, hypothetically).
- Admin: should eventually be **desktop-first responsive web**, not squeezed into mobile tabs. The current in-app Admin Panel (mobile tabs) is the honest client-only stand-in described in the README's roadmap table — this section records the target shape for when a real hosted admin app becomes possible, not a change to make now.
- Target admin shell: dashboard + left sidebar (`Dashboard · Content · Events · Clubs · Sports · Services · Directory · Menus · Communities · Moderation · Users · Roles · Settings`).
- Two still-unbuilt admin flows worth tracking: **image → content draft** (upload a poster, AI drafts the event fields, admin approves) and a **flagged-photo human review queue** (today `ImageModerationService` rejects a flagged photo outright — see README — there's no "hold for review" step yet).

## 14. What question each tab/screen answers

Use this as the test for "does this belong here":

| Question | Screen |
|---|---|
| Şimdi ne oluyor? | Home |
| Ne var? | Discover |
| İnsanlar ne yapıyor? | Social |
| Ben ne kadar katılıyorum? | Score |
| Ben kimim / neye ihtiyacım var? | Profile |
| Bir şey bulamıyorum. | Ask ARUCAD |
| Nereye gideceğim? | Campus Map |
| Binanın içinde nerede? | 360 / Directory |

If a new feature doesn't clearly answer one of these, it's a sign it's being bolted on rather than designed in.

## 15. Spacing & sizing tokens

Apply consistently across every screen, not per-screen:

| Token | Value |
|---|---|
| Safe area (top) | 44–52px |
| Header height | 52–64px |
| Content horizontal padding | 20–24px |
| Card corner radius | 16–20px |
| Gap between cards | 10–16px |
| Gap between sections | 24–32px |
| Bottom nav height | 72–84px |

## 16. Card and CTA rules

- **One card, one job.** An event card shows info + one action, not five icons and three buttons. Detail belongs behind the tap (Event Detail, Service Detail, Club Detail), not stacked into the list card.
- **One primary CTA per screen.** Event → `Katıl`. Service → `İletişime Geç`. Place → `Konuma Git`. Club → `Katıl`. Ask ARUCAD answer → `Aç` / `İletişime Geç` / `Konumla`. Don't compete with 4–5 equally-weighted buttons on one screen.

## 17. Relative product weight

Where design/build effort should lean, in order — Map is intentionally the smallest slice now that it's a secondary service:

```
Social              ██████████
Academic            ████████
Services / Admin    ████████
Events              ████████
Campus              ██████
Help                ██████
Creative            ██████
Career              █████
AI                  █████
Map                 ███
```

Social + Academic + Services is the product's actual center of gravity now, not Map.

## 19. Confirmed against ARUCAD's real official content (2026 research pass)

The product owner cross-checked this whole direction against ARUCAD's actual official site/content structure. Result: the strategy is confirmed correct (clubs, Student Affairs' real scope, Career & Alumni's real services, PDR's real services all match what this system already assumes) — see README's roadmap table for what's real vs. mocked underneath. New, more specific items this pass surfaced, all still 🔲 **Gap** unless marked otherwise:

- **Bandabuliya is Creative Campus, not a plain POI.** Since 2025–26 it's the Music & Performing Arts Faculty's real teaching space (Black Box Theatre, dance studios, rehearsal rooms, sound labs) — its Discover/Campus card should show live-ish "Tonight / Now / This week" programming, not just a 360 button and a description.
- **Career deserves a first-class hub**, not a Service Detail entry: Internships / Jobs / Career Events / CV Review / Mock Interview / Portfolio Review / Alumni — ARUCAD's Career & Alumni Office really offers all of these.
- **"Need Help?" should be a named entry point**, not just the Kampüs Hizmetleri list — a single "What do you need help with?" screen fanning out to Academic / Administrative / Mental Wellbeing / International / Accommodation / IT / Accessibility / Career / Food / Lost & Found / Safety, doubling as Ask ARUCAD's intent categories.
- **Event/exhibition cards should carry an image** when one exists — ARUCAD's real event flow (exhibitions, graduation projects, performances) is visual; an all-text card undersells it. Add an optional image slot to the event card, used when the event has one, not a placeholder.
- **Gallery/Albums** (Profile): a *personal*, private-by-default photo space (Campus Life / My Projects / Events / Friends / year), explicitly separate from the Social feed (Gallery = keep, Social = publish/share). An AI-assisted "these look like they belong together" grouping suggestion is a nice-to-have, not a requirement — real photo album creation/organization is the actual ask.
- **Notification categories**: Social / Community / Event / Service / Campus / Safety, each with its own real trigger — today there's only one local notification (check-in confirmation). Building this for real means an in-app notification list/center first, not just more local push triggers.
- **People/location visibility needs more than one on/off switch.** Today's `locationSharing` setting is binary. The real ask is a visibility *level* — Gizli / Arkadaşlarım / Topluluğum / Herkes — plus a separate "beni yakın çevrede göster" toggle, and any "X is at Garden"-style social notification must only ever fire for someone who opted in, never by default.
- **Admin vs. student visual language stays distinct** (§13's "desktop-first, dense, data-first" for admin vs. "light, visual, content-first" for the student app) — already the design intent, reconfirmed here, not a new decision.

## 20. Suggested build order

Once §1–18 (shell, color, typography, spacing, card/CTA rules) are locked — which they now are — the recommendation is to finish *screens* in this order rather than keep spreading new features thin across all of them. Not yet started/enforced as a process; recorded here so the next session doesn't have to re-derive it:

`Home → Social → Discover → Service Detail → Academic hub → Help hub → Score → Profile/Gallery → Event/Club Detail → Ask ARUCAD → Map handoff → 360/Building Directory → Notifications → Admin web`

Login/Entra intentionally sits last in priority — it's already functionally in place (mock + real-when-configured Entra) and validating it further doesn't teach us anything new about whether the product experience itself is right.

## 21. Domain map

```
                        SOCIAL LIFE
                              │
       ┌──────────────┬───────┴────────┬───────────────┐
       │              │                │               │
    ACADEMIC       ADMIN           SOCIAL          CAMPUS
       │              │                │               │
    SIS             Student           Feed            Map
    Courses         Affairs           Stories         360
    Advisor         Services          Clubs           Rooms
    Calendar        Forms             People          Places
                      │               Communities
                      │
       ┌──────────────┴───────────────┐
       │                              │
    WELLBEING                       CAREER
       │                              │
      PDR                           Jobs
      Help                          Internships
      Accessibility                 Alumni

                        ↓

                    ASK ARUCAD
```

Social Life is the **orchestrator** across these domains — it hands off to each real system (Map, 360, SIS, Entra, WordPress) rather than rebuilding them inside itself. That division of responsibility is already how this codebase is structured (see README's "What is deliberately not built here" table); this diagram is the one-picture version of it.

## 22. The standing instruction

When picking up new UX work in this app, apply this exactly (this paragraph is the literal brief — treat it as the contract, not a summary of it):

> Implement the ARUCAD Social Life mobile UX exactly as a mobile-first student-life platform. Do not redesign each screen independently. Use one consistent design system and the following information architecture: Home / Discover / Social / Score / Profile, with Ask ARUCAD as a cross-cutting action. Home must be a live timeline, not a map. The top priority is Nearby, Campus Pulse, Today, Social Now, For You, and quick access to university services/help. Social must be a highly active part of the product, with Stories, Feed, Communities, Clubs, Events, Check-ins, official/student content separation and social discovery. Academic, Administrative and Student Services must be first-class experiences, not secondary links: every service needs a proper detail screen with topics, opening hours, location, contact, responsible people and actions. Campus Map is a separate existing service and should only receive navigation/location handoffs. 360 is for building interiors, floors, rooms, people and services. Use a mature charcoal/graphite/navy/slate palette with refined blue accents. Do not use red/yellow as the primary brand UI. Use the exact mobile hierarchy, spacing, card behavior, CTA rules, headers, bottom navigation and screen order defined in the ARUCAD Social Life UX specification. Optimize for clarity, speed, one-handed use, minimal cognitive load, strong hierarchy, short content blocks, one primary CTA per screen, and smooth transitions between Social Life → Service → 360 → Map. Do not add features just to fill space; every component must answer a real student need.

## Status key for future audits

When checking a screen against this document, use: **TAMAM** (matches this system) / **DÜZELT** (exists but conflicts with this system) / **EKSİK** (called for here, not built yet). This mirrors the final consolidated audit format the product owner intends to produce once every screen has been reviewed.
