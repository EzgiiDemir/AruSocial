# ARUCAD Campus API Contract

This document is regenerated from live Laravel registration. It is not a design proposal.

**Source of truth (in order):** `backend/routes/api.php` → `php artisan route:list --path=api` → controllers → `EnsurePermission` / `GranularPermissions::KEYS` → JSON returned by controllers and models → feature tests → Flutter `RestCampusRepository`.

**Documented application endpoints: 215** (HEAD omitted).
**`php artisan route:list --path=api` unique `METHOD + path` (HEAD omitted): 215.**
Undocumented public API routes: **0**.

Not in this contract:

- `GET /up` (framework health in `bootstrap/app.php`, not under `/api`)
- Web / console routes
- Controllers with **no** `Route::` entry: `Api/Admin/UserController`, `Api/Admin/AcademicStaffController`, `Api/RealtimeController`

The sole named API route is `api.media.review-file`: it is an expiring signed
moderator-preview URL and is never exposed as a public media URL. Paths and
verbs otherwise match the code, including `POST …/delete`. The only HTTP
`DELETE` is content draft.

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

`events.manage` · `pendingActivities.manage` · `clubs.manage` · `places.manage` · `sports.manage` · `services.manage` · `food.manage` · `directory.manage` · `pages.manage` · `media.manage` · `career.manage` · `surveys.manage` · `academicYears.manage` · `staff.manage` · `applications.manage` · `appointments.manage` · `achievements.manage` · `moderation.moderate` · `email.send` · `stats.view` · `activityLog.view` · `users.manage` · `events.manageOwnDepartment`

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
| `GET /media/mine` | `uploaded_at DESC`, `id DESC` | Personal gallery for the Sanctum user only. |
| `GET /career/opportunities` | `created_at DESC`, `id DESC` | Published opportunities only for students. |
| `GET /admin/moderation/queue` | `uploaded_at DESC`, `id DESC` | Pending visual media (`moderation.moderate`). |

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
| `*_NOT_FOUND` | 404 | Missing entity (`EVENT_NOT_FOUND`, `PLACE_NOT_FOUND`, `FOOD_VENUE_NOT_FOUND`, `MEDIA_NOT_FOUND`, `POST_NOT_FOUND`, `STORY_NOT_FOUND`, …) — `POST_NOT_FOUND`/`STORY_NOT_FOUND` on the owner-only edit/delete routes also cover "exists but isn't yours" |
| `AI_NOT_CONFIGURED` | 501 | `GROQ_API_KEY` unset (Ask ARUCAD or poster draft) |
| `AI_UPSTREAM_ERROR` | 502 | Groq call failed |
| `ENTRA_NOT_CONFIGURED` | 501 | Entra tenant/client unset |
| `ENTRA_TOKEN_INVALID` | 401 | ID token failed JWKS/claims checks |
| `CHECKIN_OFF_CAMPUS` | 403 | GPS outside every ARUCAD campus geofence |
| `WORDPRESS_NOT_CONFIGURED` | 501 | WP URL/token missing for form snapshot |
| `WORDPRESS_UPSTREAM_ERROR` | 502 | WP forms fetch failed |
| `ASK_CONVERSATION_NOT_FOUND` | 404 | Ask ARUCAD thread missing or not owned |
| `ROUTING_NOT_CONFIGURED` | 501 | `ROUTING_BASE_URL` unset |
| `ROUTING_UNAVAILABLE` | 502 | Routing provider returned no route |
| `INVALID_COORDINATE` | 400 | Lat/lng out of range |
| `BUILDING_NOT_FOUND` / `FLOOR_NOT_FOUND` | 404 | Directory hierarchy miss |
| `ALREADY_REVIEWED` | 409 | Moderation queue item not pending |
| `ALREADY_APPLIED` | 409 | Student already has an open (non-terminal) application for this target |
| `ALREADY_APPROVED` | 409 | Student already has an `approved` application for this target |
| `ALREADY_MEMBER` | 409 | Already a club/community member (apply route) |
| `INVALID_STATE` | 409 | Application approve/reject/revise before the Detail form is in (`detail_form_submitted`/`under_review`) |
| `REVIEW_NOTE_REQUIRED` | 422 | Reject/revise without a `reviewNote` for the student |
| `PREVIEW_ANSWERS_INCOMPLETE` | 422 | Preview submission missing a required `ApplicationQuestion` answer |

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
| GET | `/api/v1/auth/entra/config` | Public | | `{ configured, tenantId, clientId, redirectUri }` (empty strings when unset) |
| POST | `/api/v1/auth/entra` | Public | `{ idToken }` | `{ user, token }`; 501 `ENTRA_NOT_CONFIGURED`; 401 `ENTRA_TOKEN_INVALID` |
| POST | `/api/v1/auth/logout` | Auth | — | `{ signedOut: true }` |
| GET | `/api/v1/me` | Auth | — | `User` |
| POST | `/api/v1/me/profile` | Auth | `{ department?, year?, university?, clubs?, achievements?, projects? }` | updated `User`; 400 `VALIDATION` |
| GET | `/api/v1/me/quests` | Auth | — | `{ id, title, subtitle, progress, target, reward }[]` |
| GET | `/api/v1/me/activity` | Auth | — | `{ id, kind, title, subtitle, meta, createdAt, xp }[]` (max 100) |
| GET | `/api/v1/me/onboarding` | Auth | — | `{ done: string[], startedAt }` |
| POST | `/api/v1/me/onboarding/{stepId}` | Auth | `{ completed }` | `{ completed }`; idempotent |
| GET | `/api/v1/onboarding-steps` | Auth | — | active `OnboardingStep[]`, ordered |
| GET | `/api/v1/admin/onboarding-steps` | `perm:onboarding.manage` | — | all `OnboardingStep[]` (incl. inactive), ordered |
| POST | `/api/v1/admin/onboarding-steps` | `perm:onboarding.manage` | `{ id, groupLabel, title, detail, actionKind?, refId?, sortOrder?, active? }` | upserted step; 400 `VALIDATION` |
| POST | `/api/v1/admin/onboarding-steps/{id}/delete` | `perm:onboarding.manage` | — | `{ deleted: true }` |
| GET | `/api/v1/me/settings` | Auth | — | `{ locationVisibility, nearbyDiscoverable, checkInVisible, personalization }` |
| POST | `/api/v1/me/settings` | Auth | any subset of the same keys | updated settings; 400 `VALIDATION` |

`User`: `{ id, name, role, level, xp, places, events, memories, interests, avatarUrl, department, year, university, clubs, achievements, projects }`. `role` comes from `role_assignments`, not `users.role`. The last six fields are the Social-tab bio editor (`department`/`year`/`university` nullable strings; `clubs`/`achievements`/`projects` string arrays — free text the student typed, **not** related to the `clubs`/`club_members` tables), scoped to `currentUser()` — replaced the client-only `ProfileBioStore` SharedPreferences overlay. `POST /me/profile` is a partial update: omitted keys keep their current value, an explicitly sent `null`/`[]` clears it.

Session: empty email/password → 422 `VALIDATION`. Domain mismatch → 403 `DOMAIN_NOT_ALLOWED`. Bad password → 401 `INVALID_CREDENTIALS`. Banned → 403 `ACCOUNT_BANNED`. First login **creates** the user.

`/me/onboarding` is the "First 30 Days" checklist's completion state (`onboarding_progress`: `user_id` + `step_id`, unique pair). The checklist content itself — `OnboardingStep`: `{ id, group, title, detail, actionKind, refId, sortOrder }` (`actionKind` one of `service`/`list`/`info`, mirroring `onboarding_config.dart`'s `OnboardingActionKind`) — is now real, admin-editable content in `onboarding_steps`, seeded from the same 13 ids the Dart const originally shipped so existing `onboarding_progress.step_id` rows keep resolving. Mock mode (no backend) still uses `onboarding_config.dart`'s const as its offline seed. `startedAt` is `users.created_at` (a real, cross-device "day 1"). `POST /me/onboarding/{stepId}` is idempotent both ways — marking an already-done step done again, or un-marking one never marked, are both no-ops.

`/me/settings` is the shared privacy/personalization prefs (`locationVisibility` is `ghost`/`friends`/`community`/`public`; the other three are booleans). Defaults match the previous client defaults (ghost / nearby off / check-ins visible / personalization on). `POST /me/settings` is a partial update scoped to `currentUser()`.

`GET /me/quests` `progress` is computed: `distinct_checkins` = distinct places the student has checked into, `event_joins` = rows in `event_joins`, `static` = the stored column. Capped at `target`.

`GET /me/activity` includes `createdAt` and `xp` so the Activity tab can sum yearly XP without a separate ledger. Product rule: `users.xp` is the lifetime total and is never reset; yearly XP is the sum of those `xp` values in the selected calendar year. Check-in is always `10`; event join is parsed from the `+$N XP` subtitle written at award time.

### Places / reviews / check-ins

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/places` | Auth | — | place list |
| GET | `/api/v1/places/{id}` | Auth | — | one place; 404 `PLACE_NOT_FOUND` |
| GET | `/api/v1/places/{id}/availability` | Auth | query `date` **required** (`YYYY-MM-DD`) | `{ eventId, title, time, workflowStatus }[]`; 400 if `date` missing |
| GET | `/api/v1/places/{id}/reviews` | Auth | — | reviews |
| POST | `/api/v1/places/{id}/reviews` | Auth | `{ rating, comment? }` | created review; 400 `CONTENT_BLOCKED` |
| POST | `/api/v1/places/{id}/report` | Auth | `{ reason }` | `{ reported: true }` |
| POST | `/api/v1/places/{id}/cover` | Auth | `{ url }` | updated place; 404 `PLACE_NOT_FOUND`; 400 `VALIDATION` |
| GET | `/api/v1/places/{id}/workshop` | Auth | — | `{ equipment: [{id,name,available}], posts: [{id,authorId,authorName,text,createdAt}] }`; 404 `PLACE_NOT_FOUND` |
| POST | `/api/v1/places/{id}/workshop/posts` | Auth | `{ text }` | created post; 400 `VALIDATION`/`CONTENT_BLOCKED`; 404 `PLACE_NOT_FOUND` |
| POST | `/api/v1/admin/places` | `perm:places.manage` | `{ id, name, category, lat, lng, description?, distance?, street?, tourUrl?, accessible? }` | upserted place; 400 `VALIDATION` |
| POST | `/api/v1/admin/places/{id}/delete` | `perm:places.manage` | — | `{ deleted: true }` |
| POST | `/api/v1/admin/places/{id}/workshop/equipment` | `perm:places.manage` | `{ id?, name, available?, sortOrder? }` | upserted equipment item; 400 `VALIDATION`; 404 `PLACE_NOT_FOUND` |
| POST | `/api/v1/admin/places/{id}/workshop/equipment/{itemId}/delete` | `perm:places.manage` | — | `{ deleted: true }` |
| POST | `/api/v1/admin/places/{id}/workshop/posts/{postId}/delete` | `perm:places.manage` | — | `{ deleted: true }` |
| POST | `/api/v1/admin/reviews/{id}/delete` | `perm:moderation.moderate` | — | `{ deleted: true }` |
| POST | `/api/v1/checkins` | Auth | `{ placeId, visibleToOthers? }` | `{ checkedIn: true }`; 404 `PLACE_NOT_FOUND` |

Place: `{ id, name, category, lat, lng, description, distance, density, street, tourUrl, accessible, photos, rating, coverUrl, recentCheckins, recentCheckinEntries }`.

`density` is derived from check-ins in the last 2 hours (`quiet` / `moderate` / `busy`); `rating` is the average of `reviews.rating` for that place (0 if none); `recentCheckins` is that same 2-hour count. `recentCheckinEntries` is the same 2-hour, `visible_to_others=true` window as up to `PlacePresence::MAX_RECENT_ENTRIES` (3) real entries `{ initial, checkedInAt }`, most recent first — `initial` is the first letter of the checked-in user's real name, not a fabricated one. The static `places.density` / `places.rating` columns are not what the API returns. `coverUrl` is the shared cover pointer (replaces device-local `PlacePhotoStore`).

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
| POST | `/api/v1/feed/{id}` | Auth, owner only | `{ text?, visibility? }` | updated post; 404 `POST_NOT_FOUND` if not your own; 400 `CONTENT_BLOCKED` |
| POST | `/api/v1/feed/{id}/delete` | Auth, owner only | — | `{ deleted: true }`; 404 `POST_NOT_FOUND` if not your own |
| POST | `/api/v1/feed/{id}/like` | Auth | — | updated post (toggle like) |
| POST | `/api/v1/feed/{id}/comments` | Auth | `{ text }` | updated post |
| POST | `/api/v1/feed/{id}/report` | Auth | `{ reason }` | `{ reported: true }` |
| GET | `/api/v1/stories` | Auth | — | stories from the last 24 hours, including `backgroundColorValue` |
| POST | `/api/v1/stories` | Auth | `{ text?, backgroundColorValue?, visibility? }` | story; 400 `CONTENT_BLOCKED` |
| POST | `/api/v1/stories/{id}/delete` | Auth, owner only | — | `{ deleted: true }`; 404 `STORY_NOT_FOUND` if not your own |
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
| GET | `/api/v1/chat/threads` | Auth | — | `{ name, avatarUrl }[]` — `avatarUrl` is the peer's real `users.avatar_url`, `null` if they haven't set one |
| GET | `/api/v1/chat/{peer}/messages` | Auth | `{peer}` URL-decoded name or id | messages, or `[]` if no conversation |
| POST | `/api/v1/chat/{peer}/messages` | Auth | `{ text }` | sent message; 400 if empty / self |
| GET | `/api/v1/notifications` | Auth | query `page`, `perPage` (default 1 / 20, max 50) | current user's rows + `meta.pagination` |
| POST | `/api/v1/notifications/{id}/read` | Auth | — | `{ read: true }`; 404 `NOTIFICATION_NOT_FOUND` |
| POST | `/api/v1/notifications/read-all` | Auth | — | `{ read: true }` |
| POST | `/api/v1/push-tokens` | Auth | `{ token, platform }` (`android` \| `ios` \| `web`) | `{ registered: true }` |
| POST | `/api/v1/push-tokens/unregister` | Auth | `{ token }` | `{ unregistered: true }` |

Chat is REST for history (GET/POST messages) plus **private Reverb channels** for delivery. There is **no extra HTTP chat route**. `/broadcasting/auth` is Laravel's standard private-channel authorizer (not under `/api/v1`, not in the route inventory).

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
| POST | `/api/v1/clubs/{id}/join` | Auth | `{ joined: true }`; 404 `CLUB_NOT_FOUND`; idempotent |
| POST | `/api/v1/clubs/{id}/leave` | Auth | `{ joined: false }`; idempotent even if never joined |
| GET | `/api/v1/club-memberships` | Auth | array of club ids the caller has joined |
| GET | `/api/v1/sports` | Auth | sports |
| GET | `/api/v1/services` | Auth | services |
| GET | `/api/v1/services/{id}` | Auth | service |
| GET | `/api/v1/food-venues` | Auth | food venues + `dailyMenus` |
| GET | `/api/v1/directory` | Auth | directory; query `building`, `floor` (filters, not new routes) |
| GET | `/api/v1/directory/buildings` | Auth | `[{ id, name, entryCount }]` soft hierarchy |
| GET | `/api/v1/directory/buildings/{building}/floors` | Auth | floors; 404 `BUILDING_NOT_FOUND` |
| GET | `/api/v1/directory/buildings/{building}/floors/{floor}/rooms` | Auth | rooms; 404 `FLOOR_NOT_FOUND` |
| POST | `/api/v1/routing/directions` | Auth | `{ fromLat, fromLng, toLat, toLng }` → route or 501/502 |
| GET | `/api/v1/tour-proxy/{path}` | None (rate-limited) | `{path}` mirrors 360.arucad.edu.tr's own path (e.g. `vista_export/Main/index.htm`) | proxied response from that same path upstream, with the same content type; HTML gets a `<base href>` rewritten to this same route tree (not the external host) and no `X-Frame-Options`, so both the document and every relative sub-resource it loads (scripts/images/XHR) stay same-origin and embed on Flutter web; 400 for a path-traversal attempt, 502 if the tour host itself fails |
| GET | `/api/v1/pages` | Auth | pages; query `publishedOnly=true` filters to published |
| GET | `/api/v1/pages/{slug}` | Auth | page; 404 `PAGE_NOT_FOUND` |
| GET | `/api/v1/academic-years` | Auth | years |
| GET | `/api/v1/surveys/active` | Auth | currently active surveys |
| POST | `/api/v1/surveys/{id}/vote` | Auth | `{ optionIds }` | updated survey |
| POST | `/api/v1/ai/query` | Auth + `throttle:ai` | `{ prompt }` or `{ messages, conversationId? }` | `{ answer, conversationId }`; 501 `AI_NOT_CONFIGURED`; 502 `AI_UPSTREAM_ERROR` |
| GET | `/api/v1/ask/conversations` | Auth | page/perPage | Ask threads for this account |
| GET | `/api/v1/ask/conversations/{id}` | Auth | thread + messages; 404 `ASK_CONVERSATION_NOT_FOUND` |
| POST | `/api/v1/ask/conversations/{id}/delete` | Auth | `{ deleted: true }` |
| POST | `/api/v1/moderation/check-image` | Auth | `{ imageBase64, mimeType? }` | `{ allowed: true }` or 400 `CONTENT_BLOCKED` |

Club: `{ id, name, category, description, body }`. Membership (`club_members`: `user_id` + `club_id`, unique pair) is the real, shared join — bound to `currentUser()`, never a client-supplied user id. Join/leave are idempotent, same rule as `/saved-posts/toggle`'s underlying row-exists semantics but with distinct join/leave actions instead of a toggle (so a duplicate "join" tap never flips a member back to "left").  
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

### Shuttle

Real backend + admin CRUD replacement for the previously hardcoded
`shuttleRoutes` const in `shuttle_config.dart` — mock mode still seeds from
that const (no backend to persist to there), but REST mode is the real
source of truth.

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/shuttle-routes` | Auth | — | `ShuttleRoute[]` |
| POST | `/api/v1/admin/shuttle-routes` | `perm:shuttle.manage` | `{ id, name, colorKey?, stops[], departures[], returns[]? }` | upserted route; 400 `VALIDATION` |
| POST | `/api/v1/admin/shuttle-routes/{id}/delete` | `perm:shuttle.manage` | — | `{ deleted: true }` |

`ShuttleRoute`: `{ id, name, colorKey, stops: string[], departures: string[] ('HH:mm'), returns: string[]|null }`. `colorKey` is one of `App\Models\ShuttleRoute::COLOR_KEYS` (`blue`, `yellow`, `success`, `warning`, `campusGreen`, `primary`, `danger`) — the fixed ARUCAD chrome palette, not a free hex value.

### Media (P2-2 + P4 Mega-2 video)

Permission `media.manage` on admin library paths (not under `/admin`). Personal gallery uses auth only.

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/media` | `perm:media.manage` | — | `MediaItem[]` |
| POST | `/api/v1/media` | `perm:media.manage` | **multipart** `file` — images JPEG/PNG/WEBP/GIF ≤8MB; video MP4/WEBM/MOV ≤64MB | item, **201** |
| POST | `/api/v1/media/{id}` | `perm:media.manage` | JSON `{ fileName?, usedIn? }` | item; 404 `MEDIA_NOT_FOUND` |
| POST | `/api/v1/media/{id}/delete` | `perm:media.manage` | — | `{ deleted: true }` |

`MediaItem`: `{ id, url, fileName, mimeType, uploadedAt, uploadedBy, usedIn, moderationStatus }` (`pending` \| `approved` \| `rejected`).

Video uploads and flagged images land as `pending` (human queue) — never auto-approved as “safe” when video moderation is unavailable. Upload errors: 400 `VALIDATION`, `FILE_TOO_LARGE`, `UNSUPPORTED_FILE_TYPE`.

### Human visual moderation queue (P4 Mega-2)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/admin/moderation/queue` | `perm:moderation.moderate` | page/perPage | pending `MediaItem[]` |
| POST | `/api/v1/admin/moderation/queue/{id}/resolve` | `perm:moderation.moderate` | `{ action: approved\|rejected }` | `{ id, moderationStatus }`; 409 `ALREADY_REVIEWED` |
| GET | `/api/v1/admin/moderation/posts` | `perm:moderation.moderate` | page/perPage | pending feed posts |
| POST | `/api/v1/admin/moderation/posts/{id}/approve` | `perm:moderation.moderate` | `{ id, workflowStatus }` |
| POST | `/api/v1/admin/moderation/posts/{id}/reject` | `perm:moderation.moderate` | `{ reviewNote? }` |
| GET | `/api/v1/admin/wordpress/versions` | `perm:users.manage` | page/perPage | form snapshots |
| POST | `/api/v1/admin/wordpress/versions` | `perm:users.manage` | `{ payload? }` | snapshot or 501/502 |

Distinct from student-filed `GET/POST /admin/reports*`.

### Poster → AI event draft (P4 Mega-2)

| Method | Path | Auth | Request | `data` |
| --- | --- | --- | --- | --- |
| POST | `/api/v1/admin/events/draft-from-poster` | `perm:events.manage` | **multipart** image `file` ≤8MB | `{ event, extracted, incomplete, mediaId }` **201**; never publishes (`draft` + `workflowStatus=draft` + `aiDraft=true`); 501 `AI_NOT_CONFIGURED` without `GROQ_API_KEY` |

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

### Trainer Panel

A department head publishing events scoped to their own department only —
separate from the full Admin Panel. Both gates apply to every route below:
`perm:events.manageOwnDepartment` (is this account provisioned as a
trainer) and `department-head` middleware (resolves *which* department
from the `StaffProfile` linked via `user_id`; 403 `NOT_A_DEPARTMENT_HEAD`
if none). `responsibleStaffId`/department are always server-resolved,
never accepted from the client. Trainer-created events start
`workflowStatus=published` immediately (no review queue — the trainer
already is the accountable party), and every write is logged to the
Activity Log.

| Method | Path | Request | `data` |
| --- | --- | --- | --- |
| GET | `/api/v1/trainer/events` | — | this trainer's own events only |
| POST | `/api/v1/trainer/events` | `{ id?, title, placeId, eventDate?, time?, category?, description? }` | upserted event, `workflowStatus: "published"`; 409 `PLACE_UNAVAILABLE`; 404 `EVENT_NOT_FOUND` if `id` isn't one of this trainer's own events |
| POST | `/api/v1/trainer/events/{id}/delete` | — | `{ deleted: true }`; 404 `EVENT_NOT_FOUND` if not this trainer's own event |
| GET | `/api/v1/trainer/events/{eventId}/participants` | — | same shape as `/admin/events/{eventId}/participants`, scoped to this trainer's own event; 404 `EVENT_NOT_FOUND` if not theirs |
| POST | `/api/v1/trainer/events/{eventId}/participants/{joinId}/approve` | — | marks attendance approved; 400 `FORM_NOT_SUBMITTED` if the student hasn't completed the katılım formu yet; 404 if the event isn't this trainer's own |
| GET | `/api/v1/trainer/applications` | `?status=` | pending applications where `responsibleStaffId` is this trainer's own staff id (default) or filtered by status |
| POST | `/api/v1/trainer/applications/{id}/approve` | `{ reviewNote? }` | same outcome as `/admin/applications/{id}/approve`, scoped to this trainer's own department; 404 `APPLICATION_NOT_FOUND` if not theirs |
| POST | `/api/v1/trainer/applications/{id}/reject` | `{ reviewNote }` | scoped reject; 422 `REVIEW_NOTE_REQUIRED` if blank |
| POST | `/api/v1/trainer/applications/{id}/revise` | `{ reviewNote }` | scoped revision request; 422 `REVIEW_NOTE_REQUIRED` if blank |
| GET | `/api/v1/trainer/roster` | — | active `StaffProfile[]` in this trainer's own department only |

### Applications (two-stage Preview → Detail → Review)

The single, generic pipeline behind every "Katıl/Başvur" action app-wide
(clubs, sports, career, service/help targets, communities, …) —
`ParticipationApplicationService::targetExists`/`resolveResponsibleStaffId`
decide which target types are valid and who gets notified. No category
gets its own copy of this code; only the `ApplicationQuestion` rows,
`ResponsibleUnit`/`ResponsibleUser` resolution, and side-effects (see
below) vary per `targetType`.

**Lifecycle** (`ParticipationApplication::STATUS_*`):

```
detail_form_pending -> detail_form_submitted -> under_review
  -> revision_required -> (back to under_review once resubmitted)
  -> approved | rejected
```

A Preview submission (`POST /applications`) never means the student has
joined anything — it only produces `detail_form_pending` plus a real,
working emailed link (`ParticipationApplicationService::detailFormUrl`,
`/forms/application/{token}` — a Laravel **web** route, not `/api`,
unauthenticated by design since a student may open it from any device;
the 48-char random `detail_form_token` is the credential, never guessable).
Only `approve` creates real participation (club membership, etc.) —
never the Preview or Detail submission by themselves.

A student can have only one **open** application (any status except
`approved`/`rejected`) per target at a time; a terminal decision re-opens
that target for a fresh application (`ALREADY_APPLIED` / `ALREADY_APPROVED`
otherwise). `POST /applications`, `GET /me/applications`, `GET
/application-questions` need Auth only; `/admin/applications/*` and
`/admin/application-questions/*` need `perm:applications.manage`.

| Method | Path | Request | `data` |
| --- | --- | --- | --- |
| POST | `/api/v1/applications` | `{ targetType, targetId, formPayload (Preview answers, keyed by question id), responsibleStaffId? }` | created application, `status: "detail_form_pending"`; 404 `TARGET_NOT_FOUND`; 422 `PREVIEW_ANSWERS_INCOMPLETE`; 409 `ALREADY_MEMBER` / `ALREADY_APPLIED` / `ALREADY_APPROVED` |
| GET | `/api/v1/me/applications` | — | this user's own applications, newest first; includes `detailFormUrl` while the Detail form is still fillable |
| GET | `/api/v1/me/applications/{id}/history` | — | full status-change audit trail for one of the user's own applications; 404 if not theirs |
| POST | `/api/v1/me/applications/{id}/detail` | `{ formPayload }` | Stage 2 from inside the app — same outcome as the emailed token form (`under_review`); 404 if not theirs; 409 `INVALID_STATE`; 422 `DETAIL_ANSWERS_INCOMPLETE` |
| GET | `/api/v1/application-questions` | `?targetType=`, `?stage=preview\|detail` | active `ApplicationQuestion[]` for that category/stage, ordered — what the client renders as the Preview form (the Detail form is rendered server-side, see below) |
| GET | `/api/v1/admin/applications` | `?status=`, `?targetType=` | `detail_form_pending`/`detail_form_submitted`/`under_review`/`revision_required` by default (queue + waiting-on-detail), or filtered; newest first, limit 200 |
| POST | `/api/v1/admin/applications/{id}/approve` | `{ reviewNote? }` | `status: "approved"`; applies target side-effects (e.g. club membership) and notifies the student; 404 `APPLICATION_NOT_FOUND`; 409 `INVALID_STATE` unless `detail_form_submitted`/`under_review` |
| POST | `/api/v1/admin/applications/{id}/reject` | `{ reviewNote }` | `status: "rejected"`; 422 `REVIEW_NOTE_REQUIRED` if blank; 404/409 as above |
| POST | `/api/v1/admin/applications/{id}/revise` | `{ reviewNote }` | third outcome — asks the student to change something on the Detail form and resubmit (same `detail_form_token`, previous answers prefilled); `status: "revision_requested"`; 422 `REVIEW_NOTE_REQUIRED` if blank; 404/409 as above |
| GET | `/api/v1/admin/application-questions` | `?targetType=`, `?stage=` | full (incl. inactive) `ApplicationQuestion[]` for management |
| POST | `/api/v1/admin/application-questions` | `{ id?, targetType, stage, type, label, helpText?, options?, required?, sortOrder?, active? }` | upserted question |
| POST | `/api/v1/admin/application-questions/{id}/delete` | — | `{ deleted: true }` |

Application shape: `{ id, userId, studentName, studentEmail, studentDepartment, targetType, targetId, status, responsibleStaffId, responsibleStaffName, formPayload, previewPayload, detailPayload, detailFormSubmittedAt, reviewNote, submittedAt, reviewedAt }`. `formPayload`/`previewPayload` are the same field (kept dual-named for backward compatibility with existing clients) — the Preview stage's answers, keyed by `ApplicationQuestion.id`. `detailPayload` is the Detail stage's, same keying. `status` is one of `detail_form_pending` · `detail_form_submitted` · `under_review` · `revision_required` · `approved` · `rejected` · `cancelled`.

`ApplicationQuestion` shape: `{ id, targetType, stage, type, label, helpText, options, required, sortOrder, active }`. `type` is one of `text` · `textarea` · `single_choice` · `multiple_choice` · `dropdown` · `date` · `number` · `file` · `checkbox`. `options` is the choice list for `single_choice`/`multiple_choice`/`dropdown`.

**The Detail form itself** (`GET`/`POST /forms/application/{token}`, plain Laravel web routes, session+CSRF, not JSON) renders `ApplicationQuestion` rows for that application's `targetType`/`detail` stage as a real HTML form and, on submit, moves the application to `under_review` and notifies the responsible staff — this is the page every application email's "Detaylı formu aç" link opens.

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

Not an HTTP API route and **not** part of the route inventory.

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

Track in `docs/AUDIT_GERCEK_URUN.md`, not as live `/api/v1` paths.

(Club membership shipped in the P3 domain-cleanup milestone — see `club_members` above; nothing pending here as of that milestone.)

---

## Route inventory (canonical, 244)

Machine-readable. One `METHOD /api/v1/...` per line. `tests/Feature/ApiContractInventoryTest.php` compares this list to `php artisan route:list --path=api` (HEAD omitted).

```
DELETE /api/v1/content/{contentKey}/draft
GET /api/v1
GET /api/v1/academic-years
GET /api/v1/admin/achievements
GET /api/v1/admin/application-questions
GET /api/v1/admin/applications
GET /api/v1/admin/appointments
GET /api/v1/admin/audit-log
GET /api/v1/admin/career/applications
GET /api/v1/admin/career/applications/{id}/cv
GET /api/v1/admin/career/opportunities
GET /api/v1/admin/consultation-applications
GET /api/v1/admin/consultations
GET /api/v1/admin/email-logs
GET /api/v1/admin/events/pending
GET /api/v1/admin/events/{eventId}/participants
GET /api/v1/admin/moderation/posts
GET /api/v1/admin/moderation/queue
GET /api/v1/admin/onboarding-steps
GET /api/v1/admin/reports
GET /api/v1/admin/roles
GET /api/v1/admin/roles/{email}
GET /api/v1/admin/settings/moderation
GET /api/v1/admin/settings/site
GET /api/v1/admin/staff
GET /api/v1/admin/stats
GET /api/v1/admin/surveys
GET /api/v1/admin/system-health
GET /api/v1/admin/wordpress/versions
GET /api/v1/application-questions
GET /api/v1/appointments/{id}
GET /api/v1/ask/conversations
GET /api/v1/ask/conversations/{id}
GET /api/v1/auth/entra/config
GET /api/v1/career/opportunities
GET /api/v1/career/opportunities/{id}
GET /api/v1/chat/groups
GET /api/v1/chat/groups/{id}/messages
GET /api/v1/chat/prefs
GET /api/v1/chat/threads
GET /api/v1/chat/{peer}/messages
GET /api/v1/club-memberships
GET /api/v1/clubs
GET /api/v1/clubs/{id}
GET /api/v1/consultations
GET /api/v1/consultations/{id}
GET /api/v1/content/{contentKey}/draft
GET /api/v1/content/{contentKey}/revisions
GET /api/v1/directory
GET /api/v1/directory/buildings
GET /api/v1/directory/buildings/{building}/floors
GET /api/v1/directory/buildings/{building}/floors/{floor}/rooms
GET /api/v1/events
GET /api/v1/events/mine
GET /api/v1/events/{id}
GET /api/v1/feed
GET /api/v1/food-venues
GET /api/v1/health
GET /api/v1/leaderboard
GET /api/v1/me
GET /api/v1/me/achievements
GET /api/v1/me/activity
GET /api/v1/me/applications
GET /api/v1/me/applications/{id}/history
GET /api/v1/me/appointments
GET /api/v1/me/career-applications
GET /api/v1/me/career-profile
GET /api/v1/me/career-profile/cv
GET /api/v1/me/consultation-applications
GET /api/v1/me/onboarding
GET /api/v1/me/quests
GET /api/v1/me/settings
GET /api/v1/media
GET /api/v1/media/file/{filename}
GET /api/v1/media/mine
GET /api/v1/media/{id}/file
GET /api/v1/media/{id}/review-file
GET /api/v1/notifications
GET /api/v1/onboarding-steps
GET /api/v1/pages
GET /api/v1/pages/{slug}
GET /api/v1/places
GET /api/v1/places/{id}
GET /api/v1/places/{id}/availability
GET /api/v1/places/{id}/reviews
GET /api/v1/places/{id}/workshop
GET /api/v1/saved-posts
GET /api/v1/services
GET /api/v1/services/{id}
GET /api/v1/shuttle-routes
GET /api/v1/social/blocked
GET /api/v1/social/follow-requests
GET /api/v1/social/followers
GET /api/v1/social/following
GET /api/v1/social/friends
GET /api/v1/social/users/{id}
GET /api/v1/sports
GET /api/v1/staff
GET /api/v1/staff/{staffId}/slots
GET /api/v1/stories
GET /api/v1/stories/{id}/viewers
GET /api/v1/surveys/active
GET /api/v1/tour-proxy/{path}
GET /api/v1/trainer/applications
GET /api/v1/trainer/events
GET /api/v1/trainer/events/{eventId}/participants
GET /api/v1/trainer/roster
POST /api/v1/admin/academic-years
POST /api/v1/admin/academic-years/{id}/delete
POST /api/v1/admin/achievements
POST /api/v1/admin/achievements/{id}/delete
POST /api/v1/admin/application-questions
POST /api/v1/admin/application-questions/{id}/delete
POST /api/v1/admin/applications/{id}/approve
POST /api/v1/admin/applications/{id}/reject
POST /api/v1/admin/applications/{id}/revise
POST /api/v1/admin/appointments/{id}
POST /api/v1/admin/career/applications/{id}
POST /api/v1/admin/career/opportunities
POST /api/v1/admin/career/opportunities/{id}/delete
POST /api/v1/admin/clubs
POST /api/v1/admin/clubs/{id}/delete
POST /api/v1/admin/consultation-applications/{id}
POST /api/v1/admin/consultations
POST /api/v1/admin/consultations/{id}/delete
POST /api/v1/admin/directory
POST /api/v1/admin/directory/{id}/delete
POST /api/v1/admin/email-logs/{id}/retry
POST /api/v1/admin/email/bulk
POST /api/v1/admin/events
POST /api/v1/admin/events/draft-from-poster
POST /api/v1/admin/events/{eventId}/participants/{joinId}/approve
POST /api/v1/admin/events/{eventId}/participation-types
POST /api/v1/admin/events/{eventId}/participation-types/{typeId}/delete
POST /api/v1/admin/events/{id}/approve
POST /api/v1/admin/events/{id}/delete
POST /api/v1/admin/events/{id}/reject
POST /api/v1/admin/feed
POST /api/v1/admin/food-venues
POST /api/v1/admin/food-venues/{id}/delete
POST /api/v1/admin/food-venues/{venueId}/menus
POST /api/v1/admin/food-venues/{venueId}/menus/{date}/delete
POST /api/v1/admin/moderation/posts/{id}/approve
POST /api/v1/admin/moderation/posts/{id}/reject
POST /api/v1/admin/moderation/queue/{id}/resolve
POST /api/v1/admin/onboarding-steps
POST /api/v1/admin/onboarding-steps/{id}/delete
POST /api/v1/admin/pages
POST /api/v1/admin/pages/{id}/delete
POST /api/v1/admin/places
POST /api/v1/admin/places/{id}/delete
POST /api/v1/admin/places/{id}/workshop/equipment
POST /api/v1/admin/places/{id}/workshop/equipment/{itemId}/delete
POST /api/v1/admin/places/{id}/workshop/posts/{postId}/delete
POST /api/v1/admin/reports/{id}/resolve
POST /api/v1/admin/reviews/{id}/delete
POST /api/v1/admin/roles
POST /api/v1/admin/roles/{email}/delete
POST /api/v1/admin/services
POST /api/v1/admin/services/{id}/delete
POST /api/v1/admin/settings/moderation
POST /api/v1/admin/settings/site
POST /api/v1/admin/shuttle-routes
POST /api/v1/admin/shuttle-routes/{id}/delete
POST /api/v1/admin/sports
POST /api/v1/admin/sports/{id}/delete
POST /api/v1/admin/staff
POST /api/v1/admin/staff/{id}/delete
POST /api/v1/admin/staff/{staffId}/slots
POST /api/v1/admin/staff/{staffId}/slots/{slotId}/delete
POST /api/v1/admin/surveys
POST /api/v1/admin/surveys/{id}/delete
POST /api/v1/admin/wordpress/versions
POST /api/v1/ai/query
POST /api/v1/applications
POST /api/v1/appointments
POST /api/v1/appointments/{id}/cancel
POST /api/v1/ask/conversations/{id}/delete
POST /api/v1/auth/entra
POST /api/v1/auth/logout
POST /api/v1/auth/session
POST /api/v1/career/opportunities/{id}/apply
POST /api/v1/chat/groups
POST /api/v1/chat/groups/{id}/leave
POST /api/v1/chat/groups/{id}/messages
POST /api/v1/chat/groups/{id}/prefs/toggle
POST /api/v1/chat/groups/{id}/report
POST /api/v1/chat/prefs/toggle
POST /api/v1/chat/{peer}/messages
POST /api/v1/checkins
POST /api/v1/clubs/{id}/join
POST /api/v1/clubs/{id}/leave
POST /api/v1/consultations/{id}/apply
POST /api/v1/content/{contentKey}/draft
POST /api/v1/content/{contentKey}/revisions
POST /api/v1/events/mine
POST /api/v1/events/{id}/join
POST /api/v1/events/{id}/join/form
POST /api/v1/feed
POST /api/v1/feed/{id}
POST /api/v1/feed/{id}/comments
POST /api/v1/feed/{id}/delete
POST /api/v1/feed/{id}/like
POST /api/v1/feed/{id}/pin
POST /api/v1/feed/{id}/report
POST /api/v1/feed/{id}/unpin
POST /api/v1/me/applications/{id}/detail
POST /api/v1/me/career-profile
POST /api/v1/me/career-profile/cv
POST /api/v1/me/career-profile/cv/delete
POST /api/v1/me/onboarding/{stepId}
POST /api/v1/me/profile
POST /api/v1/me/settings
POST /api/v1/media
POST /api/v1/media/mine
POST /api/v1/media/mine/{id}/delete
POST /api/v1/media/{id}
POST /api/v1/media/{id}/delete
POST /api/v1/moderation/check-image
POST /api/v1/notifications/read-all
POST /api/v1/notifications/{id}/read
POST /api/v1/places/{id}/cover
POST /api/v1/places/{id}/report
POST /api/v1/places/{id}/reviews
POST /api/v1/places/{id}/workshop/posts
POST /api/v1/push-tokens
POST /api/v1/push-tokens/unregister
POST /api/v1/routing/directions
POST /api/v1/saved-posts/toggle
POST /api/v1/social/block
POST /api/v1/social/follow
POST /api/v1/social/follow-requests/accept
POST /api/v1/social/follow-requests/decline
POST /api/v1/social/report-user
POST /api/v1/stories
POST /api/v1/stories/{id}/delete
POST /api/v1/stories/{id}/view
POST /api/v1/surveys/{id}/vote
POST /api/v1/trainer/applications/{id}/approve
POST /api/v1/trainer/applications/{id}/reject
POST /api/v1/trainer/applications/{id}/revise
POST /api/v1/trainer/events
POST /api/v1/trainer/events/{eventId}/participants/{joinId}/approve
POST /api/v1/trainer/events/{id}/delete
```

Generated from `backend/routes/api.php` + `php artisan route:list --path=api`.
