# ARUVERSE — three-domain deployment

Procedure for putting ARUVERSE behind its three production hostnames.
Supersedes nothing: `docs/DEPLOYMENT.md` remains the general provider/runtime
guide, this document is the domain, TLS and routing layer on top of it.

**Nothing in this document has been applied.** No DNS record was created, no
certificate issued, no service restarted, no migration run against a
production database. Every command below is written to be run by someone with
that access, after reading what it does.

---

## 1. Architecture

Three hostnames, **two** deployed artefacts, **one** backend service.

| Hostname | Serves | Artefact |
|---|---|---|
| `app-aruverse.arucad.edu.tr` | Student web app | `flutter build web` output, static files |
| `api-aruverse.arucad.edu.tr` | JSON API, auth, integrations, legal pages, media | Laravel 13 (PHP-FPM) |
| `admin-aruverse.arucad.edu.tr` | Filament staff panel | **the same** Laravel deployment |

`api` and `admin` are one application answering two names. The Filament panel
is server-rendered inside Laravel (`/admin`), so a separate admin frontend
would have been a second copy of something that already exists. The split
buys real isolation anyway:

- the admin session cookie is scoped to `admin-aruverse…` and is never sent
  to the API hostname the student app talks to;
- `/admin` returns **404** on the API host, so the panel's login form is not
  reachable on the name that is exposed to every phone on campus;
- the admin host can carry stricter rules (login rate limit, optional office
  IP allow-list) without touching student traffic.

The web app calls the API **cross-origin**, not through a proxy on its own
host. One CORS allow-list is then the single place that decides which origins
may call the API; a same-origin `/api` proxy would have created a second,
un-CORSed path to the same endpoints.

```
                    ┌─ app-aruverse  → /var/www/aruverse-app (static)
Browser ── nginx ───┼─ api-aruverse  →┐
                    └─ admin-aruverse →┴─ PHP-FPM → Laravel → Postgres (private)
                                                           └→ queue worker
```

---

## 2. DNS records

**Not applied — this repository has no access to the DNS zone.** Give this
table to whoever administers `arucad.edu.tr`.

`<SERVER_IPV4>` / `<SERVER_IPV6>` are the public addresses of the host that
terminates TLS. All three names may point at the same address; nginx separates
them by `Host` header.

| Type | Name | Value | TTL | Required |
|---|---|---|---|---|
| `A` | `app-aruverse` | `<SERVER_IPV4>` | 300 | Yes |
| `A` | `api-aruverse` | `<SERVER_IPV4>` | 300 | Yes |
| `A` | `admin-aruverse` | `<SERVER_IPV4>` | 300 | Yes |
| `AAAA` | `app-aruverse` | `<SERVER_IPV6>` | 300 | Only if the host has IPv6 |
| `AAAA` | `api-aruverse` | `<SERVER_IPV6>` | 300 | Only if the host has IPv6 |
| `AAAA` | `admin-aruverse` | `<SERVER_IPV6>` | 300 | Only if the host has IPv6 |
| `CAA` | `arucad.edu.tr` | `0 issue "letsencrypt.org"` | 3600 | Recommended |

Notes:

- Use `A`/`AAAA`, not `CNAME`: these are hosts at the zone apex's subdomain
  level pointing at an IP we control, and a `CNAME` would add a lookup for no
  benefit.
- **TTL 300 during rollout** so a mistake can be corrected in five minutes.
  Raise to 3600 once traffic is confirmed stable.
- Publish `AAAA` **only** if nginx actually listens on IPv6 and the firewall
  allows it. A published `AAAA` with nothing listening makes the site look
  broken to IPv6-first clients while working fine for everyone else — a
  genuinely confusing failure.
- If a `CAA` record already exists for the zone, do not replace it; add
  `letsencrypt.org` to it, or certificate issuance will fail.

Verify before issuing certificates:

```bash
for h in app api admin; do dig +short "$h-aruverse.arucad.edu.tr" A; done
```

---

## 3. Server layout

```
/var/www/aruverse-api/          # Laravel (git checkout or release tarball)
/var/www/aruverse-app/          # Flutter web bundle
/var/www/certbot/               # ACME HTTP-01 webroot
/etc/nginx/snippets/aruverse-security-headers.conf
/etc/nginx/snippets/aruverse-laravel-php.conf
/etc/nginx/conf.d/aruverse-rate-limit.conf
/etc/nginx/sites-available/{app,api,admin}-aruverse.conf
```

Install the configs from this repo:

```bash
sudo install -m644 deploy/nginx/production/snippets/security-headers.conf \
     /etc/nginx/snippets/aruverse-security-headers.conf
sudo install -m644 deploy/nginx/production/snippets/laravel-php.conf \
     /etc/nginx/snippets/aruverse-laravel-php.conf
sudo install -m644 deploy/nginx/production/rate-limit.conf \
     /etc/nginx/conf.d/aruverse-rate-limit.conf

for f in 00-http-redirect app-aruverse api-aruverse admin-aruverse; do
  sudo install -m644 "deploy/nginx/production/$f.conf" "/etc/nginx/sites-available/$f.conf"
  sudo ln -sf "/etc/nginx/sites-available/$f.conf" "/etc/nginx/sites-enabled/$f.conf"
done
```

`snippets/laravel-php.conf` assumes `php8.3-fpm` on a unix socket. Change
`fastcgi_pass` there if the socket path differs — it is the one place both
hosts read it from.

---

## 4. TLS

The HTTPS server blocks reference certificates that do not exist yet, so
**issue certificates before enabling those blocks** or `nginx -t` fails on a
missing file.

```bash
# 1. HTTP only first: the redirect block serves the ACME challenge.
sudo rm -f /etc/nginx/sites-enabled/{app,api,admin}-aruverse.conf
sudo mkdir -p /var/www/certbot
sudo nginx -t && sudo systemctl reload nginx

# 2. One certificate per hostname (separate certs, so one name can be
#    re-issued or moved without touching the others).
sudo certbot certonly --webroot -w /var/www/certbot \
  -d app-aruverse.arucad.edu.tr
sudo certbot certonly --webroot -w /var/www/certbot \
  -d api-aruverse.arucad.edu.tr
sudo certbot certonly --webroot -w /var/www/certbot \
  -d admin-aruverse.arucad.edu.tr

# 3. Now enable the TLS blocks.
for f in app-aruverse api-aruverse admin-aruverse; do
  sudo ln -sf "/etc/nginx/sites-available/$f.conf" "/etc/nginx/sites-enabled/$f.conf"
done
sudo nginx -t && sudo systemctl reload nginx
```

Renewal is certbot's systemd timer; confirm it with `systemctl list-timers |
grep certbot` and test with `sudo certbot renew --dry-run`.

### HSTS — after, not during

HSTS is deliberately commented out in `snippets/security-headers.conf`.
Enabling it tells browsers to refuse plain HTTP for that host for `max-age`
seconds, and **that cannot be undone by editing nginx** — every browser that
saw the header keeps enforcing it until it expires.

Turn it on only when all three hostnames serve valid certificates:

```bash
curl -sI https://app-aruverse.arucad.edu.tr   | head -1
curl -sI https://api-aruverse.arucad.edu.tr/api/v1/health | head -1
curl -sI https://admin-aruverse.arucad.edu.tr/admin/login | head -1
```

Then uncomment the header with `max-age=300`, leave it a day, and only then
raise it to `31536000`. Do not add `preload` unless ARUCAD accepts that the
domain becomes HTTPS-only in browsers essentially permanently.

---

## 5. Backend deploy

```bash
cd /var/www/aruverse-api
sudo -u www-data git fetch --all
sudo -u www-data git checkout <release-tag>

# Record the current revision first — this is the rollback target.
git rev-parse HEAD | sudo -u www-data tee storage/app/DEPLOYED_REV

sudo -u www-data composer install --no-dev --optimize-autoloader

# EnvironmentGuard aborts here if .env still looks local.
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# See §6 before running this against production data.
sudo -u www-data php artisan migrate --force

sudo -u www-data php artisan storage:link
sudo systemctl reload php8.3-fpm
sudo systemctl restart aruverse-queue    # queue worker
sudo systemctl restart aruverse-reverb   # websocket, if used

# The classifier serves Ask ARUVERSE's semantic search as well as
# moderation, so a deploy that changes it must restart it — an old
# process keeps answering, just without /v1/embed.
sudo systemctl restart aruverse-classifier

# Once, after the first deploy with semantic search: index the pages
# that are already crawled. Thereafter the crawl indexes changed pages
# itself and the scheduler (04:30, 16:30) catches anything missed while
# the classifier was down. Safe to re-run; it is a no-op with nothing
# to do.
sudo -u www-data php artisan knowledge:embed --all
```

Frontend:

```bash
API_BASE_URL=https://api-aruverse.arucad.edu.tr/api/v1 \
REVERB_APP_KEY=<public key> \
  ./deploy/scripts/build-web.sh

sudo rsync -a --delete frontend/build/web/ /var/www/aruverse-app/
```

---

## 6. The integrations migration

This change adds one table, `integration_states`.

| | |
|---|---|
| Migration | `2026_09_16_120000_create_integration_states_table.php` |
| Effect | `CREATE TABLE integration_states` |
| Touches existing data | **No.** It creates a new table and alters nothing. |
| Destructive | No |
| Reversible | Yes — `down()` drops only that table |
| Downtime | None |

It stores no credentials: only an enable/disable flag and the timestamps of
the last test, last success and last error. Dropping it loses that history and
nothing else; every integration's configuration lives in `.env` and
`app_settings` as before.

Rollback:

```bash
sudo -u www-data php artisan migrate:rollback --step=1 --force
```

**Approval required** before running `migrate --force` against the production
database. Take a backup first (`deploy/scripts/pg_backup.sh`), confirm it
restores, then proceed.

---

## 7. Health checks

| Check | Command | Expected |
|---|---|---|
| App | `curl -sI https://app-aruverse.arucad.edu.tr` | `200`, `text/html` |
| API | `curl -s https://api-aruverse.arucad.edu.tr/api/v1/health` | `data.status = "ok"`, `data.database = "ok"` |
| Framework | `curl -sI https://api-aruverse.arucad.edu.tr/up` | `200` |
| Admin | `curl -sI https://admin-aruverse.arucad.edu.tr/admin/login` | `200` |
| Admin isolation | `curl -so /dev/null -w '%{http_code}' https://api-aruverse.arucad.edu.tr/admin` | `404` |
| Reverb upgrade | `curl -si -o /dev/null -w '%{http_code}' -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' 'https://api-aruverse.arucad.edu.tr/app/<REVERB_APP_KEY>?protocol=7&client=js&version=8.0.0'` | `101` (switching protocols). `404` means the proxy location is missing; `200` means it reached Laravel instead of Reverb |
| Publish API is closed | `curl -so /dev/null -w '%{http_code}' https://api-aruverse.arucad.edu.tr/apps/1/events` | `404` |
| HTTP redirect | `curl -sI http://api-aruverse.arucad.edu.tr/api/v1/health` | `301` to `https://` |

`/api/v1/health` reports reachability and the database and classifier states.
It deliberately does **not** name internal hosts — the classifier's base URL
was removed from that payload (see `HealthController`), because the endpoint
is public and unauthenticated.

CORS, from a machine that can reach the API:

```bash
# Allowed origin → echoed back
curl -si -X OPTIONS https://api-aruverse.arucad.edu.tr/api/v1/health \
  -H 'Origin: https://app-aruverse.arucad.edu.tr' \
  -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin

# Foreign origin → header absent
curl -si -X OPTIONS https://api-aruverse.arucad.edu.tr/api/v1/health \
  -H 'Origin: https://evil.example' \
  -H 'Access-Control-Request-Method: GET' | grep -i access-control-allow-origin
```

---

## 8. Rollback

Ordered least to most disruptive. Each step is independent.

**Frontend** — keep the previous bundle before rsync:

```bash
sudo cp -a /var/www/aruverse-app /var/www/aruverse-app.prev   # before deploy
sudo rsync -a --delete /var/www/aruverse-app.prev/ /var/www/aruverse-app/
```

**Backend code:**

```bash
cd /var/www/aruverse-api
sudo -u www-data git checkout "$(cat storage/app/DEPLOYED_REV)"
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache
sudo systemctl reload php8.3-fpm && sudo systemctl restart aruverse-queue
```

**Migration:** `php artisan migrate:rollback --step=1 --force` (§6).

**nginx:**

```bash
sudo rm -f /etc/nginx/sites-enabled/{app,api,admin}-aruverse.conf
sudo nginx -t && sudo systemctl reload nginx
```

**A failed deploy that will not start:** put the API in maintenance mode
rather than leaving it half-broken —
`php artisan down --render="errors::503"`, fix, then `php artisan up`.

**What rollback cannot undo:** HSTS, once browsers have cached it, and a DNS
change while the old TTL is still live. Both are why HSTS is off by default
and why TTL stays at 300 during rollout.

---

## 9. Pending information

The work is complete except for values this repository cannot know. Each
blocks only its own line item.

| Needed | Blocks | Who |
|---|---|---|
| `<SERVER_IPV4>` (and `<SERVER_IPV6>` if any) | DNS records, §2 | ARUCAD IT |
| DNS zone change approval | §2 | ARUCAD IT |
| Postgres host + `aruverse_app` password | `DB_*` in `.env` | ARUCAD IT |
| SMTP host / user / password | `MAIL_*`; e-mail integration stays *Not Configured* | ARUCAD IT |
| Reverb app id / key / secret | realtime; generated at deploy | Deployer |
| Entra tenant + client id, redirect URI | Microsoft sign-in stays *Not Configured* | ARUCAD IT |
| Firebase service account (project id, client e-mail, private key) | push stays *Not Configured*; inbox still works | ARUCAD |
| `ROUTING_BASE_URL` + `ROUTING_DRIVING_BASE_URL` (OSRM foot + car graphs, Cyprus extract) | the unset mode returns 501; vehicles are never routed over the foot graph | Decision: self-host both (`docker compose --profile routing up -d`, see `deploy/osrm/`) or skip |
| WordPress site URL + API token | WordPress stays *Not Configured* | ARUCAD web team |
| Sentry DSN | error reporting silently off | ARUCAD |
| Office/VPN CIDR ranges | the admin IP allow-list in `admin-aruverse.conf` | ARUCAD IT |
| Whether a CDN fronts these hosts | the rate-limit key (`$binary_remote_addr` vs `$http_cf_connecting_ip`) | ARUCAD IT |

`CAMPUS_DIRECTORY_API_KEY` and `GROQ_API_KEY` already exist and are held by
the team — they are not committed here and must be pasted into the server's
`.env` directly.

---

## 10. Known gaps and remaining risk

Found during this work and **not** silently fixed, because each needs a
decision rather than a patch.

### MFA is recorded but not enforced

`users.mfa_required` exists, is editable in the panel, and is checked by
**nothing**. No login path — Filament session, password, or Entra — consults
it. An administrator ticking that box had no protection and no indication of
that.

Building a partial MFA flow would have been worse than none, so the change
made here is narrow: the toggle now carries helper text saying it is recorded
but not yet enforced, so it cannot be mistaken for a control.

Two honest ways to close it, in order of preference:

1. **Enforce it at the identity provider.** These are `@arucad.edu.tr`
   accounts and Entra already supports Conditional Access. Requiring MFA for
   the ARUVERSE application in Entra costs no application code and covers
   every sign-in. This is the recommended route.
2. **Implement TOTP in-app** (enrolment, QR provisioning, recovery codes,
   a verification step in the Filament login and the API token grant). A real
   piece of work, roughly a week, and it only covers password logins.

Until one of these lands, treat the admin panel as single-factor and rely on
the optional office/VPN allow-list in `admin-aruverse.conf`.

### There are no webhooks

The brief asked for `/api/webhooks/*` with per-provider signature validation.
**No provider currently sends webhooks to this application** — no route, no
controller, no secret, and none of the configured integrations (360 directory,
Groq, Entra, FCM, OSRM, OpenAI moderation, WordPress, Sentry) is set up to
call back into it. Every integration is outbound: we call them.

No endpoint was created. An unauthenticated public route that accepts posts
from "a provider" that does not exist is an attack surface with no user, and a
signature check against a secret nobody issued proves nothing.

When a real one is needed, the shape it should take:

- one route per provider under `routes/api.php`, outside the `auth:sanctum`
  group (a provider has no user session) but **inside** a dedicated throttle;
- signature verification as middleware, before the controller, using
  `hash_equals()` against the provider's documented scheme (HMAC-SHA256 over
  the **raw** body — Laravel's parsed input will not reproduce the bytes);
- a timestamp/nonce check rejecting anything older than ~5 minutes, to stop a
  captured-and-replayed delivery;
- the provider's signing secret in `.env`, and the integration registered in
  `IntegrationRegistry` so its health appears with the rest.

### `/api/v1/health` disclosure

Fixed here: the classifier's internal base URL was being returned to anonymous
callers and has been removed (`HealthController`). The endpoint still reports
the classifier's state and model/policy versions, which is a deliberate,
tested product decision — reconsider it only if ARUCAD treats the running
model version as sensitive.

### Not verified in this environment

Stated plainly so nothing reads as more tested than it is:

- **`nginx -t` was never run.** No nginx or Docker is available on this
  machine. The configs were checked structurally (balanced blocks, directive
  termination, all three hostnames present) but not parsed by nginx. Run
  `sudo nginx -t` before the first reload; treat a failure there as expected
  iteration, not as a broken design.
- **No certificate was issued and no DNS record exists**, so HTTPS,
  the HTTP→HTTPS redirect and the reverse-proxy routing are unexercised.
- **No live integration test was run against a real provider.** Every
  connection test is covered by faked HTTP in the test suite
  (`Http::preventStrayRequests()` makes an accidental real call fail). The
  first genuine run happens when someone presses the button on a server that
  has the credentials.
