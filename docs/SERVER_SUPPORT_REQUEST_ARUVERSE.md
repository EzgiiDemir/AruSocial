# ARUVERSE server support request

## Domains/subdomains in scope

| Hostname | Purpose | Server target |
|---|---|---|
| `app-aruverse.arucad.edu.tr` | Student-facing Flutter web application | Static web root: `/var/www/aruverse-app` |
| `api-aruverse.arucad.edu.tr` | Laravel JSON API, authentication, media and integrations | Laravel public root: `/var/www/aruverse-api/public` |
| `admin-aruverse.arucad.edu.tr` | Filament staff administration panel | Same Laravel public root: `/var/www/aruverse-api/public` |

All three hostnames may resolve to the same server. Nginx separates them by
hostname. The existing `arucad.edu.tr` site and its current subdomains are not
part of this change.

Current verification (21 September 2026): `app-aruverse.arucad.edu.tr` and
`api-aruverse.arucad.edu.tr` return DNS "name does not exist". The mobile app
is configured for the public API hostname, but all-network access will begin
only after the requested DNS records, server virtual hosts and TLS certificate
are active.

## Ready-to-send request

**Subject:** ARUVERSE production server, DNS and SSL setup request

Hello,

We are preparing the ARUVERSE campus application for production and request
server support for the following subdomains:

- `app-aruverse.arucad.edu.tr` — student web application
- `api-aruverse.arucad.edu.tr` — application API and media endpoints
- `admin-aruverse.arucad.edu.tr` — staff administration panel

Please help us with the following:

1. Confirm the production server's public IPv4 address and, if supported, its
   public IPv6 address.
2. Create DNS `A` records for all three hostnames pointing to that server. Add
   `AAAA` records only if IPv6 is fully configured on the server and firewall.
3. Use TTL `300` during rollout; it can be raised to `3600` after verification.
4. Allow inbound TCP ports `80` and `443`.
5. Configure Nginx virtual hosts for all three names. The web application is
   served from `/var/www/aruverse-app`; the API and admin hosts share the
   Laravel deployment at `/var/www/aruverse-api/public`.
6. Issue and configure trusted TLS certificates for all three hostnames,
   including automatic renewal.
7. Confirm the installed PHP-FPM socket/version, PostgreSQL connection details,
   and availability of system services for the Laravel queue worker and Reverb
   WebSocket process.
8. Do not enable a long-duration HSTS policy until all three HTTPS endpoints
   have been tested successfully.

The Nginx configuration and deployment procedure are available in the project
under `deploy/nginx/production/` and `docs/DEPLOYMENT_ARUVERSE.md`.

Please send us the server IP address(es), PHP-FPM socket/version, database
connection handoff method, and the planned maintenance window so we can fill
the production environment values and coordinate deployment.

Thank you.

## DNS records to request

| Type | Name | Value | TTL |
|---|---|---|---|
| `A` | `app-aruverse` | `<SERVER_IPV4>` | `300` |
| `A` | `api-aruverse` | `<SERVER_IPV4>` | `300` |
| `A` | `admin-aruverse` | `<SERVER_IPV4>` | `300` |
| `AAAA` | `app-aruverse` | `<SERVER_IPV6>` | `300` |
| `AAAA` | `api-aruverse` | `<SERVER_IPV6>` | `300` |
| `AAAA` | `admin-aruverse` | `<SERVER_IPV6>` | `300` |

The `AAAA` records are optional and must not be published unless the host is
reachable over IPv6.

---

# Technical specification

Written to be sent with the request above, as the answer to "what domains,
what server, which OS, which database, what else". Everything here is a
**requirement the repository already encodes** — nginx configs, `composer.json`,
the production `.env` template, the classifier service — not a description of
a running system. Nothing is deployed yet; the last section lists the values
only ARUCAD can supply.

Procedure (commands, TLS issuing order, rollback) stays in
`docs/DEPLOYMENT_ARUVERSE.md`; this section is the inventory.

## 1. Hostnames

Three names, **two** artefacts, **one** backend application. See the table at
the top of this document for the roots. `api` and `admin` are the same Laravel
deployment answering two hostnames; nginx separates them by `Host` header, and
`/admin` returns 404 on the API name so the panel's login form is not reachable
on the hostname exposed to every phone on campus.

The student web app calls the API **cross-origin** (no `/api` proxy on the app
host), so one CORS allow-list is the single place that decides which origins
may call the API.

## 2. Server operating system

We are asking for **Ubuntu Server 24.04 LTS** (Debian 12 is equally fine).

That is not a preference in the abstract — the configuration in
`deploy/nginx/production/` is written against Debian/Ubuntu conventions and
will need edits on anything else:

| Assumption | Where |
|---|---|
| `/etc/nginx/sites-available` + `sites-enabled` | install steps, `DEPLOYMENT_ARUVERSE.md` §3 |
| `/etc/nginx/conf.d/` for `http{}`-level config | `rate-limit.conf` |
| PHP-FPM unix socket at `/run/php/php8.3-fpm.sock` | `snippets/laravel-php.conf` |
| `www-data` as the web user | deploy commands |
| systemd for the queue worker, Reverb and the classifier | section 4 below |
| certbot's systemd renewal timer | TLS section of the deployment doc |
| `cron` for `php artisan schedule:run` | section 4 below |

Another Linux distribution works with path changes only. Windows Server is not
what these files describe — the repository does contain
`backend/scripts/install-scheduler.ps1`, but that exists for developer
machines, not as a production target.

**We need to be told the actual OS and version that is provisioned**, because
the PHP-FPM socket path in `snippets/laravel-php.conf` is derived from it and
is the one line both vhosts read.

## 3. Runtime software

| Component | Version | Why this version |
|---|---|---|
| nginx | any current stable, with `http2` | terminates TLS for all three names |
| PHP-FPM | **8.3** | `composer.json` requires `php: ^8.3` |
| Composer | 2.x | `composer install --no-dev --optimize-autoloader` |
| PostgreSQL | **16** | the version the CI matrix tests against |
| Python | **3.12** | the moderation classifier's own venv |
| certbot | any current | Let's Encrypt, webroot mode |

Frameworks, for reference: Laravel 13.17, Filament 5.8 (staff panel),
Sanctum 4 (API bearer tokens), Reverb 1.11 (websocket), Sentry SDK 4.

**PHP extensions:** `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `curl`, `xml`,
`tokenizer`, `ctype`, `fileinfo`, `intl`, `bcmath`, `zip` — the standard Laravel
set plus Postgres. **GD and Imagick are deliberately not required:** image
dimensions are read from the file header (`app/Support/ImageDimensions.php`),
so the server never decodes pixels.

**Node.js is not needed on the server.** Both frontends are built elsewhere and
copied in: the Flutter web bundle (Flutter 3.47.1, the CI version) is rsynced
to `/var/www/aruverse-app`, and the panel's compiled assets ship with the
release.

## 4. Processes that must stay running

Five, plus cron. Four are long-lived and need a supervisor; when two of them
stop, the failure is invisible from the outside.

| Process | Command | Consequence if it stops |
|---|---|---|
| `nginx` | — | everything is down (visible) |
| `php8.3-fpm` | — | API and panel return 502 (visible) |
| queue worker | `php artisan queue:work --tries=3 --backoff=10 --max-time=3600` | e-mail and push sit in the `jobs` table forever |
| Reverb | `php artisan reverb:start --host=127.0.0.1 --port=8080` | realtime chat and notifications stop; the app still works over REST |
| classifier | `uvicorn app.main:app --host 127.0.0.1 --port 8801` | uploads fail closed (503) and text moderation recall drops from 55% to 31% |

Suggested unit names, used throughout the deployment doc: `aruverse-queue`,
`aruverse-reverb`, `aruverse-classifier`.

**Cron — one line, and it is currently missing everywhere:**

```
* * * * *  cd /var/www/aruverse-api && php artisan schedule:run >> /dev/null 2>&1
```

Laravel's scheduler is not a daemon; without that line the four scheduled
commands never run. They are: the 360 directory sync (hourly), story expiry
(every 15 minutes — stories are a 24-hour promise), the moderation health
check (hourly), and the purge of refused-content copies (03:30, required by
the privacy notice).

Also run `php artisan queue:restart` after every deploy, or the worker keeps
executing the previous release from memory.

## 5. Ports

| Port | Bound to | Reachable from |
|---|---|---|
| 80 | public interface | internet — HTTP to HTTPS redirect and the ACME challenge only |
| 443 | public interface | internet — all three hostnames |
| 8080 | `127.0.0.1` | nginx only (Reverb) — see the gap in section 9 |
| 8801 | `127.0.0.1` | the Laravel process only (classifier) |
| 5432 | private interface or `127.0.0.1` | the application host only — **never the internet** |
| 22 | per ARUCAD policy | administrators |

Edge rate limiting is already written
(`deploy/nginx/production/rate-limit.conf`): 5 requests per minute on the admin
login form, 30 r/s general, plus a per-IP connection cap, all returning 429.
**If a CDN fronts these hosts, tell us** — the zones key on
`$binary_remote_addr`, which behind a proxy is the proxy, and the whole campus
would then share one bucket.

## 6. Outbound access the server needs

The application is outbound-only: nothing external calls in, and there are no
webhooks. Every destination below degrades honestly if blocked (the feature
reports "Not Configured" or returns 501) rather than failing at runtime.

| Destination | Port | For | Required? |
|---|---|---|---|
| `360.arucad.edu.tr` | 443 | campus 360 directory sync, tour proxy | yes |
| `aday.arucad.edu.tr`, `academics.arucad.edu.tr` | 443 | Ask ARUVERSE knowledge crawler | yes |
| `smtp.office365.com` | 587 | institutional e-mail (STARTTLS) | yes |
| `api.groq.com` | 443 | Ask ARUVERSE assistant, poster drafts | yes, unless a self-hosted LLM is used instead |
| `login.microsoftonline.com` | 443 | Entra sign-in token verification (JWKS) | only if Microsoft sign-in is enabled |
| `fcm.googleapis.com`, `oauth2.googleapis.com` | 443 | push while the app is closed | only if push is enabled |
| `api.open-meteo.com` | 443 | campus weather (keyless, degrades to null) | optional |
| `huggingface.co` | 443 | classifier model weights, **first start only** | yes, once |
| ACME / Let's Encrypt | 443 | certificate issue and renewal | yes |
| Sentry ingest | 443 | error reporting | only if a DSN is set |

`api.openai.com` is deliberately **not** on that list: third-party moderation is
off by default, and enabling it would contradict the published privacy notice.

## 7. Filesystem layout and disk

```
/var/www/aruverse-api/           Laravel release
/var/www/aruverse-api/public/    document root for api + admin
/var/www/aruverse-api/storage/   uploaded media, logs, cached views
/var/www/aruverse-app/           Flutter web bundle (static)
/var/www/certbot/                ACME HTTP-01 webroot
/opt/aruverse-classifier/        FastAPI service and its Python venv
~/.cache/huggingface/            pinned model weights, about 1.4 GB
```

Uploaded media is stored on the **local** disk, not the public one
(`FILESYSTEM_MEDIA_DISK=local`): the `public/storage` symlink would otherwise
serve files without the moderation gate ever running. Per-image limit is 12 MB
in the application, with `client_max_body_size 32m` at nginx for multipart
overhead.

## 8. Database

| | |
|---|---|
| Engine | PostgreSQL 16 |
| Database | `aruverse`, UTF-8, schema `public` |
| Application role | `aruverse_app`, `LOGIN` only |
| Extensions | none required |
| Port | 5432, private interface or loopback |
| Timezone | the application stores and computes in UTC |
| Schema | 113 migrations; the app creates its own tables on the first `migrate` |

The role must **not** be the superuser and must **not** hold `CREATEDB` or
`CREATEROLE`. It needs `CREATE` on `public` only because migrations create the
tables:

```sql
CREATE ROLE aruverse_app LOGIN PASSWORD '<generated by ARUCAD IT>';
GRANT CONNECT ON DATABASE aruverse TO aruverse_app;
GRANT USAGE, CREATE ON SCHEMA public TO aruverse_app;
```

If Postgres runs on a different host from PHP, set `DB_SSLMODE=require`;
`prefer` is acceptable only on loopback or a trusted private network.

Sessions, cache and the queue all live in this database (`SESSION_DRIVER`,
`CACHE_STORE` and `QUEUE_CONNECTION` are all `database`), so **no Redis is
required**. If ARUCAD would rather run Redis, the application supports it with
a configuration change and no code change.

**Backups are not configured and must be.** `deploy/scripts/pg_backup.sh` is
written for a docker-compose Postgres; its `docker compose exec` line needs
replacing with a direct `pg_dump` for a native install. The intended policy: a
daily dump, 14 days retained, stored off the database host, plus an explicit
dump immediately before every `php artisan migrate --force`, and a restore test
each quarter. An untested backup is not a backup.

## 9. Reverb websocket — now routed, not yet parsed

This section previously recorded a gap: the production `.env` template points
clients at `wss://api-aruverse.arucad.edu.tr` and Reverb listens on
`127.0.0.1:8080`, but `api-aruverse.conf` had no websocket proxy, so the
handshake would have 404'd at nginx.

**That is now written.** `api-aruverse.conf` proxies `/app/` — the exact path
the Flutter client opens (`chat_realtime_config.dart` builds it) — to
`127.0.0.1:8080` with the `Upgrade`/`Connection` headers,
`proxy_http_version 1.1`, a one-hour read timeout and buffering off. The
timeout matters: a websocket is idle between messages by definition, and the
default 60 seconds would drop every quiet conversation into a reconnect loop
that reads to a student as broken wifi. `TRUSTED_PROXIES=*` in the production
template is what makes `X-Forwarded-Proto` resolve behind it.

Reverb's HTTP publish API (`/apps/<id>/events`) is explicitly 404'd. The
application reaches it over loopback; exposed publicly it would be an
unauthenticated surface guarded only by a shared secret.

**Still unverified, and this is the honest part:** there is no nginx on the
machine this was written on, so the file has been checked structurally
(balanced blocks, correct directives) and never parsed. Run `sudo nginx -t`
before the first reload and treat a failure there as expected iteration. The
handshake itself cannot be tested until DNS, TLS and a running Reverb process
exist together.

## 10. Resource sizing

**Mostly an estimate from the component list.** Nothing is deployed, so
nothing has been load-tested end to end. One component has since been
measured, on a 20-core development machine rather than the server:

| | measured |
|---|---|
| Text moderation | 75-100 requests/second, p95 16-20 ms at low concurrency |
| Retrieval embeddings | ~80 requests/second |
| Behaviour past ~4 concurrent | throughput flat, latency rises in proportion |

That is roughly 270,000 moderated items an hour, which a 3,000-student
campus would have to sustain at 90 posts and messages per second to
exhaust. The classifier is therefore unlikely to be the constraint. More
worker processes would not raise it either: PyTorch already spreads one
request across the available cores.

The figures below remain an estimate.

The memory driver is the classifier: three pinned PyTorch models on CPU (NSFW
image, multilingual sentence encoder, CLIP), roughly 1.4 GB of weights and
100–220 ms per image. Everything else — PHP-FPM, the worker, Reverb, and
Postgres at this scale — is modest.

| | Estimate |
|---|---|
| vCPU | 4 |
| RAM | 8 GB (the classifier alone wants roughly 2–3 GB resident) |
| Disk | 80 GB SSD, growing with uploaded media |
| GPU | none — CPU inference is sufficient at campus scale |

If the classifier is given its own small host instead, the web/API host can be
noticeably smaller; `IMAGE_MODERATION_URL` and `TEXT_MODERATION_URL` would then
point at that private address instead of loopback.

## 11. Still needed from ARUCAD IT

| Needed | Blocks |
|---|---|
| Public IPv4 (and IPv6 only if the firewall really allows it) | the DNS records above |
| DNS zone change approval | the DNS records above |
| The provisioned OS and version | the PHP-FPM socket path in the nginx snippet |
| Postgres host, port and the `aruverse_app` password | `DB_*` in the server `.env` |
| The SMTP password for `support@arucad.edu.tr` | e-mail; SMTP AUTH must be enabled on that mailbox, and an app password is needed if the tenant enforces MFA |
| Entra tenant id, client id and redirect URI | Microsoft sign-in stays "Not Configured" |
| Office/VPN CIDR ranges | the optional admin IP allow-list, commented out in `admin-aruverse.conf` |
| Whether a CDN fronts these hosts | the rate-limit key (section 5) |
| Maintenance window | scheduling the cutover |

`CAMPUS_DIRECTORY_API_KEY` and `GROQ_API_KEY` already exist and are held by the
team. They are not in git and will be pasted into the server's `.env` directly.
