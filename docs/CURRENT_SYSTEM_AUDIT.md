# Current System Audit

Updated: 2026-09-02. This document describes the current code, not an old
prototype plan.

## Product surfaces

| Surface | Entry point | Purpose | Source of truth |
|---|---|---|---|
| Student mobile/web | `/` | Campus discovery, events, services, social, profile and Ask ARUCAD | Laravel REST API + PostgreSQL/SQLite in local development |
| Trainer portal | `/trainer` | A department head publishes and manages only their department's events, attendance and applications | `StaffProfile.user_id` + department-head middleware |
| Admin portal | `/admin` | Campus catalogue, event/activity review, media moderation, content, applications, roles and health | Granular server permissions + admin API routes |

The three portals have independent bearer-token storage. UI visibility is a
convenience only: permission and department checks run on the server for every
write/read that exposes restricted data.

## Student mobile flows

| Flow | Reads | Writes / consequence |
|---|---|---|
| Home | events, places, services, activity, feed, onboarding, inbox | Pull-to-refresh reloads the real API; location is device-local only |
| Events | public published events | join, form completion and attendance state persist in `event_joins` |
| Student activity proposal | places + responsible department staff | creates `events.workflow_status=pending_review`; it cannot appear publicly before admin approval |
| Social | feed, follows, blocks, messages, notifications | text is checked server-side; follows/likes/chat are account-scoped |
| Media | owner gallery/media upload | every upload starts `pending`; public media route only serves `approved` items |
| Map/navigation | MapLibre/OpenFreeMap map plus ARUCAD POIs | routes use configured OSRM; if unavailable the UI labels the fallback rather than inventing a walking route |

## Trainer → student visibility

The trainer event endpoint resolves the trainer's department from the signed-in
account; it never accepts a department or staff ID from the client. A successful
trainer publish stores `workflow_status=published`, so the same event is returned
by the student `GET /events` query immediately. Events created, updated,
published or deleted by a trainer/admin also emit a small public Reverb
invalidation signal; an already-open Student Home re-fetches the events REST
collection. Edits/deletes affect that same row. Attendance and applications are
scoped by `responsible_staff_id`.

## Admin oversight

Admin sections map to actual APIs for events, pending activities, applications,
clubs, places, sports, services, food, career, staff, achievements, pages,
media, surveys, audit log, roles, moderation and system health. Sensitive routes
name a concrete permission; a student token receives `403`, and a banned token
is rejected before it reaches any portal endpoint.

Moderation is now fully local to ARUCAD operations:

- text is blocked/struck server-side;
- image/video bytes are signature-checked locally;
- every uploaded image/video is held in the admin moderation queue;
- approval is required before media can be served to students;
- reviewers receive an expiring signed preview URL for a pending file; the
  normal public file URL returns `404` until approval.

The server additionally applies local, Turkish, English and Russian-normalised
text policy rules for profanity, harassment, credible threats, sexual
exploitation and graphic violence. A high-confidence result from an
ARUCAD-operated local media model rejects the upload and records a strike;
three strikes ban the account across all protected APIs. A missing, failed or
uncertain model never publishes the file: it remains pending for review.
`docs/LOCAL_MODERATION.md` defines the private executable contract and
model-governance requirement.

This is a safety gate, not a claim that PHP byte checks understand visual
semantics. A future fully local visual classifier needs a separately trained,
versioned and hosted model.

Social writes use the same safe realtime pattern as events: create, update,
delete, comment, like, pin and moderator publish operations emit only a `feed`
collection invalidation. The Social page reloads its first REST page, where
audience, private-profile, block and moderation rules are applied again.
Admin place/service changes similarly invalidate only `places` or `services`;
Student Home refreshes only the affected catalogue collection. An open service
detail page also re-fetches its own service after a `services` invalidation.
Media submissions and feed-review actions invalidate the protected admin
moderation dashboard, which re-fetches its queue through the existing
permission-gated REST endpoints.

## Runtime configuration

`USE_REST_API=false` remains only as an explicit debug/test fixture. Normal
debug and every release build use REST; release builds fail before mounting if
`API_BASE_URL` is absent or loopback. The Android release script embeds a
public HTTPS API address, and the sign-in settings page has no host/port
controls, so students never configure a server manually.

## Remaining delivery work

1. Rework each student, trainer and admin screen against the supplied visual
   reference—not merely the Home/Pulse cards.
2. Run a browser/device acceptance pass against a deployed HTTPS API, Reverb,
   push configuration and an OSRM host. These require ARUCAD-controlled DNS,
   credentials and infrastructure that are not present in the repository.
   `deploy/scripts/verify-public-release.ps1` automates API, Reverb-port and
   OSRM endpoint checks once those addresses exist; device/push acceptance
   still needs an actual signed build and Firebase project.

## Latest verification

- Backend: `php artisan test --stop-on-failure` — 506 passed, 2,552 assertions.
- Flutter: `flutter test --no-pub --reporter compact` — all tests passed.
- Flutter static analysis: `flutter analyze` — no issues found.
