# Test Rehberi

Nerede, nasıl test edeceğini gösteren tek doküman. Üç katman var:
**frontend** (Flutter), **backend** (Laravel API), ve **uçtan uca**
(ikisi birlikte, gerçek HTTP üzerinden).

---

## 1. Frontend — statik kontrol (her zaman ilk adım)

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
php artisan serve --port=4000
```

Sağlık kontrolü: `curl http://localhost:4000/up` → `200` dönmeli.

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

17 gerçek kontrol yapar: profil, mekânlar, etkinlik+katılma, quest'ler,
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

# Android emülatör (10.0.2.2 = emülatörün host makineye takma adı)
flutter run --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://10.0.2.2:4000/api/v1

# Web (aynı makinede backend çalışıyorsa)
flutter run -d web-server --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://localhost:4000/api/v1
```

Bu modda admin panelinden eklenen bir etkinlik gerçekten öğrenci
tarafında da görünür (aynı SQLite satırını okuyorlar) — iki ayrı
sekme/cihaz açıp doğrulayabilirsin.

## 7. Veritabanını sıfırlamak

Test sırasında veri kirlenirse (yarım kalmış post/rapor vb.):

```bash
cd backend
php artisan migrate:fresh --seed
```

Bu, `sql/database.sqlite`'ı sıfırdan oluşturup gerçek seed veriyle
doldurur (bkz. `sql/README.md`).

## 8. Tam kontrol listesi (bir değişiklikten sonra)

- [ ] `flutter analyze` temiz
- [ ] `flutter test` geçiyor
- [ ] `dart run tool/verify_rest_backend.dart` 17/17 geçiyor (backend
      ayaktaysa)
- [ ] Değişiklik UI ile ilgiliyse: uygulamayı gerçekten çalıştırıp gözle
      kontrol et (bkz. §3) — sadece analyze/test yeterli değil
- [ ] `docs/EKSIKLER.md`'yi güncelle (yeni yapılan/kalan neyse)
