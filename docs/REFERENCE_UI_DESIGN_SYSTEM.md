# ARUCAD reference UI system

This is an implementation specification derived from the supplied mobile
references, not a generic redesign. The target is a compact, friendly campus
utility product: strong black headings on a warm off-white ground, a small
number of high-saturation ARUCAD accents, rounded white cards, and clear
one-tap actions.

## Screen-by-screen reference audit

### Home

1. **Brand row:** white ground, ARUCAD wordmark left; yellow outlined activity
   pill and an outlined bell right. It is 16–20 px from the screen edges and
   deliberately quieter than the red onboarding banner below it.
2. **Onboarding banner:** red, 24 px radius, white flag and two-line copy;
   confetti is decorative only and must not carry information. The arrow is a
   clear full-card affordance.
3. **Nearby card:** white elevated 20–24 px card, coloured square location
   badge at left, two-line place metadata in the middle, red pill CTA right.
4. **Campus Pulse:** horizontal 3-up rail of outlined, lightly tinted cards.
   Each card has a density dot, status label, bold place name and large
   line-art place illustration at the bottom; it never uses photographs.
5. **Today:** an outline stadium "create" action followed by dense 72–84 px
   event rows. Time is a coloured rounded square, content is vertically
   centred, and membership uses a colour-matched pill button.
6. **Navigation:** 5 equal destinations, outline icon above label. The active
   destination is red with a pale-red capsule behind the icon, not a full tab
   fill.

### Profile and settings

Profile uses a personal hero/XP card, compact 3-stat blue panel, then simple
single-line navigation rows. Gallery is a dashed empty-state frame. The
visibility page deliberately avoids heavy cards: radio rows, thin dividers,
red switches and a small language selector create a quiet form hierarchy.

### Suggestions, social, messages and Ask ARUCAD

Suggestions are illustration-led rows with a short location/XP subtitle;
leaderboard ranks are compact coloured pills. Social uses a search pill,
three low-height filter chips, avatar-first posts and lightweight action
icons. Messages use no card around every row—avatar, name, last message,
time and chevron are enough. Ask ARUCAD pairs a white intro area with a red
prompt pill, warm-grey assistant response bubble, pale chips and a white
composer with one circular red send button.

### Career and lists

Career begins with a compact back/header region, a pale-red profile-completion
notice, category chips, plain chevron opportunity rows and a 2×2 rail of
pastel action cards. Lists across the product preserve the same 16 px content
gutter, 10–12 px row gaps, 16–20 px card radius and 44 px minimum controls.

## Tokens

| Token | Value | Reference use |
|---|---:|---|
| Brand red | `#EA0029` | primary CTAs, active nav, banner |
| Blue | `#000F9F` | stats, secondary emphasis |
| Yellow | `#FDE021` | XP/activity emphasis |
| Campus green | `#39B65A` | calm/available density |
| Lilac | `#C59FE2` | quiet event/density state |
| Orange | `#FF9517` | warm event/density state |
| Ink | `#1C1E22` | titles and outline icons |
| Muted | `#6B7280` | metadata |
| Canvas | `#FAFAFC` | page ground |
| Border | `#E9EAF0` | soft card and input boundary |

Spacing uses a 4 px base: 4, 8, 12, 16, 20, 24, 32. Cards use 18–20 px
radius; banner/large panels use 24 px; pills use 999 px. Headings are
Montserrat ExtraBold 24/20/18, body is Montserrat Regular 14–15, metadata is
12–13, and navigation labels are 10–11. Shadows are low-opacity vertical
shadows, never dark Material elevation.

## Icon family

The supplied place icons use a single green outline, rounded joins/caps,
approximately 2.5–3 px stroke at 100–120 px, generous white space and no
filled interior. Product icons follow that rule at 24 px: 1.8–2 px rounded
stroke, minimal geometry, no gradients and colour applied by context. New
SVG source assets live under `frontend/assets/icons/`; Flutter uses the same
geometry through the local `ArucadLineIcon` painter so no external icon API is
needed.

## Application rule

All student surfaces use the tokens and shared reference components. Admin and
trainer remain information-dense desktop portals but share palette, typography,
buttons, radius and icon geometry; their data/permissions are not altered by a
visual change.
