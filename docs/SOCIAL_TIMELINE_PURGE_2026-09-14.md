# Social timeline purge — 14 September 2026

Run: `php artisan social:purge-timeline --force`
Backup: `backend/storage/app/backups/social-timeline-20260914-122604/` (git-ignored)
Audit trail: `admin_audit_log`, action `purge`, target `social_timeline`

Scope was confirmed before anything ran: **the posts on the Social page
timeline**, and only the rows that exist to describe them.

## Removed

| Table | Rows | How |
|---|---|---|
| `feed_posts` | 65 | deleted directly |
| `post_comments` | 4 | cascaded from the post |
| `post_likes` | 135 | cascaded from the post |
| `saved_posts` | 0 | cascaded from the post |
| `media_items` | 5 | rows for images attached to those posts |

5 media files were deleted from storage. Each was referenced by a deleted
post and by nothing that survives — a file also used by a place cover, a
story or an avatar is kept, or the purge would blank images out of screens
nobody asked to clear.

All 65 posts went, including those the `approved-content` global scope
hides — posts held for review and posts already rejected. Missing those
would have emptied the screen while leaving the rows behind.

## Kept, deliberately

| Table | Rows | Why |
|---|---|---|
| `moderation_events` | 229 | The record of what was decided about a student. |
| `moderation_reports` | 10 | Who reported what. |
| `moderation_cases` | 6 | Open and closed review cases. |
| `moderation_appeals` | 2 | Live appeals. |
| `stories` / `story_views` | 10 / 6 | Not the timeline. |
| `messages` / `conversations` | 4 / 2 | Chat is a separate surface. |
| `reviews` | 3 | Place reviews. |
| `collaboration_posts` | 3 | Workshop boards. |
| `media_items` | 28 remaining | Used elsewhere. |
| `users` | 50 | Nobody was deleted. |

The moderation tables are the important exclusion. They carry no foreign
key into `feed_posts`, so they survive the delete on their own — and they
have to. Without them the university cannot answer "why was my content
removed", which is exactly what both app stores require an appeals process
to be able to answer, and any live disciplinary matter loses its evidence.

## Integrity

Checked after the run, not assumed:

```
post_comments  orphaned rows: 0
post_likes     orphaned rows: 0
saved_posts    orphaned rows: 0
```

No rows point at a post that no longer exists, and no files referenced by
surviving rows were deleted.

## Recovery

The backup holds one JSON file per affected table plus the bytes of every
deleted media file, and a `manifest.json` naming what was taken and what
was excluded. It contains real student content and sits under
`storage/app/`, which is git-ignored — confirmed with `git check-ignore`.
It is on this machine only; if it matters beyond this session, copy it
somewhere durable.

`PurgeSocialTimelineTest` covers the command: the dry run writes nothing,
posts held for review are included, cascades fire, attached media goes,
media used elsewhere stays, moderation records survive, accounts survive,
the backup is written before any delete, and the audit entry is made.
