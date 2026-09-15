# Translation audit — 14 September 2026

Turkish, English, Russian, across the mobile app and both management
panels. Measured, not estimated; every number below has a test behind it.

## Where the app's words come from

Three separate systems, and they are worth keeping straight:

| Surface | Source | Managed from |
|---|---|---|
| Mobile app | `app_strings.dart` bundle + published overrides | Admin panel → Translations |
| Filament Admin/Trainer panels | `backend/lang/{tr,en,ru}/panel.php` + Filament's own | The repository |
| Legal documents | `docs/legal/privacy*.md` and `community-guidelines*.md`, mirrored to app assets | The repository |

## Result

### Mobile app strings — complete

606 keys, each present in all three languages, published to the catalogue
the phone downloads.

- No key is missing a language.
- No published value is blank.
- No published value is just its own key — the `sp_private`-on-screen
  failure cannot happen for a key that exists.
- No string longer than 12 characters is identical across all three
  languages, which is the usual fingerprint of a copy-paste that was never
  translated.

Held by `TranslationCoverageTest` and `app_strings_completeness_test.dart`.

**One gap found and closed.** The ten strings added for the login consent
gate existed in the app bundle but had never been imported, so the panel
did not know they existed and nobody could have corrected their wording
without an App Store release. `TranslationCoverageTest::test_every_app_string_is_manageable_from_the_panel`
now fails the build if that happens again — which it silently would have,
because the app renders its own bundle perfectly well.

### Filament panels — were English-only, now translated

Both panels rendered entirely in English: every label, every confirmation,
and all of Filament's own chrome. The staff running them are the same
Turkish- and Russian-speaking people the app is translated for, and a
delete confirmation nobody can read is where a language gap does real
damage.

- `backend/lang/{tr,en,ru}/panel.php` — this project's own labels, 125
  call sites converted.
- `SetPanelLocale` middleware sets the locale from `users.preferred_language`
  — the same column the app's language picker writes, so switching language
  on a phone moves the panel too.
- Filament ships `tr` and `ru`, so its buttons, filters, pagination and
  validation messages came along for free.

Held by `PanelLocalisationTest`, which renders the real create page and
asserts real Turkish and real Russian appear and English does not.

### Legal documents — complete

The Privacy Policy and the Community Guidelines — the two documents the
consent checkbox names — exist in all three languages, are served with the
right `lang` attribute, and neither falls back to the Turkish original.
Both are published pages (`/legal/privacy`, `/legal/community-guidelines`)
so they are readable without installing the app.

## Still outstanding: 395 hardcoded strings in the Flutter app

This is the honest remaining gap. A `Text('Kaydet')` compiled into the
widget tree cannot be translated, cannot be corrected without a release,
and is invisible to the panel's missing-translation warning — it looks
complete while being permanently Turkish.

| | Count |
|---|---|
| Total | 395 |
| Clearly Turkish | 215 |
| Ambiguous or English | 180 |
| Clearly Russian | 0 |

They are not spread evenly. The worst nine files are almost all the
**in-app** Admin and Trainer screens (distinct from the Filament panels
above):

```
  29  features/admin/sections/applications_staff_tab.dart
  26  features/admin/sections/career_tab.dart
  20  features/admin/sections/places_tab.dart
  16  features/admin/content_blocks/content_block_editor.dart
  16  features/trainer/trainer_panel_screen.dart
  16  features/admin/sections/moderation_cases_section.dart
  15  features/social/social_screen.dart
  15  features/admin/sections/pending_activities_tab.dart
  12  features/admin/sections/onboarding_tab.dart
```

So the concrete effect today: a Russian-speaking administrator using the
in-app admin screens meets roughly 215 Turkish strings.

`hardcoded_strings_test.dart` is a **ratchet**: the count may fall, never
rise, so new screens are translated from the start. Clearing the existing
395 is a large mechanical change and has not been done.

## How to check this yourself

```bash
cd backend && php artisan test --filter="TranslationCoverageTest|PanelLocalisationTest"
cd frontend && flutter test test/app_strings_completeness_test.dart test/hardcoded_strings_test.dart
```

After adding any string to `app_strings.dart`:

```bash
cd backend && php artisan translations:import --publish
```
