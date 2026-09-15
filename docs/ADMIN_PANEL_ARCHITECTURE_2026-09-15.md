# Admin panel architecture audit — 2026-09-15

## System understood

The product is a Flutter mobile/web client backed by Laravel 13. The staff UI
is Filament 5 and is deliberately split into `/admin` and `/trainer`. Both
panels use the same `CampusPanel` design configuration. The JSON API and the
Filament resources share `GranularPermissions`; content changes are recorded
in `admin_audit_log`. Existing moderation/media work in the working tree was
preserved.

## Implemented in this change

- Sidebar order: Content Management, Social, AICAD, Users, Campus Map,
  Operations, System. Items remain permission-aware.
- ARUCAD blue, black/white/neutral dashboard palette. No progress bars.
- Role-aware card dashboard covering users, applications, appointments,
  events, participation, clubs, sports, moderation, translations, pages,
  failed email, media storage and health.
- WordPress-style Page Builder backed by a controlled block registry. It has
  19 approved block types, drag/drop ordering, clone/collapse, TR/EN/RU
  content, mobile/web visibility, block and page scheduling, audience
  selection, draft/review/published/archive states, preview, soft deletion,
  restore, permanent-delete confirmation, bulk operations and export.
- Page revisions are immutable snapshots in `content_revisions`. The API
  localizes page and block data, filters mobile/web blocks, prevents normal
  users from reading draft/future/expired pages, and keeps the existing data
  contract compatible.
- User management with institutional identity, organization, TR/EN/RU
  preference, timezone, lifecycle status and MFA requirement. Accounts can be
  suspended and active API sessions terminated. Passwords are never listed.
- Multi-role `role_grants`: primary/additional roles, scope type and ID,
  allow/deny exceptions, publish/export/sensitive-data flags, start/end dates
  and status. The legacy `role_assignments` API remains supported during
  migration.
- Canonical permission vocabulary follows `module.resource.action[.scope]`.
  CRUD resources consume action-specific grants and scope every list, route
  binding and row action. The API permission middleware also performs record
  scope checks for managed resources.
- Safety rules: users cannot edit their own role grants, the last scoped Super
  Admin grant cannot be removed, there is no user bulk delete, ordinary role
  managers cannot permanently delete users, and explicit deny wins.
- Social Campus Feed moderation actions; AICAD chat-history metadata without
  exposing message content; immutable email and audit-log screens.
- The Flutter web build is now student-only. Legacy frontend `/admin` and
  `/trainer` paths normalize to `/`, and the profile no longer embeds either
  panel. The single admin UI is Laravel/Filament at the backend `/admin/login`.

## Existing capabilities retained

- Shared CRUD conventions, soft delete/restore/purge/export and audit hooks for
  places, events, clubs, sports, services, food, shuttle, career and media.
- Published translation catalogue with TR/EN/RU draft/publish/history/rollback.
- Department scope remains available through admin role grants; the separate
  Trainer panel and its public routes were retired to keep one management UI.
- Moderation workflows, media safety and private review URLs.

## Deliberate boundaries / next modules

This change establishes the reusable architecture and the highest-risk admin
surfaces. The database already contains further operational entities. Their
Filament resources can now be added without inventing new authorization or
CRUD patterns: applications, appointments, academic years, staff, directory
buildings/rooms, onboarding, achievements, survey options/results, event
participation types and daily menus. Private messages, psychological notes and
AICAD message bodies must remain separate security domains and must not be
added to general-purpose resources.

Before production rollout, migrate existing staff from one-row
`role_assignments` into `role_grants`, configure institutional MFA/SMTP, and
run the complete deployment suite against the production database engine.
