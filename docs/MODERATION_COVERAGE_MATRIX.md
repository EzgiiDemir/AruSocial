# Moderation coverage matrix

| Route | Surface / writable fields | Gate | Publication firebreak |
|---|---|---|---|
| `POST /v1/feed`, `POST /v1/feed/{id}` | post text, caption/media | `ContentModerator` before persist/update | `FeedPost` approved global scope |
| `POST /v1/admin/feed` | official post text, caption/media | `ContentModerator` before persist | `FeedPost` approved global scope |
| `POST /v1/feed/{id}/comments` | comment text | `ContentModerator` before persist | `PostComment` approved global scope |
| `POST /v1/places/{id}/reviews` | review comment | `ContentModerator` before persist | `Review` approved global scope |
| `POST /v1/places/{id}/workshop/posts` | collaboration text | `ContentModerator` before persist | `CollaborationPost` approved global scope |
| `POST /v1/stories` | story text/caption/media | `ContentModerator` before persist | `Story` approved global scope |
| `POST /v1/chat/{peer}/messages` | direct-message text | `ContentModerator` before persist/broadcast | `ChatMessage` approved global scope |
| `POST /v1/chat/groups/{id}/messages` | group-message text | `ContentModerator` before persist | `ChatGroupMessage` approved global scope |
| `POST /v1/chat/groups` | group name | `ContentModerator` before persist | direct response only |
| `POST /v1/media/mine`, `POST /v1/media` | image/video bytes | magic bytes, decoder, frame/provider gate | approved-only file endpoint; private disk |
| `POST /v1/me/profile` | profile text fields and avatar | `ContentModerator` before update | approved local media only |
| feed/user/group/place report routes | report descriptions | `ContentModerator` before persist | authenticated/authorised report views only |
| place review, workshop/collaboration, student activity, career/consultation/participation routes | user-entered text | controller-level `ModeratesContent` gates | public models use approved scope; confidential fields remain authorised |
| trainer event create/update | title and description | `ContentModerator` before immediate publication | existing trainer ownership and broadcast flow |
| admin CMS/catalog writes | pages, clubs, services, places, workshop equipment, surveys/forms, career/consultation, staff/directory, food, sport, shuttle and onboarding text | one contextual `ContentModerator` decision before upsert | existing RBAC and publication rules |

The current API has no comment-edit, group-description, story-edit, or separate
official-post-edit route. The normal post update gate also applies when the owned
post is official.

## Provider boundary

When `MODERATION_SERVICE_ENABLED=true`, `ModerationClient` sends text, extracted
HTTPS URLs and private upload files to the self-hosted `/v1/moderate` gateway.
The gateway is required: transport errors, invalid responses and
`decision=error` become `503 MODERATION_UNAVAILABLE` without a strike. Optional
OCR, Whisper, OpenNSFW2 and URLhaus failures are represented by
`degraded=true`; an `allow` decision still follows the existing persistence
path. When disabled, the existing local engine and optional legacy provider
remain available for migration compatibility.

## Decision contract

- `ALLOW`: persist as `approved` and publish immediately.
- `BLOCK`: do not persist/broadcast; charge one violation on the shared points
  ladder (`config/moderation.php` -> `enforcement`), where the detected
  category decides the severity and the severity decides the points.
- `ERROR`: return `503 MODERATION_UNAVAILABLE`; do not persist, charge, or lock.

Local `WARN`/`REVIEW` labels are not blocks. A clean semantic result resolves a
weak local match to `ALLOW`; only an explicit local removal or configured
high-confidence semantic category blocks. Provider categories not mapped in
`services.moderation.thresholds` cannot enforce.

## Policy categories

The policy engine covers profanity/abuse, targeted harassment, credible threats,
hate, nudity/sexual content, sexual harassment/sextortion, child exploitation,
minor safety, violence/gore, self-harm, terrorism/extremism, weapon/crime
instructions, drug sales, fraud, phishing/credential theft, doxxing/validated
PII, impersonation, spam/engagement sales, harmful misinformation, piracy,
animal cruelty, political propaganda, and academic cheating. Ordinary discussion,
news, education, URLs, political references, copyrighted-work mentions, animals,
and help-seeking self-harm language are not treated as violations.

## Residual architecture note

The repository has no live-camera, HLS, WebRTC, search-index,
Algolia/Elasticsearch, or feed-cache implementation. If one is added, it must
consume only the `approved` query scope. Structurally valid media publishes when
no semantic provider is configured; a configured provider/runner failure returns
`MODERATION_UNAVAILABLE`. Explicitly pending media stays private and is available
only through the authenticated moderator flow.
