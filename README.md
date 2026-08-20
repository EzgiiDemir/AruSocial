# AruSocial — ARUCAD Digital Campus Experience

A campus-life platform for ARUCAD: not a map app, not a social-media
clone — a "student operating layer" connecting academic, administrative,
social, creative, campus, wellbeing, career and everyday-help experiences
in one place, built on real ARUCAD data (POIs, shuttle routes, clubs,
services) wherever that data exists.

**Discover your campus. Connect with your community. Get things done.**

## Project layout

```
frontend/   Flutter app (Android + Web; iOS scaffolded, untested here)
backend/    Laravel API — real, running, backing the app for real
sql/        The actual SQLite database + a reference schema dump
docs/       Everything else you need to know
```

Each top-level folder has its own README with exact commands:
[`frontend's` app details below](#feature-list) ·
[`backend/README.md`](backend/README.md) · [`sql/README.md`](sql/README.md)

## Quick start

```bash
# Frontend — web, using bundled Mock data (no backend needed)
cd frontend
flutter pub get
flutter run -d web-server --web-port=8090 --web-hostname=0.0.0.0 --release

# Backend — real Laravel API + SQLite (optional, opt-in from the app)
cd backend
composer install
php artisan migrate:fresh --seed
php artisan serve --port=4000
```

Full test/run instructions (Android, connecting the frontend to the real
backend, resetting data, etc.): **[`docs/TESTING.md`](docs/TESTING.md)**.

**Test accounts:**
- Student: any `@arucad.edu.tr` email + any non-empty password (e.g.
  `ogrenci@arucad.edu.tr` / `test1234`)
- Admin (superAdmin): `ezgi.demir@arucad.edu.tr` / `Ez26m!r`

## Where to look next

- **[`docs/EKSIKLER.md`](docs/EKSIKLER.md)** — the real, current, honest
  gap list: what's done, what's partial, what's not started, and what
  needs a real external account before it can work.
- **[`docs/EXTERNAL_ACCOUNTS.md`](docs/EXTERNAL_ACCOUNTS.md)** — every
  external service this project needs (Entra, Firebase, Groq, SMTP,
  PostgreSQL, …), what's already wired in code, and exactly what
  credential/account is still needed from ARUCAD.
- **[`docs/TESTING.md`](docs/TESTING.md)** — how to test every layer.
- **[`docs/API_CONTRACT.md`](docs/API_CONTRACT.md)** — the REST contract
  `frontend/` and `backend/` both agree on.
- **[`docs/UX_DESIGN_SYSTEM.md`](docs/UX_DESIGN_SYSTEM.md)** — the design
  contract for screen structure, color, and spacing.

## What's real vs. what's a disclosed stand-in

This app runs in two modes:

- **Mock mode (default)** — `MockCampusRepository`, everything lives in
  memory or on-device `SharedPreferences`. Zero setup, works offline,
  nothing needs a server. This is what `flutter run` gives you with no
  extra flags.
- **Real backend mode** (`--dart-define=USE_REST_API=true`) — the exact
  same UI, but talking to the real Laravel API in `backend/` over HTTP.
  An admin action taken here is genuinely visible from a second device
  hitting the same backend.

Neither mode fakes success: where a real system doesn't exist yet (SMTP,
push, a second real user), the app either shows real data clearly, uses
the most honest working stand-in, or plainly says the feature isn't
configured — never a placeholder "it worked!" toast that didn't do
anything. See `docs/EKSIKLER.md` for the precise, current line between
"real" and "not yet."

## Feature list

### Sign-in & onboarding
- Email/student-number + password against a directory (`MockAuthProvider`
  by default; real Microsoft Entra once configured — see
  `docs/EXTERNAL_ACCOUNTS.md` §1).
- Optional biometric sign-in (`local_auth`) — real OS prompt, no effect on
  web.
- Real device location permission requested at sign-in.
- "First 30 Days" checklist (Profile) for new students.

### Home
Live timeline, not a map-first screen: Nearby → Campus Pulse (busiest
real places right now) → Today (real events) → Kampüste Şimdi (recent
social posts) → For You (unvisited places) → İhtiyacın mı var? (service
shortcuts).

### Campus Map
Real OpenStreetMap vector data via MapLibre GL + the free, keyless
OpenFreeMap tile service (no Google Maps dependency) — every real POI as
a marker, live density heatmap, real device "you are here" dot, in-app
walking navigation (straight-line distance estimate; real turn-by-turn
routing is a documented gap, see `docs/EKSIKLER.md` §13), shuttle
timetables for 5 real ARUCAD routes.

### Discover
Creative Campus, Clubs, Sports, Places, and Campus Services (Student
Affairs, PDR, Career, Library, International, Dormitory, IT,
Accessibility, Lost & Found) — each service opens a real detail screen
with topics, hours, related directory entries, and real contact actions.

### Social
Instagram-style: feed + stories (24h real expiry) + a people directory +
messages, with real Follow/Block, real per-device chat threads, and
real like/comment/report — all now able to run against the real backend
(see `docs/EKSIKLER.md` §4 for the honest multi-user scope).

### Aktivite (Score)
Real yearly XP from real timestamped activity (check-ins, event joins,
reviews, comments — liking earns no XP on purpose), a level ramp, a
leaderboard, and quest cards with real progress/target/reward.

### Profile
Own post grid, real activity history, XP/level card, privacy settings
(location sharing, personalization, check-in visibility), language
switch.

### Admin Panel (web only, `/admin`, role-gated)
A real WordPress-style CMS shell — Dashboard, Etkinlikler/Kulüpler/Spor/
Hizmetler/Yemek/Bina Dizini/Sayfalar CRUD with a real block content
editor (revisions + autosave), a real Medya Kütüphanesi, real
Kullanıcılar & Roller, a real Moderasyon queue, a real Aktivite Günlüğü
(audit log), and Site Ayarları wiring Entra/WordPress/AI-moderation
credentials for real. Events and Moderation can now run against the real
backend too — see `docs/EKSIKLER.md` §10 for which sections still don't.

### Ask ARUCAD
Real Groq LLM call fed the app's full real data catalog (places, events,
clubs, sports, services, shuttle) on every question — Answer → Find →
Open/Navigate/Contact, not just chat. Routes through the real backend
proxy (key never in the client) when `USE_REST_API=true`; falls back to
a direct client-side call in Mock mode.

### Language (TR / EN / RU)
Real in-app translation layer for navigation, titles, and settings —
switches immediately, everywhere.

## Known limitations

The single most important thing to read before demoing: **`docs/EKSIKLER.md`**.
Short version — no real multi-user backend for social features yet
(single demo account), no real push/SMTP (need external accounts, see
`docs/EXTERNAL_ACCOUNTS.md`), several admin sections (Clubs/Sports/
Services/Food/Directory/Pages/Media/Roles) still write to on-device
storage instead of the real backend, and production hardening (auth,
rate limiting, CORS, monitoring, CI) hasn't started.

## Mock content note

Where real ARUCAD data was available (POI coordinates, shuttle routes,
clubs, sports, campus services) it's used as-is. Everything else
(reviews, online counts, leaderboard peers, seeded social posts) is
clearly-labeled demo/seed data pending the real backend rollout described
in `docs/EKSIKLER.md`.
