# §7 — Audit and store compliance, 15 September 2026

Against Apple App Store Review Guidelines and Google Play policy, for an
app that carries user-generated content.

## The blocker that was there

**There was no way to delete your own account.** Apple guideline 5.1.1(v)
and Google Play's data-deletion policy both require an app that lets you
create an account to let you delete it *from inside the app*, and neither
accepts "email us and we will do it". This is a rejection on submission.

Fixed. `POST /api/v1/me/delete` with `GET /api/v1/me/deletion-preview`
alongside it, so the confirmation screen can say what will happen rather
than making the student guess.

What deletion removes is decided by the schema, not by code: 39 foreign
keys cascade from `users`, taking posts, stories, comments, likes, club
memberships, onboarding progress, saved posts, push tokens and consent
records with the row. This is also why accounts are deliberately **not**
soft-deleted — a soft delete issues no DELETE, so none of those cascades
would fire and a "deleted" student's content would stay on the feed.

What survives is deliberate and disclosed: moderation events, reports and
appeals reference the user by a plain column with no foreign key. Without
them the university could not answer an appeal or a disciplinary question
about content it had already removed, and the privacy policy says so.

The password is required again even though the caller holds a valid token —
a token can be a phone left unlocked on a table, and this is the one action
in the app with no undo. `AccountDeletionTest` covers all of it, including
that one account's deletion does not touch another's.

## Two smaller findings, both fixed

**Refused content was being kept indefinitely.** Every moderation decision
stamps `excerpt_purge_after` on the row and stores a short copy of what was
refused, so an appeal has something to look at. Nothing ever deleted them.
57 excerpts are currently held; the column had been written since the
feature shipped and never acted on. `moderation:purge-excerpts` now clears
them and runs daily at 03:30. Only the excerpt goes — the decision, the
category and the scores stay, because they are the audit trail and contain
no content.

**The app still asked for access to your videos.** `READ_MEDIA_VIDEO` was
left in the Android manifest after video was removed from the product on 14
September, so the app requested a permission it had nothing to do with.
Removed.

## Checked and already in place

| Requirement | State |
|---|---|
| Content filtering | Text and image moderation before publication; see `MODERATION_V4.md` |
| Reporting content | `POST /feed/{id}/report`, `/stories/{id}/report`, `/feed/comments/{id}/report` |
| Reporting users | `POST /social/report-user` |
| Blocking users | `POST /social/block`, with `GET /social/blocked` |
| Moderator review | Case queue, `/admin/moderation/cases/{id}/decide` |
| Appeals | `POST /moderation/appeals`, student-visible at `/moderation/appeals/mine` |
| Privacy policy | Published at `/legal/privacy`, three languages, reachable without installing |
| Community guidelines | Published at `/legal/community-guidelines`, three languages |
| Consent | Blocking gate before first use, recorded per account with version and language |
| Support contact | `/legal/safety`, plus contacts in both policy documents |
| Account deletion | **New** — see above |

## Still open, and honest about it

### Blocking for submission

- **The bracketed fields in both legal documents are unfilled.** Privacy
  policy and community guidelines carry `[Date]`, `[University address]`,
  `[Legal institution name]`, `[appeal method or email]`, `[Link]`. A store
  reviewer opens these URLs. Both documents are also marked as drafts
  pending legal review, and that review has not happened.
- **No age gate.** The app is for university students, so under-13 users
  are not expected — but neither store infers that from the description.
  Google Play's Families policy and Apple's age rating questionnaire both
  need an answer, and there is currently no date-of-birth or
  affirmation anywhere in the flow. What is needed is a decision from the
  university, not code: is enrolment sufficient proof of age?
- **Data safety / privacy labels not prepared.** Both stores require a
  declaration of what is collected and shared. The app touches: name,
  university email, student ID, precise location (check-in and map), photos,
  posts and messages, device push token, IP and activity logs. Third
  parties in the build: Sentry, Firebase Cloud Messaging, Microsoft Entra,
  Groq, OpenAI (moderation, currently off by default), MapLibre, OSRM,
  WordPress. Each needs a line in the declaration; nobody has written it.

### Not blocking, but real

- **Nothing invokes `schedule:run`.** Four scheduled commands are defined —
  the 360 directory sync, expired-story purge, moderation health check and
  now the excerpt purge — and none of them runs, because no cron entry or
  Windows scheduled task calls `php artisan schedule:run` every minute. The
  code is correct and inert.
- **The semantic moderation classifier is a separate process with no
  supervisor.** With it down, recall on unseen text drops from 55% to 31%
  and nothing says so out loud except the hourly `moderation:status` check,
  which is itself one of the scheduled commands that does not run.
- **395 hardcoded Turkish strings** in the Flutter app's admin and trainer
  screens. Not a store issue; a usability one for Russian-speaking staff.
- **Tests run on SQLite by default** while production is PostgreSQL. A
  PostgreSQL configuration exists and CI uses it; a local full run against
  PostgreSQL is what proves dialect-sensitive code, and the `phpunit.pgsql.xml`
  config was missing the 512M memory limit its SQLite counterpart had — so
  the PostgreSQL CI job would have died mid-suite with an allocation failure
  that reads like a crash. Fixed.

## Reproducing

```bash
cd backend
php artisan test                                    # SQLite, fast
php artisan test --configuration=phpunit.pgsql.xml  # PostgreSQL, what production runs
php artisan schedule:list                           # what should be running
php artisan moderation:purge-excerpts               # dry run
```
