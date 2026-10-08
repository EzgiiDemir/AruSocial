# Staging infrastructure for ARUVERSE / AICAD (Phase 4E)

What has to exist before the AICAD soak can happen, and how to provision it with the repository's own deployment mechanism. **No staging environment exists today** (2026-10-07). Nothing in this document has been executed. Production is never touched.

## 1. Why a separate host is needed
- The only real hostnames are production: `app-aruverse`, `api-aruverse` and `admin-aruverse.arucad.edu.tr` (`docs/DEPLOYMENT_ARUVERSE.md`).
- Internal DNS resolves **every** `*.arucad.edu.tr` name, including invented ones like `staging-api.arucad.edu.tr`, to the production server (192.168.100.20). A staging name can't simply be assumed: it would land on production.
- `EnvironmentGuard` refuses to boot `APP_ENV=staging` without a public `APP_URL`, a non-localhost `REVERB_HOST` and non-empty, non-localhost `CORS_ALLOWED_ORIGINS`. Staging needs a real hostname with TLS.

## 2. Infrastructure request (for IT; nothing here is done by the app team)

| Item | Requirement |
|---|---|
| App host | A dedicated VM or container host, **not** the production server. Ubuntu 22.04/24.04, PHP 8.3-FPM, nginx, Composer, Node only if the web build runs there. Same layout as production (`/var/www/aruverse-api`) |
| DNS | A dedicated record, e.g. `api-aruverse-staging.arucad.edu.tr` (and `admin-…-staging` if the panel is tested there), pointing at the **staging** host. It must be an explicit record that takes precedence over the internal wildcard; confirm with `nslookup` that it does not resolve to 192.168.100.20 |
| TLS | A certificate for the staging name(s) (certbot as in `DEPLOYMENT_ARUVERSE.md` §4) |
| Database | A dedicated PostgreSQL 16 database and user for staging, on the staging host or a separate instance. **Never** the production database or a production replica with write access |
| Model | GPU host per `deploy/ai/README.md` §1 (L4 24 GB class; 16 GB minimum at FP8 with an 8K context), or the developer Ollama build `aicad-qwen3:8b` for a smaller soak. The production GPU host may be shared **read-only** (inference has no mutable state) if IT confirms capacity, with its own API key |
| Embedder | `image-moderation-service` on :8801 (`/v1/embed`), as systemd unit `aruverse-classifier`. Check the endpoint, not the port (`deploy/ai/README.md` §5b) |
| Routing (optional) | The OSRM foot graph (`deploy/docker-compose.yml`, profile `routing`) for route questions |
| Isolation | Staging must not reach production mutable services: no production FCM project (leave `FIREBASE_*` unset → no pushes to real phones), `MAIL_MAILER=log` (no real inboxes), a staging Sentry DSN or none, and no production Entra app secrets unless IT provides a staging app registration |

## 3. Provisioning checklist (the repository's own commands)
Run on the staging host as `www-data` in `/var/www/aruverse-api` (`DEPLOYMENT_ARUVERSE.md` §5):

```bash
git fetch --all
git checkout moderation-phase-0-1          # or the release tag cut from it
git rev-parse HEAD | tee storage/app/DEPLOYED_REV
composer install --no-dev --optimize-autoloader
cp .env.example .env                       # then fill §4 — never copy production .env
php artisan key:generate                   # staging gets its own APP_KEY
php artisan config:cache && php artisan route:cache && php artisan view:cache   # EnvironmentGuard checks staging here
php artisan migrate --force                # fresh staging DB: all migrations; never migrate:fresh on a shared DB
# Real-data seeders ONLY. Never plain `db:seed`: DatabaseSeeder also creates a stale active
# 2025-2026 academic year, demo events/surveys, CrowdCampusSeeder's fake student accounts
# (no environment guard) and DemoCampusLifeSeeder's demo content — all of which would be
# mistaken for campus data during the soak.
php artisan db:seed --class=CampusCatalogSeeder --force       # places, services, clubs, sports, staff roster
php artisan db:seed --class=CrawlSourceSeeder --force         # official crawl sources
php artisan db:seed --class=ApplicationQuestionSeeder --force # real apply-flow question sets
php artisan db:seed --class=AiProgrammeAliasSeeder --force
php artisan db:seed --class=AiCampusAliasSeeder --force
php artisan db:seed --class=AiEvaluationCaseSeeder --force
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo systemctl reload php8.3-fpm
sudo systemctl restart aruverse-queue aruverse-classifier   # aruverse-reverb if used
php artisan knowledge:crawl                # first corpus (the scheduler then runs it 04:00 / 16:00)
php artisan knowledge:extract-facts
php artisan knowledge:embed --all
```

- **Scheduler.** `routes/console.php` schedules the crawl, embeddings, directory sync and moderation jobs. The repository doesn't document the cron entry. It is Laravel's standard `* * * * * cd /var/www/aruverse-api && php artisan schedule:run >> /dev/null 2>&1` as `www-data`.
- **Queue.** Use the worker from `DEPLOYMENT.md` §4: `php artisan queue:work --queue=default --tries=3 --backoff=10 --max-time=3600`, under systemd `aruverse-queue`.
- **Never run plain `db:seed` here, or on production.** Use the named real-data seeders above. The 2026–2027 academic year is then added by staff in Admin → Academic years.
- **Seeder safety.** All three AI seeders were re-checked. They are idempotent and additive, and skip any entity and locale an operator already manages, so admin-edited aliases are never overwritten. Ids that don't exist are skipped, and the evaluation cases never overwrite an existing case. Do not run `AiServiceAliasSeeder`; it was renamed to `AiCampusAliasSeeder` and was never deployed.

## 4. Staging `.env` (exact keys, verified in code)

| Key | Staging value | Read as |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` / `APP_URL` | `staging` / `false` / `https://<staging api host>` | EnvironmentGuard |
| `DB_CONNECTION` / `DB_*` | `pgsql` / the **staging** database | `config/database.php` |
| `QUEUE_CONNECTION` | `database` | EnvironmentGuard (not `sync`) |
| `CACHE_STORE` | `database` (default) or redis; the answer cache key is namespaced `ai:answer:v2` | `config/cache.php` |
| `AI_CACHE_ENABLED` | `true` (the privacy smoke checks that public answers still cache) | `ai.cache.enabled` |
| `AICAD_SUPPORTED_FACT_GENERATION_ENABLED` | `on` | `ai.supported_facts.mode` |
| `AICAD_CAMPUS_TIMEZONE` | `Europe/Nicosia` | `ai.campus_timezone` |
| `AI_PROVIDER` / `LOCAL_AI_BASE_URL` / `LOCAL_AI_MODEL` / `LOCAL_AI_API_KEY` | `local` / the GPU host `/v1` (or Ollama `:11434/v1`) / `arucad-ask` (vLLM) or `aicad-qwen3:8b` (Ollama) / the vLLM key | `config/ai.php` |
| `KNOWLEDGE_EMBEDDINGS_ENABLED` / `KNOWLEDGE_EMBEDDINGS_URL` | `true` / `http://127.0.0.1:8801` | `config/knowledge.php` |
| `ROUTING_BASE_URL` | the OSRM foot graph, if routes are tested | `services.routing.base_url` |
| `REVERB_*`, `CORS_ALLOWED_ORIGINS` | the staging host / staging web origin | EnvironmentGuard |
| `MAIL_MAILER` | `log` | isolation |
| `FIREBASE_*` | unset | isolation (no pushes) |

## 5. Then
Follow `AICAD_STAGING_PACKAGE.md` §4 onwards (data readiness, readiness checks, evaluations, smoke) and `AICAD_STAGING_SOAK.md`. That includes the **two-user privacy pack**:

```bash
php artisan aicad:staging-fixtures                                   # fixture rows + 3 fixture accounts (no usable password)
php artisan ask:smoke --privacy --base=https://<staging api>/api/v1
```
