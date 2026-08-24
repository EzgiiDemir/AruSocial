# ARUCAD Campus API Contract

This document is regenerated from live Laravel registration. It is not a design proposal.

**Source of truth (in order):** `backend/routes/api.php` → `php artisan route:list --path=api` → controllers → `EnsurePermission` / `GranularPermissions::KEYS` → JSON returned by controllers and models → feature tests → Flutter `RestCampusRepository`.

**Documented application endpoints: 108** (HEAD omitted).  
**`php artisan route:list --path=api` unique `METHOD + path` (HEAD omitted): 108.**  
Undocumented public API routes: **0**.

Not in this contract:

- `GET /up` (framework health in `bootstrap/app.php`, not under `/api`)
- Web / console routes
- Controllers with **no** `Route::` entry: `Api/Admin/UserController`, `Api/Admin/AcademicStaffController`, `Api/RealtimeController`

There are **no named routes**. Paths and verbs match the code, including `POST …/delete`. The only HTTP `DELETE` is content draft.

---

## Base URL

```
{origin}/api/v1
```

Example: `GET http://localhost:4000/api/v1/health`

Flutter `ApiClient` already prefixes `/api/v1`. Repository paths in tables below are the suffixes (`/me`, `/admin/audit-log`, …).

---

## Envelope

Every application JSON response:

```json
{
  "data": {},
  "meta": { "request_id": "req-…" },
  "error": null
}
```

Failure: `data` is `null`, `error` is `{ "code": "STRING", "message": "…" }`.

`meta.request_id` is per-response (`req-` + UUID). It is not pagination.

Site-settings `POST` uses Laravel `$request->validate()`. A failed validate may return **422** in Laravel’s default validation JSON, not this envelope.

`throttle:api` may return **429** (framework).

---

## Auth model

| Class | Middleware | Typical status |
| --- | --- | --- |
| **Public** | `throttle:api` only | 200, plus action errors |
| **Authenticated** | `auth:sanctum` + `not-banned` | 401 `AUTH_REQUIRED`; 403 `ACCOUNT_BANNED` if `users.banned_at` is set |
| **Permission** | plus `permission:{key}` | 403 `FORBIDDEN` (`EnsurePermission`) |

Bearer token: `Authorization: Bearer {plainTextToken}` from `POST /auth/session`.

Permission keys are `GranularPermissions::KEYS` (not role names):

`events.manage` · `pendingActivities.manage` · `clubs.manage` · `sports.manage` · `services.manage` · `food.manage` · `directory.manage` · `pages.manage` · `media.manage` · `surveys.manage` · `academicYears.manage` · `moderation.moderate` · `email.send` · `stats.view` · `activityLog.view` · `users.manage`

`GET /admin/roles/{email}` is Authenticated only. The controller allows the caller’s own email; anyone else’s address requires `users.manage`.

---

## Pagination

Four list endpoints are length-aware. Query params:

| Param | Default | Rules |
| --- | --- | --- |
| `page` | `1` | integer ≥ 1 |
| `perPage` | `20` | integer 1–**50** |

Invalid values return **400** `VALIDATION` in the standard envelope (`page must be an integer >= 1 and perPage must be an integer between 1 and 50.`).

Laravel `paginate()` runs **after** scope / filter / sort. `meta.pagination` is camelCase next to `request_id`:

```json
{
  "data": [],
  "meta": {
    "request_id": "req-…",
    "pagination": {
      "currentPage": 1,
      "perPage": 20,
      "total": 123,
      "lastPage": 7
    }
  },
  "error": null
}
```

| Endpoint | Order | Notes |
| --- | --- | --- |
| `GET /feed` | `created_at DESC`, `id DESC` | Previously unbounded. Default page is 20 posts. |
| `GET /notifications` | `created_at DESC`, `id DESC` | Scoped to the Sanctum user. Old cap of 100 is gone; history is pageable. No read/unread query filter. |
| `GET /admin/audit-log` | `at DESC`, `id DESC` | `perm:activityLog.view`. Old cap of 200 is gone; older rows are on later pages. |
| `GET /admin/email-logs` | `sent_at DESC`, `id DESC` | `perm:email.send`. Old cap of 200 is gone. No extra list filters. |

Omitting the query params still returns **page 1** of **20**. Clients that ignore `meta.pagination` keep working.

Still **not** paginated (hard caps / full dumps):

| Endpoint | Behaviour |
| --- | --- |
| `GET /me/activity` | newest **100** |
| `GET /content/{contentKey}/revisions` | last **20** |
| `GET /admin/stats` | query `days` clamped **1–90** (default 14); one payload |
| Events, media, food, reports, stories, … | full result set |

---

## Actor / identity on writes

Authenticated user is always `request()->user()` (Sanctum).

Body fields such as `actorName`, `assignedBy`, `adminName`, `userId`, `editorName` are **not** the audit actor. `assignedBy` on role upsert is stored on `role_assignments.assigned_by`. Audit rows use `AuditLogger::logAsCurrentUser`.

---

## Error codes (observed)

| Code | Status | When |
| --- | --- | --- |
| `AUTH_REQUIRED` | 401 | Missing/invalid Sanctum token |
| `INVALID_CREDENTIALS` | 401 | Wrong password on session |
| `FORBIDDEN` | 403 | Permission middleware or in-controller ACL |
| `ACCOUNT_BANNED` | 403 | Ban flag |
| `DOMAIN_NOT_ALLOWED` | 403 | Email domain on session |
| `VALIDATION` | 400 or 422 | Controller `fail` or Laravel `validate` |
| `CONTENT_BLOCKED` | 400 | Text/image moderation |
| `FILE_TOO_LARGE` / `UNSUPPORTED_FILE_TYPE` | 400 | Media / image check |
| `PLACE_UNAVAILABLE` | 409 | Place already booked (own activity or admin upsert) |
| `*_NOT_FOUND` | 404 | Missing entity (`EVENT_NOT_FOUND`, `PLACE_NOT_FOUND`, `FOOD_VENUE_NOT_FOUND`, `MEDIA_NOT_FOUND`, `POST_NOT_FOUND`, …) |
| `AI_NOT_CONFIGURED` | 501 | `GROQ_API_KEY` unset |
| `AI_UPSTREAM_ERROR` | 502 | Groq call failed |

Unlisted codes may still exist on a given action; do not invent extras.

---

## Inventory by group

Auth column: `Public` · `Auth` · `perm:{key}`.

### Health (public)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1` | Public | — | `{ status, service, version, database }` |
| GET | `/api/v1/health` | Public | — | same |

`status` is `"ok"`, `service` is `"arucad-campus-api"`, `version` is `"v1"`, `database` is `"ok"` or `"unavailable"`. Reachable without a token, including for a banned account.

### Auth / current user

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| POST | `/api/v1/auth/session` | Public | JSON `{ email, password }` | `{ user: User, token }` |
| POST | `/api/v1/auth/logout` | Auth | — | `{ signedOut: true }` |
| GET | `/api/v1/me` | Auth | — | `User` |
| GET | `/api/v1/me/quests` | Auth | — | `{ id, title, subtitle, progress, target, reward }[]` |
| GET | `/api/v1/me/activity` | Auth | — | `{ id, kind, title, subtitle, meta }[]` (max 100) |

`User`: `{ id, name, role, level, xp, places, events, memories, interests, avatarUrl }`. `role` comes from `role_assignments`, not `users.role`.

Session: empty email/password → 422 `VALIDATION`. Domain mismatch → 403 `DOMAIN_NOT_ALLOWED`. Bad password → 401 `INVALID_CREDENTIALS`. Banned → 403 `ACCOUNT_BANNED`. First login **creates** the user.

### Places / reviews / check-ins

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/places` | Auth | — | place list |
| GET | `/api/v1/places/{id}` | Auth | — | one place; 404 `PLACE_NOT_FOUND` |
| GET | `/api/v1/places/{id}/availability` | Auth | query `date` **required** (`YYYY-MM-DD`) | `{ eventId, title, time, workflowStatus }[]`; 400 if `date` missing |
| GET | `/api/v1/places/{id}/reviews` | Auth | — | reviews |
| POST | `/api/v1/places/{id}/reviews` | Auth | `{ rating, comment? }` | created review; 400 `CONTENT_BLOCKED` |
| POST | `/api/v1/places/{id}/report` | Auth | `{ reason }` | `{ reported: true }` |
| POST | `/api/v1/checkins` | Auth | `{ placeId, visibleToOthers? }` | `{ checkedIn: true }`; 404 `PLACE_NOT_FOUND` |

Place: `{ id, name, category, lat, lng, description, distance, density, street, tourUrl, accessible, photos, rating }`.

Review: `{ id, placeId, author, rating, comment, meta }` (`author` from the user relation).

`visibleToOthers` defaults true unless the JSON value is exactly `false`.

### Events (student)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/events` | Auth | query `includeUnpublished=true`, `academicYearId` | events (default: not draft, `workflow_status=published`) |
| GET | `/api/v1/events/mine` | Auth | — | caller’s proposed activities |
| POST | `/api/v1/events/mine` | Auth | `{ title, placeId, time?, eventDate?, category?, description? }` | created event **201**; 400 `VALIDATION` / `INVALID_PLACE`; 409 `PLACE_UNAVAILABLE` |
| GET | `/api/v1/events/{id}` | Auth | — | event; 404 `EVENT_NOT_FOUND` |
| POST | `/api/v1/events/{id}/join` | Auth | optional `{ participationTypeId }` | event + `participationStatus`; 400 `INVALID_PARTICIPATION_TYPE` |
| POST | `/api/v1/events/{id}/join/form` | Auth | — | `{ formSubmitted, formCompletedEmailSent }`; 400 `NOT_JOINED` |

`/events/mine` is registered **before** `/events/{id}`.

Event: `{ id, title, time, eventDate, placeName, category, attendees, xp, draft, publishAt, expiresAt, audience, organizer, organizerEmail, description, workflowStatus, reviewNote, placeId, academicYearId, createdByUserId, participationTypes: [{ id, label }] }`.

`participationStatus`: `{ joined, alreadyJoined, formSubmitted, clubEmailSent, formEmailSent }`.

Own activity starts `draft: true`, `workflowStatus: "pending_review"`.

### Feed / comments / stories / leaderboard

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/feed` | Auth | query `page`, `perPage` (default 1 / 20, max 50) | `FeedPost[]` + `meta.pagination` |
| POST | `/api/v1/feed` | Auth | `{ text, imageUrl?, visibility?, postType?, courseTag?, locationTag? }` | post; 400 `CONTENT_BLOCKED` |
| POST | `/api/v1/feed/{id}/like` | Auth | — | updated post (toggle like) |
| POST | `/api/v1/feed/{id}/comments` | Auth | `{ text }` | updated post |
| POST | `/api/v1/feed/{id}/report` | Auth | `{ reason }` | `{ reported: true }` |
| GET | `/api/v1/stories` | Auth | — | stories from the last 24 hours |
| POST | `/api/v1/stories` | Auth | `{ text?, backgroundColorValue?, visibility? }` | story; 400 `CONTENT_BLOCKED` |
| GET | `/api/v1/leaderboard` | Auth | — | `{ name, xp, isMe }[]` |

`FeedPost`: `{ id, authorId, name, text, meta, likes, likedByMe, imageUrl, comments, visibility, postType, courseTag, locationTag, official }`.

Comment: `{ id, author, text, meta }`. `visibility` is `onlyMe` or `everyone`. Like is a **toggle**. 404 `POST_NOT_FOUND`.

### Social / saved posts / chat / notifications / push

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/social/following` | Auth | — | **array of display names** |
| GET | `/api/v1/social/blocked` | Auth | — | **array of display names** |
| POST | `/api/v1/social/follow` | Auth | `{ peer }` or `{ peerId }` | `{ following: boolean }` |
| POST | `/api/v1/social/block` | Auth | `{ peer }` or `{ peerId }` | `{ blocked: boolean }` |
| GET | `/api/v1/saved-posts` | Auth | — | array of post ids |
| POST | `/api/v1/saved-posts/toggle` | Auth | `{ postId }` | `{ saved: boolean }` |
| GET | `/api/v1/chat/threads` | Auth | — | **array of peer display names** |
| GET | `/api/v1/chat/{peer}/messages` | Auth | `{peer}` URL-decoded name or id | messages, or `[]` if no conversation |
| POST | `/api/v1/chat/{peer}/messages` | Auth | `{ text }` | sent message; 400 if empty / self |
| GET | `/api/v1/notifications` | Auth | query `page`, `perPage` (default 1 / 20, max 50) | current user's rows + `meta.pagination` |
| POST | `/api/v1/notifications/{id}/read` | Auth | — | `{ read: true }`; 404 `NOTIFICATION_NOT_FOUND` |
| POST | `/api/v1/notifications/read-all` | Auth | — | `{ read: true }` |
| POST | `/api/v1/push-tokens` | Auth | `{ token, platform }` (`android` \| `ios` \| `web`) | `{ registered: true }` |
| POST | `/api/v1/push-tokens/unregister` | Auth | `{ token }` | `{ unregistered: true }` |

Chat is REST for history (GET/POST messages) plus **private Reverb channels** for delivery. There is **no extra HTTP chat route**. `/broadcasting/auth` is Laravel's standard private-channel authorizer (not under `/api/v1`, not in the 108-route inventory).

Chat message: `{ id, fromMe, text, sentAt, sender, peer, conversationId }`. `conversationId` is additive. `fromMe` is viewer-relative on REST; WebSocket clients recompute it from `sender`.

Inbox notification rows are written independently of FCM. Creating a notification dispatches `DeliverFcmNotification` to every stored token for that user. FCM failure is logged and does not roll back the inbox row or the triggering mutation. There is **no extra HTTP route** for send. Invalid FCM tokens (`UNREGISTERED` / `NOT_FOUND`) are deleted. Foreground chat still uses Reverb; FCM is for background / killed / socket-down devices. The client suppresses OS banners while the app is in the foreground.

`POST /push-tokens` is authenticated (`auth:sanctum`). The token is bound to `currentUser()`, not a client-supplied user id. Re-registering the same token updates the row; a second device adds a second row. `POST /push-tokens/unregister` deletes only that user's matching token. Registering a token already owned by someone else moves it to the caller.

Notification: `{ id, kind, title, body, read, createdAt, actorUserId }`.

`peer` is resolved by unique display name; ambiguous name → 400 `VALIDATION`. `peerId` is preferred when the client has an id.

### Catalog reads (authenticated, no extra permission)

| Method | Path | Auth | `data` |
| --- | --- | --- | --- |
| GET | `/api/v1/clubs` | Auth | clubs |
| GET | `/api/v1/clubs/{id}` | Auth | club; 404 `CLUB_NOT_FOUND` |
| GET | `/api/v1/sports` | Auth | sports |
| GET | `/api/v1/services` | Auth | services |
| GET | `/api/v1/services/{id}` | Auth | service |
| GET | `/api/v1/food-venues` | Auth | food venues + `dailyMenus` |
| GET | `/api/v1/directory` | Auth | directory |
| GET | `/api/v1/pages` | Auth | pages; query `publishedOnly=true` filters to published |
| GET | `/api/v1/pages/{slug}` | Auth | page; 404 `PAGE_NOT_FOUND` |
| GET | `/api/v1/academic-years` | Auth | years |
| GET | `/api/v1/surveys/active` | Auth | currently active surveys |
| POST | `/api/v1/surveys/{id}/vote` | Auth | `{ optionIds }` | updated survey |
| POST | `/api/v1/ai/query` | Auth | `{ prompt }` | `{ answer }` or 501/502 |
| POST | `/api/v1/moderation/check-image` | Auth | `{ imageBase64, mimeType? }` | `{ allowed: true }` or 400 `CONTENT_BLOCKED` |

Club: `{ id, name, category, description, body }`.  
Sport: `{ id, name, facility, contact }`.  
Service: `{ id, title, category, description, contact, building, floor, room, contactPerson, topics, hours, body }`.  
Directory: `{ id, building, floor, room, occupantName, occupantRole, relatedServiceId }`.  
Page: `{ id, title, slug, blocks, status, updatedAt, updatedBy }`.  
Academic year: `{ id, label, startsOn, endsOn, isActive }`.  
Survey: `{ id, question, description, startsAt, endsAt, targetAudience, multipleChoice, anonymous, showResults, active, totalVotes, myOptionIds, options: [{ id, label, votes, percentage }] }`.

### Food (P2-1)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/food-venues` | Auth | — | `FoodVenue[]` |
| POST | `/api/v1/admin/food-venues` | `perm:food.manage` | `{ id, name, hours?, menuFileUrl? }` | venue; 400 if missing id/name |
| POST | `/api/v1/admin/food-venues/{id}/delete` | `perm:food.manage` | — | `{ deleted: true }` |
| POST | `/api/v1/admin/food-venues/{venueId}/menus` | `perm:food.manage` | `{ date, items?, price?, hours? }` | venue; 404 `FOOD_VENUE_NOT_FOUND` |
| POST | `/api/v1/admin/food-venues/{venueId}/menus/{date}/delete` | `perm:food.manage` | `{date}` `Y-m-d` | `{ deleted: true }` |

`FoodVenue`: `{ id, name, hours, menuFileUrl, dailyMenus: [{ date, items, price, hours }] }`.

No `PUT`/`DELETE` verb for food. Successful writes audit as `food_venue` / `food_menu`.

### Media (P2-2)

Permission `media.manage` on all four (paths are **not** under `/admin`).

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/media` | `perm:media.manage` | — | `MediaItem[]` |
| POST | `/api/v1/media` | `perm:media.manage` | **multipart** field `file` (JPEG/PNG/WEBP/GIF, ≤8MB) | item, **201** |
| POST | `/api/v1/media/{id}` | `perm:media.manage` | JSON `{ fileName?, usedIn? }` | item; 404 `MEDIA_NOT_FOUND` |
| POST | `/api/v1/media/{id}/delete` | `perm:media.manage` | — | `{ deleted: true }` |

`MediaItem`: `{ id, url, fileName, uploadedAt, uploadedBy, usedIn }`.

Upload errors: 400 `VALIDATION`, `FILE_TOO_LARGE`, `UNSUPPORTED_FILE_TYPE`.

### Content revisions (same permission as media)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/content/{contentKey}/revisions` | `perm:media.manage` | — | last 20 `{ id, savedAt, editorName, snapshot }` |
| POST | `/api/v1/content/{contentKey}/revisions` | `perm:media.manage` | `{ snapshot, editorName? }` | `{ recorded: true\|false }` (`editorName` is a stored label, not the audit actor) |
| GET | `/api/v1/content/{contentKey}/draft` | `perm:media.manage` | — | `{ blocks, updatedAt }` or `null` |
| POST | `/api/v1/content/{contentKey}/draft` | `perm:media.manage` | `{ blocks }` | `{ saved: true }` |
| DELETE | `/api/v1/content/{contentKey}/draft` | `perm:media.manage` | — | `{ deleted: true }` — **only HTTP DELETE in this API** |

### Site settings (P2-3)

Permission `users.manage`.

| Method | Path | Request | `data` |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/settings/site` | — | public Entra + WP URL + flag |
| POST | `/api/v1/admin/settings/site` | partial `{ entra?, wordpress? }` | same as GET |

```json
{
  "entra": { "tenantId": "", "clientId": "", "redirectUri": "" },
  "wordpress": { "siteUrl": "", "apiTokenConfigured": false }
}
```

Never returned: `wordpress.apiToken`, moderation `apiKey`, passwords. POST: omit `apiToken` to keep; `""` clears; non-empty sets. Invalid body may be **422** (Laravel validation JSON).

### Audit (P2-4)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/admin/audit-log` | `perm:activityLog.view` | query `page`, `perPage` (default 1 / 20, max 50) | rows, `at` desc + `meta.pagination` |

```json
{
  "id": "audit-…",
  "actorName": "…",
  "action": "create",
  "targetType": "food_venue",
  "targetLabel": "…",
  "at": "2026-08-23T16:00:00+00:00"
}
```

**No audit write endpoint.** Rows are created inside successful admin mutations.

### Moderation (admin)

Permission `moderation.moderate`.

| Method | Path | Request | `data` |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/reports` | — | `{ id, kind, targetId, targetLabel, reason, reportedAt }[]` |
| POST | `/api/v1/admin/reports/{id}/resolve` | `{ action }` | `{ resolved: true }`; 404 `REPORT_NOT_FOUND` |
| GET | `/api/v1/admin/settings/moderation` | — | `{ configured: boolean }` |
| POST | `/api/v1/admin/settings/moderation` | `{ apiKey }` | `{ configured: boolean }` — key never echoed |

### Events (admin)

| Method | Path | Auth | Request / notes |
| --- | --- | --- | --- |
| POST | `/api/v1/admin/events` | `perm:events.manage` | `{ id, title, time?, eventDate?, placeName?, placeId?, category?, attendees?, xp?, draft?, publishAt?, expiresAt?, audience?, organizer?, organizerEmail?, description?, academicYearId? }`; 409 `PLACE_UNAVAILABLE` |
| POST | `/api/v1/admin/events/{id}/delete` | `perm:events.manage` | `{ deleted: true }` |
| POST | `/api/v1/admin/events/{eventId}/participation-types` | `perm:events.manage` | participation type upsert body |
| POST | `/api/v1/admin/events/{eventId}/participation-types/{typeId}/delete` | `perm:events.manage` | `{ deleted: true }` typical |
| GET | `/api/v1/admin/events/{eventId}/participants` | `perm:events.manage` | join list |
| POST | `/api/v1/admin/events/{eventId}/participants/{joinId}/approve` | `perm:events.manage` | attendance approve |
| GET | `/api/v1/admin/events/pending` | `perm:pendingActivities.manage` | `workflow_status=pending_review` |
| POST | `/api/v1/admin/events/{id}/approve` | `perm:pendingActivities.manage` | publishes (`workflow_status=published`, `draft=false`) |
| POST | `/api/v1/admin/events/{id}/reject` | `perm:pendingActivities.manage` | `{ reviewNote? }` |

### Clubs / sports / services / directory / pages (admin writes)

| Method | Path | Auth |
| --- | --- | --- |
| POST | `/api/v1/admin/clubs` | `perm:clubs.manage` |
| POST | `/api/v1/admin/clubs/{id}/delete` | `perm:clubs.manage` |
| POST | `/api/v1/admin/sports` | `perm:sports.manage` |
| POST | `/api/v1/admin/sports/{id}/delete` | `perm:sports.manage` |
| POST | `/api/v1/admin/services` | `perm:services.manage` |
| POST | `/api/v1/admin/services/{id}/delete` | `perm:services.manage` |
| POST | `/api/v1/admin/directory` | `perm:directory.manage` |
| POST | `/api/v1/admin/directory/{id}/delete` | `perm:directory.manage` |
| POST | `/api/v1/admin/pages` | `perm:pages.manage` |
| POST | `/api/v1/admin/pages/{id}/delete` | `perm:pages.manage` |

Club/sport upsert: `{ id, name, … }`. Directory upsert requires `id`, `building`, `occupantName`. Deletes return `{ deleted: true }` even if the row was already gone.

### Roles

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/admin/roles` | `perm:users.manage` | — | `RoleAssignment[]` |
| POST | `/api/v1/admin/roles` | `perm:users.manage` | `{ email, role, assignedBy?, permissions? }` | assignment |
| POST | `/api/v1/admin/roles/{email}/delete` | `perm:users.manage` | — | `{ deleted: true }` |
| GET | `/api/v1/admin/roles/{email}` | Auth | — | `{ role }` or `{ role: null }`; 403 if not self and no `users.manage` |

Valid `role` strings: `student`, `clubManager`, `contentEditor`, `moderator`, `careerStaff`, `studentAffairs`, `superAdmin`.

`RoleAssignment`: `{ email, role, permissions, assignedAt, assignedBy }`. `assignedBy` is a stored column, not the audit actor.

### Surveys / academic years (admin)

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/api/v1/admin/surveys` | `perm:surveys.manage` |
| POST | `/api/v1/admin/surveys` | `perm:surveys.manage` — `{ question, options }` (≥2 options); optional `id` |
| POST | `/api/v1/admin/surveys/{id}/delete` | `perm:surveys.manage` |
| POST | `/api/v1/admin/academic-years` | `perm:academicYears.manage` — `{ id, label, startsOn, endsOn, isActive? }` |
| POST | `/api/v1/admin/academic-years/{id}/delete` | `perm:academicYears.manage` |

Activating a year (`isActive: true`) deactivates every other year.

### Email / stats

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/admin/email-logs` | `perm:email.send` | query `page`, `perPage` (default 1 / 20, max 50) | `{ id, toEmail, subject, template, status, error, attempts, sentAt }[]` + `meta.pagination` |
| POST | `/api/v1/admin/email-logs/{id}/retry` | `perm:email.send` | — | `{ status }`; 404 `EMAIL_NOT_FOUND` |
| POST | `/api/v1/admin/email/bulk` | `perm:email.send` | `{ recipients, subject, body }` | `{ sent, results }` |
| GET | `/api/v1/admin/stats` | `perm:stats.view` | query `days` (1–90, default 14) | aggregate object; `appUsage.trackable` is `false` |

---

## Removed vs previous contract

The previous `docs/API_CONTRACT.md` was a short prototype sketch. **Removed as live API** (never registered in `routes/api.php`):

- `POST /memories`
- `GET /map`
- `POST /routes`
- Bare `/places`, `/checkins`, `/events` without `/api/v1` and without Sanctum
- Users CRUD under `/admin/users`
- Academic-staff HTTP API
- Realtime / WebSocket / Reverb endpoints
- Audit `POST` / `PUT`
- Invented envelope codes that the live middleware does not emit as first-class product codes (`RATE_LIMITED`, `EVENT_FULL` as documented names, etc.)

---

## Flutter cross-check (`RestCampusRepository` + `RestAuthProvider`)

Client paths (after `ApiClient` `/api/v1`) match this contract for the calls that exist, including:

`/auth/session`, `/auth/logout`, `/me`, `/me/quests`, `/me/activity`, `/feed`, `/stories`, `/leaderboard`, `/ai/query`, `/food-venues`, `/media`, `/admin/audit-log`, `/admin/settings/site`, `/admin/settings/moderation`, `/chat/threads`, `/events/mine`, `/admin/events/pending`, `/admin/email-logs`, `/admin/email/bulk`, `/admin/reports`, `/places/{id}/availability?date=`, `/events?includeUnpublished=true`, `participationTypeId` on join.

Not a contract defect: WordPress CMS HTTP from the device (`WordPressDataSource`) is **not** this Laravel API.

Backend-only surfaces the app may not call (still documented): `GET /api/v1`, `GET /api/v1/health`, content draft `DELETE`, `POST /moderation/check-image`, `POST /push-tokens*`, some `{id}` catalog GETs.

Remaining client/server notes (not missing routes):

- Flutter may still send `assignedBy` on roles; audit actor is server-side.
- Event reject / attendance may send `reviewNote` / `actorName`; audit uses the Sanctum user.
- `GET /admin/stats?days=` matches backend query `days`.

---

## Not routed (do not call)

These PHP classes exist under `app/Http/Controllers/Api` but have **no** entries in `routes/api.php`:

- `Admin/UserController`
- `Admin/AcademicStaffController`
- `RealtimeController`

Controller-on-disk ≠ public API.

---

## Realtime / WebSocket

Not an HTTP API route and **not** part of the 108-route inventory.

| Piece | Value |
| --- | --- |
| Server | Laravel Reverb (Pusher protocol), default `ws://localhost:8080` |
| Private channels | `conversation.{conversationId}`, `user.{userId}` |
| Event | `message.created` (after the message row is committed) |
| Payload | Same chat message JSON as `POST /api/v1/chat/{peer}/messages` |
| Channel auth | `POST /broadcasting/auth` with Sanctum `Authorization: Bearer …` (token is **not** on the WebSocket query string) |

Authorization: the subscriber must be a conversation participant (`conversation.*`) or the channel owner (`user.{id}`). History remains `GET /api/v1/chat/{peer}/messages`.

---

## Planned / not implemented

Track in `docs/AUDIT_GERCEK_URUN.md`, not as live `/api/v1` paths:

- Club membership tables — later optional P2

---

## Route inventory (canonical, 108)

Machine-readable. One `METHOD /api/v1/...` per line. `tests/Feature/ApiContractInventoryTest.php` compares this list to `php artisan route:list --path=api` (HEAD omitted).

```
DELETE /api/v1/content/{contentKey}/draft
GET /api/v1
GET /api/v1/academic-years
GET /api/v1/admin/audit-log
GET /api/v1/admin/email-logs
GET /api/v1/admin/events/pending
GET /api/v1/admin/events/{eventId}/participants
GET /api/v1/admin/reports
GET /api/v1/admin/roles
GET /api/v1/admin/roles/{email}
GET /api/v1/admin/settings/moderation
GET /api/v1/admin/settings/site
GET /api/v1/admin/stats
GET /api/v1/admin/surveys
GET /api/v1/chat/threads
GET /api/v1/chat/{peer}/messages
GET /api/v1/clubs
GET /api/v1/clubs/{id}
GET /api/v1/content/{contentKey}/draft
GET /api/v1/content/{contentKey}/revisions
GET /api/v1/directory
GET /api/v1/events
GET /api/v1/events/mine
GET /api/v1/events/{id}
GET /api/v1/feed
GET /api/v1/food-venues
GET /api/v1/health
GET /api/v1/leaderboard
GET /api/v1/me
GET /api/v1/me/activity
GET /api/v1/me/quests
GET /api/v1/media
GET /api/v1/notifications
GET /api/v1/pages
GET /api/v1/pages/{slug}
GET /api/v1/places
GET /api/v1/places/{id}
GET /api/v1/places/{id}/availability
GET /api/v1/places/{id}/reviews
GET /api/v1/saved-posts
GET /api/v1/services
GET /api/v1/services/{id}
GET /api/v1/social/blocked
GET /api/v1/social/following
GET /api/v1/sports
GET /api/v1/stories
GET /api/v1/surveys/active
POST /api/v1/admin/academic-years
POST /api/v1/admin/academic-years/{id}/delete
POST /api/v1/admin/clubs
POST /api/v1/admin/clubs/{id}/delete
POST /api/v1/admin/directory
POST /api/v1/admin/directory/{id}/delete
POST /api/v1/admin/email-logs/{id}/retry
POST /api/v1/admin/email/bulk
POST /api/v1/admin/events
POST /api/v1/admin/events/{eventId}/participants/{joinId}/approve
POST /api/v1/admin/events/{eventId}/participation-types
POST /api/v1/admin/events/{eventId}/participation-types/{typeId}/delete
POST /api/v1/admin/events/{id}/approve
POST /api/v1/admin/events/{id}/delete
POST /api/v1/admin/events/{id}/reject
POST /api/v1/admin/food-venues
POST /api/v1/admin/food-venues/{id}/delete
POST /api/v1/admin/food-venues/{venueId}/menus
POST /api/v1/admin/food-venues/{venueId}/menus/{date}/delete
POST /api/v1/admin/pages
POST /api/v1/admin/pages/{id}/delete
POST /api/v1/admin/reports/{id}/resolve
POST /api/v1/admin/roles
POST /api/v1/admin/roles/{email}/delete
POST /api/v1/admin/services
POST /api/v1/admin/services/{id}/delete
POST /api/v1/admin/settings/moderation
POST /api/v1/admin/settings/site
POST /api/v1/admin/sports
POST /api/v1/admin/sports/{id}/delete
POST /api/v1/admin/surveys
POST /api/v1/admin/surveys/{id}/delete
POST /api/v1/ai/query
POST /api/v1/auth/logout
POST /api/v1/auth/session
POST /api/v1/chat/{peer}/messages
POST /api/v1/checkins
POST /api/v1/content/{contentKey}/draft
POST /api/v1/content/{contentKey}/revisions
POST /api/v1/events/mine
POST /api/v1/events/{id}/join
POST /api/v1/events/{id}/join/form
POST /api/v1/feed
POST /api/v1/feed/{id}/comments
POST /api/v1/feed/{id}/like
POST /api/v1/feed/{id}/report
POST /api/v1/media
POST /api/v1/media/{id}
POST /api/v1/media/{id}/delete
POST /api/v1/moderation/check-image
POST /api/v1/notifications/read-all
POST /api/v1/notifications/{id}/read
POST /api/v1/places/{id}/report
POST /api/v1/places/{id}/reviews
POST /api/v1/push-tokens
POST /api/v1/push-tokens/unregister
POST /api/v1/saved-posts/toggle
POST /api/v1/social/block
POST /api/v1/social/follow
POST /api/v1/stories
POST /api/v1/surveys/{id}/vote
```

Generated from `backend/routes/api.php` + `php artisan route:list --path=api`.
