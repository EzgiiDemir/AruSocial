# Integrations

What ARUVERSE is wired to, how the admin panel reports it, and what it
deliberately does not do.

## Where it lives

| Piece | File |
|---|---|
| The list itself | `app/Services/Integrations/IntegrationRegistry.php` |
| One integration's descriptor | `app/Services/Integrations/IntegrationDefinition.php` |
| Test outcome | `app/Services/Integrations/IntegrationTestResult.php` |
| Operational state (table) | `app/Models/IntegrationState.php` |
| JSON API | `app/Http/Controllers/Api/Admin/IntegrationsController.php` |
| Staff UI | `app/Filament/Pages/Integrations.php` + `resources/views/filament/pages/integrations.blade.php` |

`IntegrationRegistry` is the single source of truth. The API and the Filament
page both read it, so they cannot disagree about whether something is
connected. Adding a provider is one entry in `definitions()`.

## Status vocabulary

Four values, in precedence order:

| Status | Meaning |
|---|---|
| `disabled` | An operator switched it off. Outranks everything else, so a stale error never masks a deliberate decision. |
| `not_configured` | No credentials yet. The normal state before setup — not an error, never shown in red. |
| `error` | The last connection test failed. |
| `connected` | Credentials present and nothing has failed since. |

`connected` does **not** mean "tested just now" — a provider configured but
never tested is `connected` because nothing has gone wrong. That is why the
card always shows *Son bağlantı testi* next to the chip. There is no fifth
"unknown" status by design; the timestamp carries that nuance instead.

## Credentials

**No credential is stored, accepted or returned by this feature.**

Configuration continues to live where it already did:

- **env** (`config/services.php`) for most providers — changing one is a
  deploy, which is the correct amount of friction for a production secret;
- **`app_settings`** for Entra and WordPress, which staff may edit through the
  existing site-settings endpoint. That endpoint was already write-only: it
  returns `apiTokenConfigured: true|false`, never the token.

The panel shows a **masked tail** only (`••••••••1793`), produced by
`IntegrationDefinition::mask()`. Secrets of 8 characters or fewer are masked
whole rather than revealing a meaningful fraction. `envKeys` lists variable
*names* so an operator knows what to set; values are never read into the
payload.

Two tests pin this and will fail if it ever regresses:

- `test_configured_secret_is_never_returned_only_a_mask`
- `test_rendered_page_never_contains_a_raw_credential`

## Connection tests

`POST /api/v1/admin/integrations/{key}/test`, or the button on the page.

Rules the testers follow:

1. **Unconfigured → skipped, not failed.** An operator mid-setup is not shown
   a red error for work they have not finished. `last_test_ok` stays `null`.
2. **No fake tests.** A provider that cannot be probed without doing real work
   has `tester: null` and says so ("bu sağlayıcı için canlı bağlantı testi
   yapılmıyor"). FCM would need a signed JWT minted from the service-account
   key; SMTP would need to actually email someone. Neither happens on a button
   press.
3. **The provider's own error text never reaches the operator.** Providers
   routinely echo the request back, credential included. The operator sees a
   fixed, actionable sentence; the detail goes to the server log, which is
   additionally scrubbed of long opaque tokens by `IntegrationRegistry::redact()`.
4. **Never throws.** A provider being down must not 500 the admin panel.
5. **Rate limited** — `throttle:integration-tests`, 10/min per account. Each
   press is a real outbound request with a real key; an operator leaning on
   the button could get the key throttled at the provider's end.

## Authorization

Uses the permission vocabulary that already existed (`system.integration` is
in `GranularPermissions::RESOURCES`, granted to the `it` role) — no new
permission was invented.

| Action | Permission |
|---|---|
| View the list / open the page | `system.integration.read` |
| Test, enable, disable | `system.integration.manage_settings` |

**Grant one via `role_grants`, not the legacy role string.** The canonical
`system.integration.*` keys resolve through `RoleGrant`;
`GranularPermissions::allows()` consults its legacy `KEYS` map only after the
grant check, and these keys are not in it. An account whose role is `it` only
as a `role_assignments` row is therefore denied — it needs an active
`role_grants` row with role `it` (or `superAdmin`, which short-circuits).
`test_an_it_grant_can_open_and_operate_the_page` asserts both directions.

Enforced on the **backend**: route middleware for the API, `canAccess()` and
`canManage()` on the Filament page. The page's buttons are hidden without the
write grant *and* the server rejects the call anyway, so hiding the UI is
convenience, not the control.

## Audit

Every test and every enable/disable writes to `admin_audit_log` via
`AuditLogger`, recording who, what and when. Labels name the integration, not
its credential — asserted by
`test_configuration_and_status_changes_are_audit_logged`.

## The table

`integration_states`, one row per key, created on demand. It holds the
enable/disable flag and four timestamps. A missing row means "never tested,
never disabled", which is why nothing is seeded — and why `enabled` is
nullable rather than defaulting to `true`: "nobody has touched this" must stay
distinguishable from "someone deliberately enabled it".

Dropping the table loses that history and nothing else.

## Current inventory

Status below is *as configured in this repository* — production will differ
according to what is set in the server's `.env`.

| Key | Provider | Configured by | Live test |
|---|---|---|---|
| `campus_directory` | ARUCAD 360 directory | env | Yes — reads the directory endpoint |
| `groq` | Ask ARUVERSE assistant | env | Yes — lists models |
| `entra` | Microsoft sign-in | admin panel | Yes — OIDC discovery (no secret sent) |
| `wordpress` | Corporate site bridge | admin panel | Yes — WP REST `/types` |
| `routing` | OSRM walking directions | env | Yes — computes a short campus route |
| `openai_moderation` | External moderation (off by default) | env | Yes — moderation endpoint |
| `local_moderation` | Self-hosted classifier | env | Yes — binary present and executable |
| `fcm` | Push notifications | env | No — would require minting a real JWT |
| `mail` | SMTP | env | No — would email a real person |
| `sentry` | Error reporting | env | No — write-only DSN |

## Adding one

Add an entry to `IntegrationRegistry::definitions()`. The API, the page, the
audit trail and the status logic pick it up with no further changes.
`AdminIntegrationsApiTest` walks the registry, so a new integration is covered
by the existing authorization and leakage tests the day it lands.

Do not add an entry for something that is not implemented. A status chip for a
provider no code talks to means nothing.
