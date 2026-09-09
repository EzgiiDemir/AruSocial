# Content moderation

Every piece of user-generated content in this app passes through one
server-side gate before it is stored. This document is the map of that
system: what it checks, where it is wired in, and what it deliberately does
not do.

## Why it is built this way

The first version was a keyword blocklist. It failed in both directions: it
missed anything not spelled exactly as listed (rephrasing, `s*ktir`,
`i d i o t`, mixed Cyrillic/Latin), and it punished people quoting a slur in
order to report it. Both failures come from the same cause — judging strings
instead of judging the act.

The current system judges the act. It weighs what was said, whether a person
is being addressed, and the context it was said in.

## Architecture

```
Mobile app → Backend → [ local engine + OpenAI omni-moderation ] → decide → DB
```

The app never talks to OpenAI. The API key exists only as `OPENAI_API_KEY`
in the server environment and is never returned by any endpoint.

| Piece | File | Job |
|---|---|---|
| Gateway | `app/Services/Moderation/ContentModerator.php` | The single entry point. Ban check → local engine → provider → decide → record → strike. |
| Provider | `OpenAiModerationClient.php` | Calls `omni-moderation-latest` with text and/or images. |
| Local engine | `TextPolicyEngine.php` | Offline TR/EN/RU rules: obfuscation, targeting, context exemptions, campus policy. |
| Ladder | `PenaltyLadder.php` | Strike number → warning or timed ban. |
| Outcome | `ModerationOutcome.php` | What the controller does, and what the user is told. |
| Video | `VideoModerator.php` | Samples frames across a clip so they can be moderated as images. |
| Controller hook | `Http/Controllers/Api/Concerns/ModeratesContent.php` | One-line guard: `if ($blocked = $this->moderationBlock(...)) return $blocked;` |

There is one gateway on purpose. A new content feature that forgets to call
it is the only way something can reach the timeline unchecked, and a missing
call is far easier to catch in review than a missing `if` inside one of
thirty handlers.

## Decisions

| Decision | Published? | Strike? | Meaning |
|---|---|---|---|
| `allowed` | yes | no | Clean. |
| `warned` | yes | no | Borderline; author is told, audit row written. |
| `review` | yes | no | Ambiguous (sarcasm, banter, unnamed target) — queued for a human. |
| `support` | yes | **never** | Author described harm to themselves — help offered, not a penalty. |

`warned`, `review` and `support` publish, so they travel on the *success*
response as `meta.moderation` — attached centrally in `ApiResponds::ok()`
rather than at each call site, because they were previously computed and
discarded: the self-harm support message never reached anyone.
| `rejected` | **no** | yes | Violation. Content is never written. |
| `banned` | **no** | no | Account is already serving a ban. |
| `unavailable` | **no** | no | Provider unreachable — held, not published. |

Client error codes: `CONTENT_BLOCKED` (400), `ACCOUNT_SUSPENDED` /
`ACCOUNT_BANNED` (403), `MODERATION_UNAVAILABLE` (503).

## Strike ladder

Configured in `config/services.php` under `moderation.penalties`, so the
rules change without touching code.

| Strike | Consequence |
|---|---|
| 1, 2, 3 | Warning |
| 4 | 24-hour ban |
| 5 | 3-day ban |
| 6 | 7-day ban |

Past 6 the longest configured ban repeats; escalating to a permanent ban
stays an explicit administrator decision. Ban windows use server time, so
changing a phone's clock does nothing. Expired bans clear themselves on the
next request — no cron job, no admin action.

## Failure behaviour

Two different situations, deliberately not collapsed into one:

- **No key configured** — the local engine runs alone. That is a deployment
  choice, not an outage, and the app keeps working.
- **Key configured but the provider is unreachable** — content is held and
  the caller gets `MODERATION_UNAVAILABLE` (503). No strike is recorded,
  because the user did nothing wrong. Obvious abuse is still refused by the
  local engine during the outage.

`MODERATION_FAIL_OPEN=true` reverses the second case. It is off by default
and should stay off.

## Images and video

Images are sent to the moderation model together with their caption — an
image is often only abusive in light of its text. Local uploads are inlined
as data URIs because our storage is not reachable from OpenAI's side; passing
an unreachable URL would mean the image was silently never inspected.

Video has no native support in the model, so frames are sampled at evenly
spaced points across the clip (`moderation.video_frames`, default 5) and
moderated as images. Sampling across the whole clip is the point: a clean
opening shot must not be able to smuggle prohibited content into the middle.

**Frame extraction requires ffmpeg.** Without it nothing about the video's
content has been inspected, so the upload is held as `pending` for human
review rather than approved. Set `FFMPEG_PATH` if it is not on `PATH`.

## Campus policy

The provider is trained on abuse between people and is good at it. It has
no opinion about someone selling exam answers, because that is not harmful
speech — it is a university rules violation. Those rules are ours:

| Rule | Outcome |
|---|---|
| `academic_dishonesty` — leaked papers, essays for hire, proxy exam-sitting | removed |
| `scam_fraud` — fake scholarships, deposit-first housing, "guaranteed" returns | removed |
| `drug_sale` | removed |
| `doxxing` — publishing someone else's contact details | removed |
| `pii_exposure` — T.C. kimlik, full phone number, IBAN | removed |
| `weapon`, `sextortion` | removed + escalated |
| `spam_solicitation` — follower-buying, link bait | warning |
| `self_harm` | **published, no strike, support contacts shown** |

`self_harm` is the one rule that deliberately produces no penalty. A student
saying they want to hurt themselves is not a rule-breaker, and treating them
as one teaches the people most at risk that speaking up costs them. It is
decided before every exemption (the generic "self_directed" rule that keeps
"I'm such an idiot" allowed used to swallow these silently) and before every
path that can record a strike, including the provider's own self-harm
categories. `self-harm/instructions` is excluded: telling *other* people how
to hurt themselves is harmful content and stays on the enforcement path.

PII patterns are kept narrow on purpose. A campus feed is full of harmless
numbers — room 204, extension 1006, prices, dates — so only identity-bearing
shapes match.

## Covered surfaces

`feed.store`, `feed.update`, `feed.comment`, `feed.storeOfficial`,
`feed.report`, `story.store`, `chat.send`, `chat.sendGroupMessage`,
`chat.createGroup`, `chat.reportGroup`, `place.review`,
`place.addWorkshopPost`, `place.report`, `profile.updateBio`,
`career.updateProfile`, `event.createOwnActivity`, `appointment.book`,
`consultation.apply`, `application.store`, `application.detail`,
`social.reportUser`, `admin.event.upsert`, `media.upload` (images, video,
and web-camera captures — a camera shot is bytes on the same upload path,
so there is no separate route to forget).

Staff content goes through the same gate. "Authorised" describes who may
publish, not that what they publish needs no checking — an announcement
reaches every student at once, which makes a compromised staff account the
highest-reach path in the app. The engine was measured against real notices
first (suicide prevention, weapons policy, drug awareness, phishing
warnings); `PolicyCoverageTest` pins that those still publish.

`ModerationSurfaceCoverageTest` drives each surface over HTTP with the same
abusive string. Reading the controllers is not enough to know a surface is
wired: four of them imported the trait and never called it, which looks
correct at a glance and enforces nothing.

## Admin tools

All behind `permission:moderation.moderate`:

- `GET /admin/moderation/events` — decision trail, filterable
- `GET /admin/moderation/users` — offenders, standing, next penalty
- `GET /admin/moderation/policy` — active ladder and thresholds
- `POST /admin/moderation/events/{id}/remove-strike` — reverse a wrong call (also lifts the ban it caused)
- `POST /admin/moderation/users/{userId}/ban` — manual ban / lift

## Privacy

`moderation_events` stores a 280-character excerpt with an
`excerpt_purge_after` date (`moderation.retain_excerpt_days`, default 30) —
long enough to handle an appeal, not an indefinite archive of the worst
thing every student ever typed.

## Tests

- `tests/Unit/ModerationCorpusTest.php` — 150 labelled TR/EN/RU cases
- `tests/Unit/CampusPolicyRulesTest.php` — campus rules, PII, crisis handling,
  and the ordinary-post cases that must stay untouched
- `tests/Feature/OpenAiModerationTest.php` — provider path, faked HTTP
- `tests/Feature/ModerationApiTest.php` — endpoint behaviour, strike ladder
- `tests/Feature/AdminModerationToolsTest.php` — admin tools and permissions

The suite never calls the real API: `phpunit.xml` forces `OPENAI_API_KEY`
empty, and provider tests fake HTTP explicitly.
