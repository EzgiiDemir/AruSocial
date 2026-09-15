# §9 — Pre-launch checklist

Last updated 15 September 2026.

Everything below is something **code cannot decide**. It needs a person to
supply a value, make a policy decision, or approve something. Items that
were only engineering work are not here — they are done, or they are in
the "known gaps" section at the end, which is honest about what is not.

Each item says who it needs, what specifically is missing, and what
happens if it ships without it.

---

## 1. Blocking — the app will be rejected or is unlawful without these

### 1.1 Fill in the legal documents

**Needs:** university legal / data protection officer.

`docs/legal/privacy.md` has **4** bracketed placeholders and
`docs/legal/community-guidelines.md` has **5**. They are mirrored into
`frontend/assets/legal/` and published at `/legal/privacy` and
`/legal/community-guidelines` in three languages.

| Placeholder | What it needs |
|---|---|
| `[Date]` | Effective date of the policy |
| `[University address]` | Registered postal address of the data controller |
| `[Legal institution name]` | The exact legal entity, not the brand name |
| `[appeal method or email]` | Where a student sends a moderation appeal |
| `[Link]` | Wherever the document points at another document |

A store reviewer opens these URLs during review. A policy containing the
word `[Date]` is a rejection, and an unfilled controller identity is a
GDPR/KVKK problem independent of any store.

**Also:** both documents are marked as drafts pending legal review, and
that review has not happened. Publishing a privacy policy nobody with
authority has read is the university's risk, not an engineering one.

### 1.2 Decide the age gate

**Needs:** university, then roughly a day of engineering.

There is no date-of-birth field or age affirmation anywhere in the app.
Apple's age-rating questionnaire and Google Play's Families policy both
require an answer, and neither infers "university students only" from the
description.

The question to answer is: **is enrolment sufficient proof of age?**

- If yes: the sign-up path is already restricted to university accounts,
  and this becomes a declaration on the store listing plus a line in the
  privacy policy. No app change.
- If no: an age gate has to be built, and under-18 users need a separate
  consent flow. That is a real feature, not a checkbox.

Nothing can be submitted until this is settled either way.

### 1.3 Prepare the data-safety declarations

**Needs:** whoever owns the store accounts, with engineering to verify.

Both stores require a declaration of what is collected and shared. Nobody
has written it. What the app actually touches, from the code:

**Collected:** name, university email, student ID, precise location
(check-in and campus map), photos, posts, comments and direct messages,
device push token, IP address, activity log.

**Third parties in the build:** Sentry (crash reports), Firebase Cloud
Messaging (push), Microsoft Entra (sign-in), Groq and OpenAI (moderation;
OpenAI currently off by default), MapLibre and OSRM (maps and routing),
WordPress (content sync).

Each needs a line saying what it receives and why. Declaring this wrongly
is worse than declaring it late — it is grounds for removal after launch.

---

## 2. Blocking for operation — the app runs, but unsafely

### 2.1 Install the scheduler

**Needs:** whoever administers the server. Roughly five minutes.

Four scheduled commands are defined and **none of them has ever run**,
because nothing calls `php artisan schedule:run`. Laravel's scheduler is
not a daemon: it decides what is due when it is called, so with nothing
calling it the schedule is inert. `schedule:list` still prints "Next Due",
which is what makes this easy to miss.

| Command | Interval | What does not happen without it |
|---|---|---|
| `campus:sync-360-directory` | hourly | 360 tour links go stale |
| `stories:purge-expired` | 15 min | Stories never expire — they are supposed to last 24 hours |
| `moderation:status` | hourly | **Nothing notices when the classifier is down** |
| `moderation:purge-excerpts --force` | 03:30 | Copies of refused content are kept indefinitely, contradicting the privacy policy |

The third is the serious one. With the classifier down, recall on unseen
text falls from 55% to 31%, and this check is the only thing that says so.

**Windows:** run from an elevated PowerShell prompt:

```powershell
cd backend
.\scripts\install-scheduler.ps1
Get-ScheduledTaskInfo -TaskName 'ARUVERSE-Scheduler'   # LastTaskResult 0 = fine
```

**Linux:** one crontab line, as in `docs/MODERATION_RUNBOOK.md`:

```
* * * * *  cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Not done automatically because it changes system configuration and needs
elevation.

### 2.2 Supervise the moderation classifier

**Needs:** server administrator.

The semantic classifier is a separate FastAPI process on `:8801` with no
supervisor. If it stops, the app keeps accepting posts and moderation
quality silently drops by nearly half. It needs whatever the host uses to
keep services up — systemd, NSSM, a container restart policy — plus 2.1
above so that the health check actually reports.

### 2.3 Confirm the production secrets are server-side only

**Needs:** server administrator.

`CAMPUS_DIRECTORY_API_KEY`, the database password and any OpenAI key must
exist only in `backend/.env`, which is git-ignored. They must never appear
in `.env.example`, in a Dart `--dart-define`, in an asset, or in an API
response. This is currently correct; it needs re-checking after any
deployment change, because it is the kind of thing that breaks quietly.

---

## 3. Not blocking, but decide before launch

### 3.1 Music

No commercial music integration exists and none should be added. If audio
is ever wanted, it may use **only** properly licensed, royalty-free,
Creative Commons, or university-managed tracks, and licensing metadata
must be stored for every track. Recorded here so the constraint survives
the conversation it was agreed in.

### 3.2 Image processing needs GD or Imagick

**Needs:** server administrator. This one is a genuine blocker on
finishing §4.

The server has **neither GD nor Imagick installed**, and no `exif`
extension. What that prevents:

- **EXIF orientation correction.** A photo taken sideways stays sideways.
- **Derivative generation.** No separate feed, story or thumbnail sizes —
  every viewer downloads the full-size original.
- **Format conversion.** No WebP/AVIF output, no controlled re-encoding,
  no transparent-PNG flattening.

What was built instead, because it needs no image library:

- Decompression-bomb and pixel-dimension limits, read from the file header
  with `getimagesize()` (part of PHP core, works without GD).
- Format detection from the file's own bytes, never its extension or the
  client's declared MIME type.
- Randomised, collision-resistant stored filenames.

Installing GD unblocks the rest. On Windows, uncomment `extension=gd` in
`php.ini` and restart PHP; then EXIF correction and derivatives can be
implemented as a follow-up.

### 3.3 Translation coverage for staff screens

**391** hardcoded Turkish strings remain in the Flutter app, mostly in the
admin and trainer screens. Not a store issue — a usability one for
Russian-speaking staff. There is a ratchet test (`hardcoded_strings_test`)
that stops the number rising, so this pays down as people work in those
files rather than needing one large change.

---

## 4. Known gaps, stated plainly

These are real and not fixed. They are listed so nobody discovers them
after launch and assumes they were hidden.

- **Moderation recall on unseen text is 55.2%** with the full pipeline,
  31% with rules alone. Precision is 100% with zero blocked false
  positives, which is the trade deliberately chosen: it under-blocks
  rather than wrongly blocking students. Ambiguous content is held for
  human review. See `docs/MODERATION_V4.md`.
- **The SEX category scores 0% on the fresh evaluation set.** It performs
  on the tuned set and does not generalise. This is the weakest category
  and should be treated as effectively unenforced by the text classifier,
  which makes image moderation and user reports the real defence there.
- **12 of 20 soft-deletable tables have no Filament resource**, so
  something soft-deleted in those tables can only be restored from the
  database directly.
- **Two uncommitted migrations** exist (`expand_admin_pages_for_page_builder`
  and `add_admin_identity_and_scoped_role_grants`) that are not in any
  commit. They create `role_grants` and add ten columns to `users`, and a
  Filament resource already references them. They need either committing
  or removing before a deployment is reproducible.
- **One test fails** (`role_gate_widget_test`, "superAdmin settings show
  both management tiles") because of uncommitted work-in-progress in
  `profile_screen.dart` that removed the admin and trainer tiles. Not a
  regression from any recent change; it belongs to whoever is mid-edit.
- **Video is not supported** and was removed on 14 September 2026. The
  carousel schema carries a `media_type` column so adding it later is a
  value change rather than a migration, but nothing decodes video today.

---

## 5. What is done

For contrast, so this list is not read as the whole state of the app:

account deletion from inside the app, consent gate with versioned
recording, content and user reporting, blocking, moderator case queue,
appeals, three-language legal documents, text and image moderation before
publication, retention purge for refused content, media library with
quarantine and restore, PostgreSQL parity in the test suite, multi-image
carousel posts, non-destructive post framing, per-item alt text, story
text overlays with pause and timed progress, and upload limits that
account for decompression bombs.
