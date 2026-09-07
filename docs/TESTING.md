# Test Rehberi

Nerede, nasıl test edeceğini gösteren tek doküman. Üç katman var:
**frontend** (Flutter), **backend** (Laravel API), ve **uçtan uca**
(ikisi birlikte, gerçek HTTP üzerinden).

---

## 1. Frontend — statik kontrol (her zaman ilk adım)

## Baseline

Current application API surface: **151** unique `METHOD + /api/v1/...`
routes (HEAD omitted). See `docs/API_CONTRACT.md` inventory and
`ApiContractInventoryTest`.

### Latest P4–P9 product packages (2026-08-26)

- Campus POI + staff: `php artisan db:seed --class=CampusCatalogSeeder` or `php backend/scripts/upsert_p1p2_seed.php`
- Local routing: set `ROUTING_BASE_URL=https://router.project-osrm.org` (public demo) or leave empty for 501 / straight-line honesty
- Shell: **6** bottom tabs including Ask ARUCAD (`campus_shell_tabs_test.dart`)
- Admin queues soft-poll every 30–45s; Reverb still opt-in (`REVERB_ENABLED=true`)

### Latest Final Hardening baseline (2026-08-26)

- `php artisan test --compact` → **393 passed**
- `flutter test` → appointment + suite (see latest run)
- `flutter analyze` → pre-existing info/warnings only (no new blocking errors after unused-helper cleanup)
- `php artisan route:list --path=api` → **151 routes**
- Dashboard/Stats merged; appointment cancel + slot status codes covered by `FinalHardeningAppointmentTest`
- `flutter run -d web-server --web-port 7357` boots (HTTP 200); full DevTools console sweep still requires interactive browser

### Latest Hardening 3 baseline (2026-08-26)

- `php artisan test --compact` → **389 passed**
- `flutter test` → **102 passed**
- `flutter analyze` → **12 issues** (pre-existing info/warnings set; no new blocking error)
- `php artisan route:list --path=api` → **151 routes**
- `flutter run -d web-server --web-port 7357` boots successfully (HTTP 200 verified)

```bash
cd frontend
flutter analyze
```

Temiz olmalı (birkaç bilinen, zararsız uyarı dışında — `tools/` klasöründeki
yardımcı script'ler ve bir üçüncü-parti paket kaynaklı uyarı). Yeni bir
hata çıkarsa değişikliğin kırdığı bir şey var demektir.

## 2. Frontend — widget testi

```bash
cd frontend
flutter test
```

`test/widget_test.dart` gerçek `ArucadCampusApp` kök widget'ını, gerçek
Mock servis implementasyonlarıyla ayağa kaldırıp `MaterialApp` bulunduğunu
doğruluyor — şablon değil, gerçek bir duman testi (smoke test).

## 3. Frontend — uygulamayı gerçekten çalıştırıp görmek

```bash
cd frontend

# Web (varsayılan Mock veri, backend gerekmez)
flutter run -d web-server --web-port=8090 --web-hostname=0.0.0.0 --release

# Android emülatör/cihaz (varsayılan Mock veri)
flutter run --release
```

Tarayıcıda `http://localhost:8090` — normal öğrenci uygulaması `/`,
admin paneli `/admin` (örn. `http://localhost:8090/admin`).

**Test hesapları:**
- Öğrenci: herhangi bir `@arucad.edu.tr` e-postası + boş olmayan şifre
  (örn. `ogrenci@arucad.edu.tr` / `test1234`)
- Admin (superAdmin): `ezgi.demir@arucad.edu.tr` / `Ez26m!r`

## 4. Backend — kurulum ve çalıştırma

```bash
cd backend
composer install
php artisan migrate:fresh --seed
php artisan config:cache
php artisan serve --port=4000
php artisan reverb:start   # chat delivery; REST still works if this is down
```

PHP 8.5 + Windows built-in server currently trips over `vlucas/phpdotenv`
while reading `.env` (`intlcal_get_first_day_of_week()` argument error).
`config:cache` skips that load path so `/api/v1/health` can boot. Run
`php artisan config:clear` before `php artisan test` — PHPUnit's in-memory
SQLite must not be overridden by the cached file config. Cache again
before the next `serve` / `verify_rest_backend` run.

Ortam dart-define / `.env` örnekleri: `docs/ENVIRONMENTS.md`. Debug
`flutter run` mock kalır. Staging/prod release `USE_REST_API=true` +
`API_BASE_URL` ister.

`backend/server.php` is artisan serve's local router: it answers Chrome's
`GET /` and `/json/version` probes without booting Laravel (those probes
otherwise fatal the single PHP process).

Sağlık kontrolü: `curl http://localhost:4000/api/v1/health` → `200` ve
`{"data":{"status":"ok",...,"database":"ok"},...}` dönmeli. Uygulamanın
kullandığı `/api/v1` base path'inin ta kendisi olduğu için "sunucu ayakta
mı" ile "uygulama doğru adrese mi bakıyor" sorularını aynı anda cevaplar;
`curl http://localhost:4000/api/v1` de aynı yanıtı verir.

`database` alanı `unavailable` diyorsa sunucu ayakta ama veritabanına
ulaşamıyor demektir — genelde `sql/database.sqlite` eksiktir (§7).

PHPUnit varsayılanı **SQLite in-memory** (`backend/phpunit.xml`). Bozma:

```bash
cd backend
php artisan config:clear
php artisan test
```

PostgreSQL aynı feature suite (CI `postgres:16` servisi; yerelde ulaşılabilir
bir sunucu + `pdo_pgsql` gerekir):

```bash
cd backend
php artisan test --configuration=phpunit.pgsql.xml
```

`phpunit.pgsql.xml` `DB_CONNECTION=pgsql`, `127.0.0.1:5432`, veritabanı
`arucad_test`, kullanıcı/şifre `arucad` (yalnız test; secret değil). Bu
rol/veritabanı yoksa Postgres `password authentication failed` der —
superuser ile bir kez oluştur:

```sql
CREATE USER arucad WITH PASSWORD 'arucad';
CREATE DATABASE arucad_test OWNER arucad;
```

CI aynı kimlik bilgilerini `postgres:16` servisinde üretir. Default local
`.env` SQLite kalır.

Mail: local `.env` `MAIL_MAILER=log` (gerçek kutu yok). PHPUnit
`MAIL_MAILER=array` + testlerde `Mail::fake()` — gerçek SMTP ağı yok.
Staging/prod `MAIL_MAILER=smtp` + `MAIL_*` (placeholder: `.env.example`).

PHP'de `fileinfo`/`pdo_sqlite`/`sqlite3` eksikse (Composer/artisan hata
verirse) `backend/README.md`'deki `PHPRC` çözümüne bak.

## 5. Backend — uçtan uca doğrulama (tarayıcı/emülatör gerekmez)

Backend ayakta olduğu sürece, uygulamanın gerçekte kullandığı **aynı**
Dart kodunu (`ApiClient`, `RestCampusRepository`, DTO'lar) gerçek sunucuya
karşı çalıştırıp her uçta gerçek bir okuma/yazma yapan script:

```bash
cd frontend
dart run tool/verify_rest_backend.dart
```

Önce `POST /auth/session` ile giriş yapar (API artık token'sız hiçbir uca
cevap vermiyor), sonra aldığı Sanctum token'ı ile devam eder. Varsayılan
hesap seed'lenmiş **superAdmin**'dir, çünkü aşağıdaki kontroller admin
uçlarını da kapsıyor ve onlar artık gerçek yetki istiyor — öğrenci hesabı
yarısında (doğru şekilde) 403 alır. Başka bir hesapla çalıştırmak için:

```bash
dart run tool/verify_rest_backend.dart --email baska@arucad.edu.tr --password ...
```

46 gerçek kontrol yapar: profil, mekânlar, etkinlik+katılma, quest'ler,
sosyal akış (post oluştur/beğen/yorumla/şikayet et), hikayeler, liderlik
tablosu, aktivite geçmişi, admin etkinlik ekle/sil, admin şikayet listesi.
Hepsi **OK** dönmeli. Herhangi biri **FAIL** derse backend'de gerçek bir
regresyon var demektir — script hatayı ve beklenen/gerçek değeri basar.

Farklı bir backend'e karşı test etmek için (örn. gerçek bir sunucuya
deploy ettikten sonra):

```bash
dart run tool/verify_rest_backend.dart https://api.senin-sunucun.com/api/v1
```

## 6. Frontend'i gerçek backend'e bağlayarak test etmek

Varsayılan olarak Flutter hâlâ `MockCampusRepository` kullanır (backend
gerekmez). Gerçek backend'i kullanarak test etmek için:

```bash
cd frontend

# Web ve Android emülatör — ikisi de aynı makinedeki 4000 portunu
# varsayılan olarak doğru çözer, ek tanım gerekmez
flutter run -d chrome --dart-define=USE_REST_API=true \
  --dart-define=REVERB_ENABLED=true \
  --dart-define=REVERB_APP_KEY=arucad-local-key \
  --dart-define=REVERB_HOST=localhost \
  --dart-define=REVERB_PORT=8080 \
  --dart-define=REVERB_SCHEME=http
flutter run --dart-define=USE_REST_API=true

# Gerçek telefon (aynı Wi-Fi) bu makinenin loopback'ine ulaşamaz; LAN IP
# açıkça verilmeli ve backend `php artisan serve --host=0.0.0.0` ile açılmalı
flutter run --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://<bu-makinenin-LAN-IP>:4000/api/v1
```

`API_BASE_URL` verildiğinde her zaman kazanır. Verilmezse varsayılan
`http://localhost:4000/api/v1`, Android'de ise `http://10.0.2.2:4000/api/v1`
olur (emülatörün kendi loopback'i emülatörün kendisidir; 10.0.2.2 host
makineye giden takma addır).

Bu modda admin panelinden eklenen bir etkinlik gerçekten öğrenci
tarafında da görünür (aynı SQLite satırını okuyorlar) — iki ayrı
sekme/cihaz açıp doğrulayabilirsin.

### Bu modda giriş gerçek

Mock modun aksine burada şifre gerçekten doğrulanır: giriş `POST
/api/v1/auth/session`'a gider, backend Sanctum token'ı üretir, uygulama
onu saklar ve sonraki her isteğe `Authorization: Bearer …` olarak ekler.
Seed'lenmiş hesaplar:

| E-posta | Şifre | Rol |
| --- | --- | --- |
| `ege.aydin@arucad.edu.tr` | `demo-password` | öğrenci |
| `ezgi.demir@arucad.edu.tr` | `Ez26m!r` | superAdmin (yönetim paneli) |

`@arucad.edu.tr` uzantılı **yeni** bir adresle ilk giriş hesabı o şifreyle
oluşturur; sonraki girişlerde aynı şifre istenir. Uzantı dışı adresler
`DOMAIN_NOT_ALLOWED` ile reddedilir (`AUTH_ALLOWED_EMAIL_DOMAIN`).

İki farklı hesapla iki sekme açarsan artık gerçekten iki farklı
kullanıcısındır — check-in'in XP'si kimin yaptıysa ona yazılır. Çıkış
yaptığında token sunucuda iptal edilir; aynı token'la yapılan istek
`401 AUTH_REQUIRED` döner.

### Yetki de gerçek

Giriş yapmış olmak yönetici olmak değildir. Öğrenci hesabının token'ıyla
bir admin ucuna gitmeyi dene:

```bash
# öğrenci token'ı al
TOKEN=$(curl -s -X POST http://localhost:4000/api/v1/auth/session \
  -H "Content-Type: application/json" \
  -d '{"email":"ege.aydin@arucad.edu.tr","password":"demo-password"}' | jq -r .data.token)

curl -i http://localhost:4000/api/v1/me          -H "Authorization: Bearer $TOKEN"   # 200
curl -i http://localhost:4000/api/v1/admin/stats -H "Authorization: Bearer $TOKEN"   # 403 FORBIDDEN
curl -i http://localhost:4000/api/v1/admin/stats                                     # 401 AUTH_REQUIRED
```

Aynı isteği `ezgi.demir@arucad.edu.tr` token'ıyla yaparsan `200` alırsın.

Yetki kuralları `backend/app/Services/GranularPermissions.php` içinde tek
yerde durur: her admin bölümünün kendi izin anahtarı var, her rol belirli
anahtarları kapsar, `superAdmin` hepsini geçer ve
`role_assignments.permissions` bir kişiye rolünün üstüne tek tek anahtar
ekleyebilir (yalnızca ekler, asla kısıtlamaz).

Panelde yetkin olmayan bir sekmeye girersen sonsuz spinner değil, "Bu
bölüm için yetkin yok." mesajını görürsün.

### Beğeni de gerçek

Beğeni artık `post_likes` tablosunda bir satır. İki farklı hesapla iki
sekme aç, aynı gönderiyi beğen:

- A beğenince B'nin kalbi dolmaz, ama sayı ikisinde de artar.
- A beğenisini geri alınca B'ninki durur, sayı 1'e döner.
- Aynı hesap ne kadar hızlı tıklarsa tıklasın iki satır oluşamaz —
  `(user_id, post_id)` unique.

Seed akışındaki gönderilerde artık uydurma sayılar (14, 6) yok; görünen
sayı gerçekten atılmış beğeni kadar.

### Push token (P3-2)

Mevcut uçlar: `POST /api/v1/push-tokens`, `POST /api/v1/push-tokens/unregister`.
Phpunit gerçek Google ağına çıkmaz (`FakeFcmClient`). HTTP duman (port 4000):

```bash
TOKEN=$(curl -s -X POST http://localhost:4000/api/v1/auth/session \
  -H "Content-Type: application/json" \
  -d '{"email":"ege.aydin@arucad.edu.tr","password":"demo-password"}' | jq -r .data.token)

curl -s -X POST http://localhost:4000/api/v1/push-tokens \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"token":"smoke-device","platform":"android"}'
# aynı token tekrar → tek satır
curl -s -X POST http://localhost:4000/api/v1/push-tokens/unregister \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"token":"smoke-device"}'
```

Yetkisiz `401`. Başka kullanıcının token'ını unregister etmek o satırı silmez.
`QUEUE_CONNECTION=sync` testte; boş `FIREBASE_*` → `NullFcmClient`.

## 6b. Data migration dry-run (P3-8)

SQLite → PostgreSQL cutover'dan önce, gerçek satır sayılarını görmek için
(hiçbir şey yazmaz):

```bash
cd backend
php artisan db:migrate-sqlite-to-pgsql --dry-run
```

Hedef bağlantı (`--target=pgsql`, varsayılan) gerçekten ulaşılabilir bir
PostgreSQL'e işaret etmeli ve üzerinde `php artisan migrate --force` zaten
çalıştırılmış olmalı (bu komut şema oluşturmaz, yalnız satır kopyalar).
Gerçek yazma için `--confirm-source-backup` zorunlu — bkz.
`app/Console/Commands/MigrateSqliteToPgsql.php` ve
`tests/Feature/MigrateSqliteToPgsqlCommandTest.php`. Tam prosedür:
`docs/DEPLOYMENT.md`.

## 7. Veritabanını sıfırlamak

Test sırasında veri kirlenirse (yarım kalmış post/rapor vb.):

```bash
cd backend
php artisan migrate:fresh --seed
```

Bu, `sql/database.sqlite`'ı sıfırdan oluşturup gerçek seed veriyle
doldurur (bkz. `sql/README.md`).

## 8. Tam kontrol listesi (bir değişiklikten sonra)

- [ ] `php artisan test` (SQLite) geçiyor
- [ ] PostgreSQL varsa `php artisan test --configuration=phpunit.pgsql.xml`
- [ ] `flutter analyze` temiz
- [ ] `flutter test` geçiyor
- [ ] `dart run tool/verify_rest_backend.dart` 17/17 geçiyor (backend
      ayaktaysa)
- [ ] Değişiklik UI ile ilgiliyse: uygulamayı gerçekten çalıştırıp gözle
      kontrol et (bkz. §3) — sadece analyze/test yeterli değil
- [ ] `docs/EKSIKLER.md`'yi güncelle (yeni yapılan/kalan neyse)
