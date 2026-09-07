# AruSocial — Gerçek Ürün Dönüşümü: Repository Audit

## Hardening Final Update (2026-08-26)

- Durum: `REAL_PRODUCT_HARDENING_FINAL_PARTIAL`
- Dashboard + Stats tek experience (`/admin/stats` tek kaynak)
- Appointment cancel + slot status + PAST_SLOT / APPOINTMENT_ALREADY_EXISTS
- Map null-safe marker/heatmap filtering
- Backend tests: **393 passed**, routes: **151**
- Full manual browser DevTools sweep hâlâ açık (web-server boot OK)

## Hardening 3 Update (2026-08-26)

- Durum: `REAL_PRODUCT_HARDENING_3_PARTIAL`
- Route sayısı yeniden doğrulandı: **151**
- Sport / Service / Career apply UI akışları gerçek `participation` endpointine bağlandı.
- Career ekranında staff slot listesi + appointment booking endpoint entegrasyonu eklendi.
- Admin panele `Achievements` sekmesi eklendi (listele/oluştur/düzenle).
- Web smoke: `flutter run -d web-server --web-port 7357` başarıyla açıldı (HTTP 200), ancak tam manuel browser console sweep bu turda tamamlanmadı.

**Commit:** `6fa85b5` (21 Ağu 2026, "Revert: project deploy")
**Kapsam:** Salt okuma analizi. Bu turda **hiçbir kod değiştirilmedi.**
**Not:** 21 Ağustos'taki auth / RBAC / realtime / post_likes çalışması revert edildi. Bu rapor **revert sonrası gerçek ağaca** dayanır (`git ls-files` ile doğrulandı). Bazı arama indeksleri hâlâ silinmiş dosyaları gösteriyor; onlar yok sayıldı.

**Şu an ağaçta OLMAYAN (revert ile gitmiş) dosyalar:**
`backend/app/Http/Controllers/Api/AuthController.php` · `Api/RealtimeController.php` · `Api/Admin/UserController.php` · `Api/Admin/AcademicStaffController.php` · `Http/Controllers/ActivityFormController.php` · `Http/Middleware/EnsurePermission.php` · `Models/PostLike.php` · `Models/XpTransaction.php` · `Models/RealtimeEvent.php` · `Services/GranularPermissions.php` · `Services/XpLedger.php` · `Services/RealtimePublisher.php` · `frontend/lib/core/auth/session_store.dart` · `core/network/session_token_adapter.dart` · `core/services/realtime_sync.dart` · `core/services/admin_user_store.dart` · `post_likes` / `xp_transactions` / `academic_staff` / `realtime_events` migration'ları.

---

## 1. Mevcut Mimari Haritası

### 1.1 Fiziksel yapı

```
frontend/         Flutter (Android + Web; iOS iskelet)
  lib/app/        MaterialApp + _DemoSession (login state + rol)
  lib/features/   55 ekran dosyası (5 sekme + admin + sosyal alt kabuk)
  lib/core/
    services/     contracts.dart (CampusRepository), Mock + Rest impl,
                  + 14 adet SharedPreferences "store"
    network/      ApiClient, AuthTokenAdapter, campus_dtos
    auth/         MockAuthProvider, EntraAuthProvider, AppSettingsStore
backend/          Laravel 12/13
  routes/api.php  Tek grup: prefix v1 + [throttle:api, not-banned]
  app/Http/       32 controller, 1 middleware (EnsureNotBanned)
  app/Services/   ActivityLogger, AuditLogger, EmailService,
                  ModerationService, ImageModerationService
  app/Models/     37 model
  migrations/     46 dosya
sql/              database.sqlite (git'te yok) + schema.sql (BAYAT)
.github/          ci.yml (analyze+test+web build / phpunit)
```

### 1.2 Katman diyagramı — bugünkü gerçek

```
 Flutter Widget (StatefulWidget, setState)
        │  doğrudan çağrı, ayrı state layer YOK
        ▼
 CampusRepository  ────────────────┐
   ├── MockCampusRepository        │  (varsayılan, USE_REST_API=false)
   └── RestCampusRepository        │
             │                     │
             ▼                     │
        ApiClient                  │  authTokenAdapter alanı VAR
        (baseUrl + path)           │  ama main.dart BAĞLAMIYOR
             │                     │
             ▼                     │
 Laravel /api/v1  [throttle, not-banned]   ← auth:sanctum YOK
             │                     │
      ApiResponds::currentUser()   │  = User::firstOrFail()
             │                     │
             ▼                     │
        Eloquent Model             │
             │                     │
             ▼                     │
          SQLite                   │
                                   │
 ┌─────────────────────────────────┘
 │  BYPASS (repository'yi hiç kullanmayan yollar)
 ├── AdminContentStore   → Kulüp/Spor/Hizmet (yalnız Mock); yemek Rest'te repository
 ├── MediaLibraryStore   → Medya (yalnız Mock; Rest'te GET/POST /media)
 ├── PlacePhotoStore     → Mekân kapak işaretçisi (URL veya legacy dataUri)
 ├── SiteSettingsStore   → Entra / WordPress kimlik bilgileri
 ├── AuditLogStore       → Admin işlem logu YAZMA (cihazda)
 ├── AskArucadStore      → AI sohbet geçmişi
 ├── ProfileBioStore     → Sosyal profil bio
 └── AppSettingsStore    → Tercihler (meşru), + onboarding, kulüp üyeliği
```

### 1.3 Kritik gözlem

`CampusRepository` arayüzü yemek için `getFoodVenues` / `upsertFoodVenue` / `upsertFoodMenu` / `deleteFoodMenu` içerir. Rest modunda Explore, Guide ve Admin Yemek sekmesi `GET /food-venues` ve `/admin/food-venues*` yazar. Mock modunda aynı metotlar `AdminContentStore` (SharedPreferences) kullanır. Medya Rest'te `getMedia` / `uploadMedia` / `deleteMedia` ile mevcut `GET/POST /media` üzerinden gelir; Mock'ta `MediaLibraryStore` kalır.

---

## 2. Flutter → API → Laravel → DB Veri Akış Haritası

### 2.1 Gerçekten uçtan uca çalışan akış (örnek: check-in)

```
PlaceDetailScreen "Check-in Yap"
  → repository.checkIn(placeId, visibleToOthers)
  → ApiClient.post('/checkins')            [Authorization header YOK]
  → POST /api/v1/checkins
  → CheckinController::store
      currentUser() = User::first()        ← KİMLİK PROBLEMİ
      Checkin::create(user_id)
      $me->increment('places'), increment('xp', 10)   ← XP kuralı controller içinde
      ActivityLogger::log(...)
      visibleToOthers ? FeedPost::create(...) : —
  → checkins + users + activity_log + feed_posts tabloları
```

### 2.2 Yemek akışı (P2-1 tamamlandı)

```
ExploreScreen / GuideContext / Admin Yemek sekmesi
  → CampusRepository.getFoodVenues / upsertFoodVenue / upsertFoodMenu / deleteFoodMenu
      Rest  → GET /api/v1/food-venues + POST /api/v1/admin/food-venues*
            → food_venues + food_daily_menus
      Mock  → AdminContentStore (SharedPreferences) — yalnızca USE_REST_API=false
```

### 2.3 Ters akış (örnek: audit log)

```
YAZMA:  Admin panel → AuditLogStore.log()  → SharedPreferences (cihaz)
OKUMA:  Aktivite Kaydı sekmesi → repository.getAuditLog() → GET /admin/audit-log → admin_audit_log
```
REST modunda admin'in kendi yazdığı log **okuduğu yerde görünmez**. Sunucu tarafındaki kayıtlar ayrıca `AuditLogger::log()` ile controller'lardan yazılıyor → **iki ayrı, senkron olmayan audit kaydı**.

### 2.4 Medya akışı (P2-2 tamamlandı)

```
Admin Medya / blok editörü / mekân kapağı
  → CampusRepository.getMedia / uploadMedia / deleteMedia
      Rest  → GET/POST /api/v1/media (+ POST /media/{id}, POST /media/{id}/delete)
            → media_items + public disk (storage/app/public/media)
      Mock  → MediaLibraryStore (SharedPreferences dataUri) — yalnızca USE_REST_API=false
```

---

## 3. Local / Mock / Seed Veri Kullanan Bütün Noktalar

| # | Kaynak | Ne tutuyor | Kullanan ekran(lar) | REST modda da local mi? | Meşru mu |
|---|---|---|---|---|---|
| 1 | `AdminContentStore` | Yemek mekânları + menü (**yalnız Mock**) | Mock repo | Hayır (REST'te `GET /food-venues`) | Mock için evet |
| 2 | `AdminContentStore` | Kulüp/Spor/Hizmet (fallback) | Mock repo | Hayır (REST'te repo) | Kısmen |
| 3 | `MediaLibraryStore` | Medya (base64 dataUri, **yalnız Mock**) | Mock repo | Hayır (REST'te `GET /media`) | Mock için evet |
| 4 | `PlacePhotoStore` | Mekân kapak işaretçisi (URL veya legacy dataUri) | PlaceDetailScreen | Evet (işaretçi; binary REST'te `/media`) | Kısmen |
| 5 | `SiteSettingsStore` | Entra tenant/client/redirect, WP url/token | Admin Site Ayarları, `main.dart` | **Evet** | Hayır (kimlik bilgisi) |
| 6 | `AuditLogStore` | Admin işlem logu (yazma) | Tüm admin CRUD | **Evet** | Hayır |
| 7 | `AskArucadStore` | AI sohbet geçmişi | AskArucadScreen | **Evet** | Tartışmalı |
| 8 | `ProfileBioStore` | Sosyal profil bio | SocialProfileScreen | **Evet** | Hayır |
| 9 | `ChatStore` | Eski local mesaj deposu | `chat_screen.dart` import ediyor | Evet (ölü/gölge) | Hayır |
| 10 | `AppSettingsStore` | Dil, gizlilik, biyometri, "beni hatırla" | Her yer | Evet | **Evet** (tercih) |
| 11 | `AppSettingsStore` | Onboarding başlangıcı, kulüp katılımı | Profile, ClubDetail | Evet | Hayır (üyelik = veri) |
| 12 | `SavedPostsStore` / `SocialGraphStore` / `RoleAssignmentStore` / `BuildingDirectoryStore` / `AdminPageStore` / `ContentRevisionStore` / `DraftStore` | Mock repo'nun altyapısı | Mock modda | Hayır | Evet (mock impl) |
| 13 | `campus_life_config.dart`, `poi_config.dart`, `shuttle_config.dart`, `place_catalog.dart`, `tours_config.dart`, `onboarding_config.dart` | Hardcoded kampüs verisi | Explore, Harita, Shuttle | Evet | Kısmen (gerçek ARUCAD verisi, ama CMS'te değil) |
| 14 | `MockAuthProvider` | `ezgi.demir@arucad.edu.tr` / `Ez26m!r` + domain allowlist | Login | Evet | **Hayır** |
| 15 | `MockAnalyticsTracker` | `print()` | Her yer | Evet | Kabul edilebilir |

---

## 4. Ekran → Veri Kaynağı Matrisi

Kısaltmalar: **REST** = backend, **MOCK** = MockCampusRepository, **SP** = SharedPreferences, **CONST** = derlenmiş sabit, **CALC** = istemcide hesaplanıyor.

### 4.1 Home (`home_screen.dart`)

| Component | Field | Current Source | Intended | API | DB Table | Action |
|---|---|---|---|---|---|---|
| Yakınında | events + mesafe | REST/MOCK + CALC (Geolocator) | REST | `GET /events` | `events` | Konum istemcide kalsın, OK |
| Kampüs Nabzı | `density` | REST (`places.density` kolonu, statik) | REST hesaplanmış | `GET /places` | `places` | **Sayaç gerçek değil** — check-in'den türetilmeli |
| Kampüs Nabzı | online sayısı | CALC (deterministik tahmin) | REST | yok | — | Ya gerçek hesapla ya etiketi koru |
| Bugün | events | REST/MOCK | REST | `GET /events` | `events` | OK |
| Kampüste Şimdi | son 3 post | REST/MOCK | REST | `GET /feed` | `feed_posts` | OK |
| Sana Özel | ziyaret edilmemiş yerler | CALC (mesafe/aktivite) | REST öneri | — | — | Ya isimlendirmeyi düzelt ya sunucuda hesapla |
| Hizmet kısayolu | services | REST/MOCK | REST | `GET /services` | `services` | OK |
| Anket popup | surveys | REST/MOCK | REST | `GET /surveys/active` | `surveys` | OK |
| First 30 Days | ilerleme | **SP** | REST (user profili) | yok | — | Kullanıcıya bağlı veri, sunucuya taşınmalı |
| Skor chip | xp/level | REST `GET /me` | REST | `GET /me` | `users` | OK |

### 4.2 Keşfet (`explore_screen.dart`)

| Component | Field | Current | Intended | API | DB | Action |
|---|---|---|---|---|---|---|
| Yerler | places | REST/MOCK | REST | `GET /places` | `places` | OK |
| Kulüpler | clubs | REST/MOCK | REST | `GET /clubs` | `clubs` | OK |
| Spor | sports | REST/MOCK | REST | `GET /sports` | `sports` | OK |
| Hizmetler | services | REST/MOCK | REST | `GET /services` | `services` | OK |
| **Yemek** | food venues + menü | REST (`GET /food-venues`) / Mock (`AdminContentStore`) | REST | `GET /food-venues` | `food_venues`, `food_daily_menus` | **P2-1 tamam** |
| Kampüs/POI | koordinat, shuttle | **CONST** | REST (CMS) | yok | — | Uzun vade CMS |
| Kulüp katılım | üyelik | **SP** | REST | yok | `club_members` (yok) | **Şema eksik** |

### 4.3 Sosyal (`social_screen.dart`, `social_shell.dart`, `chat_screen.dart`)

| Component | Field | Current | Intended | API | DB | Action |
|---|---|---|---|---|---|---|
| Feed | posts | REST/MOCK | REST | `GET /feed` | `feed_posts` | OK |
| Post | `likedByMe` | REST ama **global kolon** | kullanıcıya özel | `POST /feed/{id}/like` | `feed_posts.liked_by_me` | **P1 kritik: `post_likes` tablosu** |
| Post | `likes` sayısı | REST (kolon) | REST (count) | — | — | Denormalize sayaç, ilişkiden türetilmeli |
| Yorum | comments | REST | REST | `POST /feed/{id}/comments` | `post_comments` | `author` **string**, `user_id` yok → **ownership eksik** |
| Hikâye | stories | REST | REST | `GET/POST /stories` | `stories` | `author_id` integer FK → `users.id`; `author_name` snapshot (rename eski hikâyeyi güncellemez) |
| Takip | following | REST ama **isimle** | user_id ile | `POST /social/follow` | `social_follows.followed_name` | **P1: user_id'ye çevir** |
| Engelle | blocked | REST (isimle) | user_id | `POST /social/block` | `social_blocks` | Aynı |
| Kaydet | saved | REST | REST | `POST /saved-posts/toggle` | `saved_posts` | OK |
| Mesaj | thread | REST ama **tek yönlü ayna** | conversation | `GET/POST /chat/{peer}/messages` | `chat_messages(user_id, peer_name, from_me)` | **P1 kritik: alıcı hiç satır almıyor** |
| Bildirim | inbox | REST | REST | `GET /notifications` | `notifications` | Takip bildirimi **yanlış kişiye** yazılıyor |
| Kişiler | roster | REST leaderboard | REST users | `GET /leaderboard` | `users` | Seed satırlar; gerçek hesap değil |
| Profil bio | bio | **SP** | REST | yok | — | **P2** |

### 4.4 Aktivite / Skor (`quests_screen.dart`)

| Component | Current | API | DB | Action |
|---|---|---|---|---|
| Yıl skoru / XP | REST `GET /me` | `users.xp` | `users` | XP tek toplam; **yıllık ledger yok** (revert edildi) |
| Görevler | REST | `GET /me/quests` | `quests` | `progress` statik kolon, gerçek olaydan türemiyor |
| Aktivite geçmişi | REST | `GET /me/activity` | `activity_log` | OK |
| Liderlik | REST | `GET /leaderboard` | `users` | `isMe` = `User::first()` ile karşılaştırma |
| Yıl filtresi | REST + CALC | `GET /academic-years` | `academic_years` | Filtre istemcide |

### 4.5 Ayarlar / Profil

| Component | Current | Action |
|---|---|---|
| Kullanıcı | REST `GET /me` | OK |
| Dil / gizlilik / biyometri | SP | **Meşru** |
| Görünürlük seviyesi | SP | Sosyal etkisi var → sunucuya |
| Gönderilerim | REST feed filtresi | `author_id` ile, OK |
| Galeri | yok | EKSİK |

### 4.6 Admin Panel (18 bölüm)

| Bölüm | Kaynak | API | Durum |
|---|---|---|---|
| Dashboard | REST + **SP (audit)** | karışık | **Hibrit** (yemek + medya REST) |
| İstatistikler | REST | `GET /admin/stats` | `totalXp = User::first()->xp` |
| Etkinlikler | REST | `POST /admin/events` | OK (yetki yok) |
| Bekleyen aktiviteler | REST | `/admin/events/pending` | OK |
| Yoklama | REST | `/admin/events/{id}/participants` | OK |
| Kulüp / Spor / Hizmet | REST | `/admin/...` | OK |
| **Yemek** | REST | `GET /food-venues`, `POST /admin/food-venues*` | **P2-1 tamam** |
| Bina Dizini / Sayfalar | REST | `/admin/directory`, `/admin/pages` | OK |
| **Medya** | REST | `GET /media`, `POST /media` (multipart), `POST /media/{id}`, `POST /media/{id}/delete` | **P2-2 tamam** |
| Anketler / Akademik Yıl | REST | `/admin/surveys`, `/admin/academic-years` | OK |
| E-posta günlüğü | REST | `/admin/email-logs` | OK |
| Kullanıcılar & Roller | REST (roller) | `/admin/roles` | **Kullanıcı CRUD/ban API yok** |
| Moderasyon | REST | `/admin/reports` | OK |
| Aktivite Kaydı | REST okuma / **SP yazma** | `/admin/audit-log` | **Çelişki** |
| **Site Ayarları** | **SP** (Entra/WP) + REST (moderasyon key) | kısmi | **Kopuk** |

---

## 5. Buton / Action Audit

| UI Action | Bugünkü davranış | Endpoint | Backend | DB mutasyonu | Sonuç | Realtime |
|---|---|---|---|---|---|---|
| Beğen | Global boolean toggle | `POST /feed/{id}/like` | `FeedController::like` — **kullanıcıyı hiç kullanmıyor** | `feed_posts.liked_by_me`, `likes` | **Herkes için değişir** | Polling |
| Yorum yap | Gerçek insert | `POST /feed/{id}/comments` | `comment` | `post_comments` (`author` string) | Gerçek | Polling |
| Gönderi paylaş | Gerçek + moderasyon | `POST /feed` | `store` | `feed_posts` | Gerçek | Polling |
| Hikâye ekle | Gerçek | `POST /stories` | `store` | `stories` | Gerçek | Static |
| Şikayet et | Gerçek | `POST /feed/{id}/report` | `report` | `moderation_reports` | Gerçek | Static |
| Takip et | Gerçek satır, **isimle** | `POST /social/follow` | `toggleFollow` | `social_follows` + `notifications` (**yanlış user_id**) | Yarım | Event gerekli |
| Engelle | Gerçek | `POST /social/block` | `toggleBlock` | `social_blocks` | Gerçek | Static |
| Kaydet | Gerçek | `POST /saved-posts/toggle` | `toggle` | `saved_posts` | Gerçek | Static |
| Mesaj gönder | REST insert + Reverb `message.created` | `POST /chat/{peer}/messages` | `send` | `messages` (shared conversation) | Karşı taraf REST + private channel | **Reverb** |
| Check-in | Gerçek + feed post | `POST /checkins` | `store` | `checkins`, `users`, `activity_log`, `feed_posts` | Gerçek | Polling |
| Etkinliğe katıl | Gerçek + 2 e-posta | `POST /events/{id}/join` | `join` (transaction, unique) | `event_joins`, `events.attendees`, `users.xp` | Gerçek | Polling |
| Formu tamamla | Gerçek kapı | `POST /events/{id}/join/form` | `submitForm` | `event_joins.form_submitted_at` | Gerçek | — |
| Kendi aktiviteni oluştur | Gerçek + slot çakışma | `POST /events/mine` | `createOwnActivity` | `events` (pending) | Gerçek | — |
| Yorum/anket oyu | Gerçek | `POST /surveys/{id}/vote` | `vote` | `survey_responses` | Gerçek | — |
| Değerlendirme yaz | Gerçek | `POST /places/{id}/reviews` | `addReview` | `reviews` | Gerçek | — |
| Fotoğraf moderasyonu | Gerçek sunucu taraması | `POST /moderation/check-image` | `checkImage` | `users.strikes/banned_at` | Gerçek (key varsa) | — |
| **Yemek menüsü kaydet (admin)** | Gerçek | `POST /admin/food-venues/{id}/menus` | `upsertFoodMenu` | `food_daily_menus` | Gerçek (Rest) | `food.manage` |
| **Medya yükle (admin)** | Gerçek | `POST /media` (multipart) | `store` | `media_items` + public disk | Gerçek (Rest) | `media.manage` |
| **Site ayarı kaydet (admin)** | **Sadece cihaz** | kısmi | kısmi | kısmi | **Sahte kalıcılık** | — |
| Etkinlik oluştur/sil (admin) | Gerçek | `POST /admin/events` | `upsert` | `events` | Gerçek | **Yetki yok** |
| Katılımcı onayla | Gerçek | `/admin/.../approve` | `approveParticipant` | `event_joins.approved_at` | Gerçek | **Yetki yok** |
| Şikayet çöz | Gerçek | `/admin/reports/{id}/resolve` | `resolve` | `moderation_reports.action` | Gerçek | **Yetki yok** |
| Toplu e-posta | Gerçek | `/admin/email/bulk` | `bulk` | `email_logs` | Gerçek (MAIL=log) | **Yetki yok** |
| Rol ata | Gerçek | `POST /admin/roles` | `upsert` | `role_assignments` | Gerçek | **Yetki yok** |
| **Kullanıcı banla (admin)** | **Yok** | — | — | — | Sadece otomatik strike | — |
| Kulübe katıl | **SP toggle** | yok | yok | yok | **Sahte** | — |
| Çıkış yap | Sadece local state | yok | yok | yok | Token olmadığı için sorunsuz | — |

**Özet:** 26 önemli action'ın **3'ü tamamen sahte** (site ayarı, kulüp katılımı, kulüp üyeliği), **yemek ve medya Rest'te gerçek (P2-1 / P2-2)**, **2'si veri modeli yüzünden yanlış** (beğeni, mesaj), **7'si yetkisiz erişime açık** (tüm admin yazma — P1 yetki katmanı ayrıca ele alındı).

---

## 6. Authentication Akışı (mevcut)

```
LoginScreen (e-posta + şifre)
   → MockAuthProvider.signInWithCredentials()
        admin: ezgi.demir@arucad.edu.tr / Ez26m!r  (hardcoded)
        diğer: *@arucad.edu.tr + boş olmayan herhangi bir şifre  ← ŞİFRE DOĞRULANMIYOR
   → true
   → repository.getMe()          → GET /me → User::first()
   → repository.roleFor(email)   → GET /admin/roles/{email}
   → setState(signedIn = true)
```

**Tespitler**

1. **Sunucuya kimlik hiç gitmiyor.** Giriş tamamen istemci kararı.
2. `AuthProvider.getAccessToken()` arayüzde var; `ApiClient.authTokenAdapter` alanı var; `main.dart` **bağlamıyor** → hiçbir istekte `Authorization` header yok.
3. `routes/api.php` grubunda `auth:sanctum` **yok**. `personal_access_tokens` tablosu var, kullanılmıyor.
4. `bootstrap/app.php` sadece `not-banned` alias'ı tanımlıyor.
5. Entra: kod hazır (`EntraAuthProvider`, PKCE), config **cihazda**; token backend'de **doğrulanmıyor**.
6. Web `/admin` girişi: `startInAdminMode` + istemci rol kontrolü → **sadece UI kapısı**.

---

## 7. Authorization Akışı (mevcut)

| Katman | Var mı | Not |
|---|---|---|
| Rol verisi | ✅ | `role_assignments` (email → role, permissions kolonu yok) |
| Rol çözümleme | ✅ | Girişte `GET /admin/roles/{email}` |
| Frontend gizleme | ✅ | `UserRole.canManageContent` / `canModerate` |
| **Backend route koruması** | ❌ | `/admin/*` dahil hiçbir route rol kontrol etmiyor |
| Policy / Gate | ❌ | Yok |
| Ownership kontrolü | ❌ | Post silme/düzenleme yok; yorum sahipliği yok |
| Ban zorlaması | 🔧 | `EnsureNotBanned` var ama `User::first()` ve `api/v1/admin/*` muaf |

**Sonuç:** `curl -X POST http://host:4000/api/v1/admin/events` → kimlik doğrulaması olmadan çalışır.

---

## 8. Kullanıcı İzolasyonu Problemleri (öncelik sırasıyla)

| # | Problem | Dosya | Etki |
|---|---|---|---|
| 1 | `currentUser()` = `User::firstOrFail()` | `Api/Concerns/ApiResponds.php:41` | **Tüm yazma işlemleri tek satıra** |
| 2 | `feed_posts.liked_by_me` global boolean | `FeedController::like`, migration `115143` | A beğenince B de beğenmiş görünür |
| 3 | Mesajlar tek yönlü ayna | `ChatController`, migration `123607` | Alıcı mesajı **hiç görmez** |
| 4 | Takip ilişkisi isimle | `social_follows.followed_name` | Ad değişimi/çakışma; ters yön yok |
| 5 | Takip bildirimi takip **edene** yazılıyor | `SocialGraphController:55` | Yanlış kutu |
| 6 | `post_comments.author` string | migration `115144` | Yorum sahibi silinemez/doğrulanamaz |
| 7 | `EnsureNotBanned` `User::first()` | middleware:30 | Bir kişi banlanınca **herkes** banlanır |
| 8 | `StatsController` `User::first()->xp` | `Admin/StatsController:61` | Toplam XP yanlış |
| 9 | `LeaderboardController.isMe` | `:20` | Her zaman aynı kişi |
| 10 | XP kuralları controller içinde dağınık | Checkin +10, Event `$event->xp` | Ledger yok, yıllık sıfırlama yok |

---

## 9. Realtime Kararları

| Alan | Bugün | Karar | Gerekçe |
|---|---|---|---|
| Mesajlaşma | REST geçmiş + Reverb private channel (`message.created`) | **REALTIME** (P3-1) | History REST; WS yalnız teslimat |
| Bildirimler | Ekran açılışında GET | **POLLING** (30–60 sn) → sonra push | Anlık olması şart değil |
| Feed / beğeni / yorum | Ekran açılışında GET | **REQUEST/REFRESH** + pull-to-refresh | Instagram bile canlı değil |
| Kampüs Nabzı / yoğunluk | Statik kolon | **POLLING** (60 sn) + sunucu hesabı | "Canlı" iddiası var |
| Online presence | Deterministik tahmin | **STATIC** (etiketli tahmin) veya kaldır | Gerçek presence altyapısı maliyetli |
| Etkinlik katılımcı sayısı | Sayfa yenilenince | **REQUEST/REFRESH** | Yeterli |
| Admin moderasyon kuyruğu | Manuel | **POLLING** (60 sn) | Admin masaüstünde |
| Check-in görünürlüğü | Feed'e yazıyor | **REQUEST/REFRESH** | OK |
| Push (cihaz kapalıyken) | FCM job + mevcut `POST /push-tokens` | **FCM** (P3-2) | Reverb açık uygulamada; FCM arka plan |

**Kural:** Chat realtime `ChatRealtimeService` içinde yönetilir; widget'lar `Stream` dinler, ham WebSocket protokolü görmez. App-wide event bus yoktur.

---

## 10. Migration → Model → API Eşleşmesi

### 10.1 Tam eşleşen (sorunsuz)

`places` · `events` · `event_participation_types` · `event_joins` · `quests` · `stories` · `reviews` · `checkins` · `activity_log` · `moderation_reports` · `clubs` · `sports` · `services` · `directory_entries` · `admin_pages` · `content_revisions` · `drafts` · `surveys` · `survey_options` · `survey_responses` · `academic_years` · `email_logs` · `admin_audit_log` · `app_settings` · `saved_posts` · `social_blocks` · `notifications` · `role_assignments`

### 10.2 Model var, API var, **Flutter kullanmıyor** (orphan)

| Tablo | Model | Endpoint | Durum |
|---|---|---|---|
| `food_venues` | `FoodVenue` | `GET /food-venues`, `POST /admin/food-venues` | **Flutter Rest kullanıyor (P2-1)** |
| `food_daily_menus` | `FoodDailyMenu` | `.../menus`, `.../menus/{date}/delete` | **Flutter Rest kullanıyor (P2-1)** |
| `media_items` | `MediaItem` | `GET/POST /media`, `/media/{id}` | **Kullanılmıyor** (Flutter base64 kullanıyor) |
| `push_tokens` | `PushToken` | `POST /push-tokens` | Kayıt + FCM gönderimi (P3-2; boş `FIREBASE_*` → no-op) |
| `personal_access_tokens` | (Sanctum) | — | **Hiç kullanılmıyor** |

### 10.3 Şema düzeyinde hatalı

| Tablo | Alan | Problem | Çözüm |
|---|---|---|---|
| `feed_posts` | `liked_by_me` | Kullanıcıya bağlı state ana tabloda | `post_likes(user_id, post_id)` + unique |
| `feed_posts` | `likes` | Denormalize sayaç | İlişkiden `withCount` ya da tutarlı güncelleme |
| `chat_messages` | `peer_name`, `from_me` | Konuşma modeli yok | `conversations` + `conversation_participants` + `messages(sender_id)` |
| `social_follows` | `followed_name` | İsimle ilişki | `followed_user_id` FK |
| `social_blocks` | `blocked_name` | Aynı | `blocked_user_id` FK |
| `post_comments` | `author` (string) | Ownership yoktu | **Yapıldı:** `user_id` FK; `author` response relation'dan |
| `feed_posts`, `stories` | `author_id` integer FK | Ownership `users.id`; isim snapshot | **Yapıldı.** Rename `name` / `author_name` alanlarını güncellemez |
| Tüm tablolar | `updated_at` çoğunda yok | Audit/sync zor | `timestamps()` |
| — | `club_members` | **Tablo yok** | Kulüp üyeliği için gerekli |
| — | `xp_transactions` | Revert edildi | Yıllık XP için gerekli |

### 10.4 API Contract ↔ Gerçek Route farkı

| `docs/API_CONTRACT.md` (P2-5 sonrası) | Gerçek |
|---|---|
| 108 `METHOD /api/v1/...` envanteri | `php artisan route:list --path=api` ile aynı (HEAD hariç) |
| Hayali `POST /memories`, `GET /map`, `POST /routes` | **Dokümanda yok** |
| `GET /api/v1/admin/audit-log` salt okuma | **Doğru** — yazma ucu yok |
| `GET/POST /api/v1/admin/settings/site` | **Doğru** — `users.manage` |
| Food / Media path’leri | **Doğru** — `POST …/delete`, multipart media |
| Permission anahtarları | `GranularPermissions::KEYS` ile aynı |
| Pagination | Yok (sabit tavan: audit 200, notifications 100, email-logs 200) |
| User CRUD / academic-staff / realtime HTTP | **Yok** (controller diskte olsa da route yok) |

---

## 11. Kritik Riskler

| # | Risk | Şiddet | Şu anki durum |
|---|---|---|---|
| R1 | Kimliksiz API: herkes admin ucu çağırabilir | **Kritik** | Backend internete açılırsa veri silinebilir |
| R2 | Tek kullanıcı: tüm yazmalar aynı satıra | **Kritik** | Çok kullanıcılı ürün imkânsız |
| R3 | Mesaj alıcıya ulaşmıyor | **Kritik** | Sohbet özelliği yanıltıcı |
| R4 | Beğeni global | **Yüksek** | Sosyal veri yanlış |
| R5 | Admin değişikliği cihazda kalıyor (medya/ayar; yemek P2-1 ile REST) | **Yüksek** | Öğrenci medya/ayar görmez |
| R6 | Web istemci CORS + `10.0.2.2` yüzünden bağlanamıyor | **Yüksek** | Demo düşer |
| R7 | Ban tek kullanıcıya bağlı → herkesi banlar | **Yüksek** | Kilitlenme |
| R8 | `schema.sql` bayat | Orta | Yanlış şema okuması |
| R9 | Entra/WP kimlik bilgileri `SharedPreferences`'ta | Orta | Cihazda düz metin |
| R10 | Release APK debug key ile imzalı, paket adı `com.example.*` | Orta | Mağazaya çıkamaz |
| R11 | Test kapsamı ince (7 backend + 2 frontend test dosyası) | Orta | Regresyon koruması zayıf |
| R12 | `admin_panel_screen.dart` tek dosyada ~3700+ satır | Düşük | Bakım maliyeti (dokunmuyoruz) |
| R13 | Ödünç veri: `AuditLogStore` yaz / `getAuditLog` oku | Düşük | Log kaybı |

---

## 12. Teknik Görev Listesi (P0 / P1 / P2)

Her görev **tek milestone**, sonunda test + analyze + smoke.

### P0 — Bağlantı ve teşhis (hiçbir iş mantığı değişmez)

| ID | Görev | Dokunulacak dosyalar | Migration | Frontend kırma riski |
|---|---|---|---|---|
| P0-1 | Platforma göre `API_BASE_URL` varsayılanı (web/masaüstü `localhost`, Android emülatör `10.0.2.2`, define önceliği korunur) | `frontend/lib/main.dart` | Hayır | **Düşük** — sadece varsayılan |
| P0-2 | CORS: localhost/127.0.0.1 tüm portlar (pattern), `CORS_ALLOWED_ORIGINS` env korunur | `backend/config/cors.php`, `.env.example` | Hayır | Yok |
| P0-3 | `GET /api/v1` + `/api/v1/health` teşhis ucu (auth'suz, zarf uyumlu) | `backend/routes/api.php`, yeni `HealthController` | Hayır | Yok |
| P0-4 | `USE_REST_API` varsayılanının netleştirilmesi + dokümantasyon | `main.dart`, `backend/README.md`, `docs/TESTING.md` | Hayır | **Orta** — varsayılan değişirse mock demo bozulur, ayrı karar |
| P0-5 | Smoke doğrulama: `verify_rest_backend.dart` çalıştır, kırıkları raporla | — (salt çalıştırma) | Hayır | Yok |

### P1 — Kimlik, yetki, izolasyon (ürün eşiği)

| ID | Görev | Dosyalar | Migration | Risk |
|---|---|---|---|---|
| P1-1 | `POST /auth/session` (+`/auth/logout`): e-posta domain allowlist, `User::firstOrCreate`, Sanctum token | yeni `Api/AuthController.php`, `routes/api.php` | Hayır (`personal_access_tokens` var) | Düşük (yeni uç) |
| P1-2 | `currentUser()` → `request()->user()`; route grubuna `auth:sanctum`; 401 zarfı | `ApiResponds.php`, `routes/api.php`, `bootstrap/app.php` | Hayır | **Yüksek** — token gönderilmezse tüm REST çağrıları 401. P1-3 ile birlikte teslim edilmeli |
| P1-3 | Flutter oturum: `SessionStore` (token) + `SessionTokenAdapter` + `main.dart`'ta `ApiClient(authTokenAdapter:)`; login akışında `startSession` | `main.dart`, `app/app.dart`, yeni `core/auth/session_store.dart`, `core/network/session_token_adapter.dart`, `contracts.dart`, `rest_campus_repository.dart`, `mock_campus_repository.dart` | Hayır | **Yüksek** — login akışı; mock modun bozulmaması şart |
| P1-4 | `EnsureNotBanned` → `$request->user()`; admin muafiyeti yerine rol kontrolü | `EnsureNotBanned.php` | Hayır | Orta |
| P1-5 | Permission middleware + `/admin/*` koruması | yeni `EnsurePermission.php`, `bootstrap/app.php`, `routes/api.php` | **Evet** (`role_assignments.permissions`) | Orta — admin panel 403 alabilir |
| P1-6 | Gerçek beğeni ilişkisi | yeni migration `post_likes`, `feed_posts.liked_by_me` drop, `FeedController`, `FeedPost` modeli, `campus_dtos.dart` | **Evet** | Orta — feed DTO alanı aynı kalırsa frontend değişmez |
| P1-7 | Konuşma modeli: `conversations` + `messages(sender_id)`; `/chat/*` uçları aynı isimle kalır | yeni migration'lar, `ChatController`, `ChatMessage` modeli | **Evet** | Orta — endpoint imzası korunursa Flutter değişmez |
| P1-8 | Takip/engelleme `user_id` FK'ye taşınır (isim alanı geçiş süresince korunur) | migration, `SocialGraphController` | **Evet** | Orta |
| P1-9 | Bildirim hedefi düzeltmesi (takip edilen kişiye) | `SocialGraphController` | Hayır | Düşük |
| P1-10 | İki kullanıcı entegrasyon testi (§20 senaryosu) | yeni `backend/tests/Feature/MultiUserIsolationTest.php` | Hayır | Yok |

### P2 — Local ada temizliği ve sözleşme senkronu

| ID | Görev | Dosyalar | Migration | Risk |
|---|---|---|---|---|
| P2-1 | **TAMAMLANDI** — Yemek: `CampusRepository.getFoodVenues/upsertFoodVenue/upsertFoodMenu/deleteFoodMenu`; Rest → mevcut `/food-venues*` uçları; Mock → `AdminContentStore`; Explore/Guide/Admin repository kullanır | `contracts.dart`, `rest_campus_repository.dart`, `mock_campus_repository.dart`, `admin_panel_screen.dart`, `explore_screen.dart`, `guide_context.dart`, `FoodVenueController` (tarih `Y-m-d`, menü upsert id korunur) | Hayır (tablolar vardı) | Local SP yemek satırları Rest'e migrate edilmedi (aşağıdaki not) |
| P2-2 | **TAMAMLANDI** — Medya: `CampusRepository.getMedia/uploadMedia/renameMedia/deleteMedia/markMediaUsed`; Rest → mevcut `/media*` uçları (multipart upload); Mock → `MediaLibraryStore`; Admin/blok/kapak repository kullanır | `api_client.dart`, `contracts.dart`, `rest_campus_repository.dart`, `mock_campus_repository.dart`, `media_library_screen.dart`, `content_block_editor.dart`, `place_detail_screen.dart` | Hayır | Local SP base64 medya Rest'e migrate edilmedi (aşağıdaki not) |
| P2-3 | **TAMAMLANDI** — Site ayarları (Entra public + WP URL) sunucuya; WP token write-only; Rest `CampusRepository.getSiteSettings/updateSiteSettings`; Mock `SiteSettingsStore` | `Admin/SettingsController`, `routes/api.php` (`GET/POST /admin/settings/site`, `users.manage`), `contracts.dart`, `rest_campus_repository.dart`, `mock_campus_repository.dart`, `admin_panel_screen.dart`, `main.dart` (REST’te local Entra okunmaz) | Hayır (`app_settings` var) | Local SP secret otomatik yüklenmedi (aşağıdaki not) |
| P2-4 | **TAMAMLANDI** — Audit log tek kaynak: REST admin yazmaları sunucu `admin_audit_log`; Flutter yalnızca `GET /admin/audit-log` okur; `AuditLogStore` REST'te yazmaz/okumaz | `AuditLogger::logAsCurrentUser`, admin controller'lar, `admin_panel_screen.dart`, `app.dart`, `audit_log_store.dart` | Hayır | Düşük |
| P2-5 | **TAMAMLANDI** — `docs/API_CONTRACT.md` canlı `routes/api.php` + `route:list --path=api` (108 uç) ile yeniden yazıldı; hayali uçlar kaldırıldı; permission / request / response kodla hizalandı | `docs/API_CONTRACT.md`, `backend/tests/Feature/ApiContractInventoryTest.php` | Hayır | Yok |
| P2-6 | **TAMAMLANDI** — `sql/schema.sql` izole SQLite migrate dump (`backend/scripts/dump_schema.php`); `SchemaInventoryTest` kritik tablo/kolon/FK kilitler | `sql/schema.sql`, `sql/README.md`, `backend/scripts/dump_schema.php`, `SchemaInventoryTest` | Hayır | Düşük — `chat_messages` conversion drop etmez; `club_members` yok |
| P2-7 | **TAMAMLANDI** — Yazma uçlarının şekil validasyonu FormRequest'e taşındı; `error.code=VALIDATION` zarfı ve mevcut 400/422 korundu; permission middleware duruyor | `app/Http/Requests/*`, ilgili controller'lar, `FormRequestWriteValidationTest` | Hayır | Düşük — self-follow / ambiguous peer / occupancy query hâlâ controller'da |
| P2-8 | **TAMAMLANDI** — Liste uçlarına pagination (feed, notifications, audit-log, email-logs); Laravel `paginate()`; `page`/`perPage` (default 20, max 50); `meta.pagination` camelCase; Flutter `PageSlice` + load more/refresh | `PaginatedListRequest`, `ApiResponds::okPage`, dört controller, `page_slice.dart`, Rest/Mock repository, social/notifications/admin UI | Hayır | Düşük — diğer listeler hâlâ cap/full dump |

**P2-1 legacy not:** SharedPreferences yemek JSON'u (`admin.content.foodVenues.v1`) Rest DB'ye otomatik taşınmadı. Rest seed `food-the-garden` (The Garden + günün menüsü); Mock first-run seed `garden` (The Garden, menüsüz). Cihazda admin'in oluşturduğu ekstra mekânlar Rest'te görünmez; admin panelden yeniden oluşturulur. İki kaynağın id'leri bilinçli olarak farklı — birleştirme yok.

**P2-2 legacy not:** SharedPreferences medya JSON'u (`admin.media.items.v1`) Rest DB'ye otomatik taşınmadı. Mock first-run boş kütüphane (kullanıcı yükler). Rest'te dosyalar `storage/app/public/media` + `media_items` satırıdır; UI `url` ile `Image.network` kullanır. Cihazda daha önce kaydedilmiş base64 satırlar Rest'te görünmez; admin panelden yeniden yüklenir. Silinmedi — yalnızca canonical kaynak değişti.

**P2-3 legacy not:** SharedPreferences site ayarları (`site.entra.*`, `site.wordpress.*`) Rest `app_settings`'e otomatik taşınmadı — özellikle `site.wordpress.apiToken` cihazdan sunucuya yüklenmez. REST canonical kaynak `GET/POST /admin/settings/site`. REST path bu prefs anahtarlarını okumaz/yazmaz. Mock (`USE_REST_API=false`) hâlâ `SiteSettingsStore` kullanır. REST admin'in Entra public alanlarını ve WP URL/token'ı panelden yeniden girmesi gerekir. WordPress form/entry çekme hâlâ cihazdan (`WordPressDataSource`); token GET ile dönmediği için çekme denemesi yazılı alana yeni token girmeyi ister. Entra login REST'te Sanctum'dur; native Entra akışı yeniden yazılmadı.

**P2-4 legacy not:** SharedPreferences audit JSON'u (`admin.audit.log.v1`) Rest `admin_audit_log`'a otomatik taşınmadı — cihaz geçmişi güvenilir actor/shared history sayılmaz. REST canonical kaynak `GET /admin/audit-log`. REST path bu prefs anahtarını okumaz; `AuditLogStore.logIfMock` RestCampusRepository'de no-op. Cihazda duran satırlar silinmedi. Mock (`USE_REST_API=false`) hâlâ `AuditLogStore` yazar/okur. Login/logout sunucu audit'ine eklenmedi; REST'te local login satırı da yazılmaz.

**P2-7 legacy not:** FormRequest `authorize()` her zaman true — yetki `auth:sanctum` + `permission:*`. Başarısız şekil validasyonu mevcut zarfı korur (`data/meta/error`, kod `VALIDATION`, yazma uçlarında 400; oturum 422). Site ayarları POST bilinçli olarak Laravel 422 JSON'unda kalır (`$request->validate` davranışı). Self-follow/block, ambiguous peer, occupancy `date` query, invalid base64, anket tek-seçim kuralı controller'da. UserController / AcademicStaffController / RealtimeController route'suz, FormRequest yok. HTTP 201/204 kitlesel değiştirilmedi.

**P2-8 not:** Pagination Laravel `LengthAwarePaginator` (`paginate()`). Query `page` (min 1, default 1) ve `perPage` (1–50, default 20). `meta.pagination`: `currentPage`, `perPage`, `total`, `lastPage` (camelCase). Feed `created_at DESC, id DESC`; notifications aynı + `user_id` scope; audit `at DESC, id DESC` (200 cap kalktı); email-logs `sent_at DESC, id DESC` (200 cap kalktı). Email/notification listelerinde ekstra filter query yok. Flutter `PageSlice<T>` + `get*Page`; `getFeed`/`getAuditLog`/`getInboxNotifications`/`getEmailLogs` duruyor (REST'te default ilk sayfa). Mock in-memory slice. Hâlâ paginate edilmeyenler: `GET /me/activity` (100), revisions (20), stats, events/media/food/reports full dump.

**P2-6 legacy not:** `sql/schema.sql` artık canlı `database.sqlite` tinker dump'ı değil; `php backend/scripts/dump_schema.php` throwaway SQLite üzerinde `migrate` + `sqlite_master` snapshot'ı. Canlı DB'ye dokunulmadı. Conversion `chat_messages`'i drop etmez (legacy tablo snapshot'ta durur). `club_members` yoktur. `reviews.author` / `post_comments.author` / `feed_posts.liked_by_me` kolon değildir. Feed/story `author_id` → `users.id` CASCADE; review/comment `user_id` nullable FK (eşleşmeyen isim satırları null kalır). `likedByMe` API serializer alanıdır.

### P3

| ID | Görev | Durum |
|---|---|---|
| P3-1 | Chat REST + Reverb private channel (`conversation.{id}`, `user.{id}`), `message.created` REST şekli, Flutter `ChatRealtimeService`, mock in-process, REST fallback | **TAMAMLANDI** |
| P3-2 | FCM token kaydı (mevcut uçlar) + inbox satırı + queue job + cihaz teslimi; Reverb ön planda | **TAMAMLANDI** |
| P3-3 | PostgreSQL uyumluluğu + geçiş hazırlığı (aynı migration'lar SQLite+PG; production cutover yok) | **TAMAMLANDI** |
| P3-4 | Local / staging / production ortam ayrımı (config isolation; deploy yok) | **TAMAMLANDI** |
| P3-5 | Gerçek SMTP (`MAIL_MAILER=smtp`); local `log`; email_logs sent/failed | **TAMAMLANDI** |
| P3-6 | Sentry SDK ile error/performance monitoring (backend + Flutter) | **TAMAMLANDI** |
| P3-7 | CORS / trusted host / production HTTP hardening | **TAMAMLANDI** |
| P3-8 | PostgreSQL production cutover (mimari + data migration script + smoke planı) | **READY_FOR_CUTOVER** — gerçek prod sunucu yok |
| P3-9 | Gerçek SMTP production hesabı | **SMTP_ACCOUNT_REQUIRED** — kod zaten hazır (P3-5) |
| P3-10 | Gerçek Firebase/APNs production kurulumu | **BLOCKED_EXTERNAL_DEPENDENCY** — Firebase projesi yok, Apple hesabı yok |
| P3-11 | Gerçek Sentry organizasyon/DSN smoke | **BLOCKED_EXTERNAL_DEPENDENCY** — gerçek Sentry hesabı yok |
| P3-12 | Production deployment / provider / backup / HA planı | **PARTIAL** — prosedür + build/cache doğrulandı, hiçbir sağlayıcı seçilmedi (`docs/DEPLOYMENT.md`) |
| P3-13 | Android applicationId + release signing | **PARTIAL** — applicationId güncellendi + signing iskeleti hazır, `KEYSTORE_REQUIRED` |
| P3-14 | iOS Firebase/APNs + signing / store hazırlığı | **BLOCKED_EXTERNAL_DEPENDENCY** — Apple hesabı yok + bu makinede Xcode/macOS yok |

**P3-3 not:** Source of truth `backend/database/migrations/`. `sql/schema.sql` SQLite snapshot olarak kaldı (`dump_schema.php` değişmedi). Default local/test SQLite (`phpunit.xml` `:memory:`). PostgreSQL opt-in: `.env.example` yorum satırları + `phpunit.pgsql.xml` + CI `postgres:16` job. 108 HTTP route aynı; `docs/API_CONTRACT.md` değişmedi. Reverb/FCM/audit mimarisi yeniden yazılmadı. Legacy `chat_messages` drop edilmedi.

**P3-3 audit (uyumluluk):**
- Schema: `users.id` `$table->id()` → PG `bigint`; referencing `foreignId` / conversion `unsignedBigInteger` aynı tip. Boolean query'ler `true`/`false` (cast'ler mevcut). JSON Eloquent array cast; `json_extract` yok. Unique: `push_tokens(user_id,token)`, `food_daily_menus(food_venue_id,menu_date)`, `role_assignments.email`, `app_settings.key`, `conversations.pair_key`, `post_likes(user_id,post_id)`, social graph pair.
- Query düzeltmesi: `StatsController` checkins `byDay` `date(created_at) as day` + `GROUP BY day` — SQLite alias GROUP BY kabul eder, PostgreSQL etmez. `date(created_at)` SELECT + `groupByRaw`/`orderByRaw` (her iki diyalekt).
- Test: `SchemaInventoryTest` FK artık SQLite `PRAGMA` *veya* PG `pg_constraint`. `FeedStoryAuthorFkTest` / conversion `getColumnType` `int8`/`int4` kabul eder.
- Chat conversion `from_me`: PHP `(bool) 'f'` truthy; `filter_var` + `t`/`f` parse. Fresh migrate'de `chat_messages` boş, no-op.
- Raw SQL: uygulama kodunda `DB::raw` / `whereRaw` / `strftime` / `json_extract` / `ILIKE` yok. Kalan `selectRaw` aggregate'leri (`count(*)`, `date(created_at)`, `groupBy` gerçek kolonlar) dual-DB.
- Case sensitivity: email/username unique VARCHAR, ürün semantiği case-sensitive eşleşme (`User::where('email', $email)`). LIKE araması yok; citext eklenmedi.
- FK: ownership CASCADE (feed/story `author_id`, review/comment `user_id` nullable + CASCADE). `disableForeignKeyConstraints` PG'de `SET CONSTRAINTS ALL DEFERRED` (Laravel default FK'ler DEFERRABLE değil; conversion drop/rename yine de FK'sız ara kolon üzerinden).

**P3-3 remaining (production cutover — bu task dışı):**
- Gerçek prod PostgreSQL sunucusu / provider seçimi
- SQLite → PG data migration, zero-downtime, replication, backup, HA
- Bu makinede `phpunit.pgsql.xml` rolü (`arucad` / `arucad_test`) henüz yok; local PG 18 dinliyor ama test kullanıcısı oluşturulmadı. CI `postgres:16` servisi bu kimliği üretir. Credential denemesi yapılmadı.

**P3-5 not:** Delivery layer only. `EmailService` + 4 Mailable (`BulkAnnouncementMail`, katılım club/form/completed). `Mail::send` senkron (yeni mail queue yok). Local `MAIL_MAILER=log`. Production `smtp` + host/from/credentials yoksa `EnvironmentGuard` fail; staging `log` veya `smtp`. Status şeması `sent`/`failed`. SMTP şifresi email_logs/API/audit'te yok. 108 route aynı. Gerçek mailbox smoke yok (credential yok; phpunit `Mail::fake` / array).

**P3-4 not:** Environment boundary only (`docs/ENVIRONMENTS.md`). Laravel `APP_ENV=local|staging|production` + `EnvironmentGuard` (staging/prod: no SQLite, no debug, no localhost APP_URL/Reverb, no sync queue, no partial Firebase). Flutter `AppConfig.fromEnvironment()` (`APP_ENV`, `USE_REST_API`, `API_BASE_URL`, public Reverb, optional `SENTRY_DSN` / `FIREBASE_PROJECT_ID`). Debug `flutter run` hâlâ mock (`USE_REST_API` unset). Release `USE_REST_API` zorunlu — boş bırakılırsa mock APK sessizce çıkmaz. Cache/Redis/session prefix `APP_ENV` içerir. Media `MEDIA_URL` / `FILESYSTEM_MEDIA_DISK`. `.env.example` secret yok; staging/prod blokları yorum. 108 route aynı. Production deploy **yapılmadı**; CORS/host hardening P3-7'de.

**P3-7 not:** `config/cors.php`: `allowed_methods` gerçek 108-route method setine indirildi (`GET,HEAD,POST,DELETE,OPTIONS` — hiçbir route `PUT`/`PATCH` kullanmıyor), `allowed_headers` sadece `ApiClient`'ın gerçekten gönderdiği başlıklar (`Authorization`, `Content-Type`, `Accept`, `X-Requested-With`); `exposed_headers` boş kalır (`request_id` zaten `meta.request_id` gövdede). `allowed_origins_patterns` (loopback-any-port) yalnız `local`/`testing`'de aktif; staging/production yalnız `CORS_ALLOWED_ORIGINS`'te adı geçen origin'leri kabul eder. `EnvironmentGuard`'a yeni kontrol: staging/production'da `CORS_ALLOWED_ORIGINS` boşsa, `*` içeriyorsa veya localhost origin'i içeriyorsa boot fail eder (`app/Support/EnvironmentGuard.php`) — Fruitcake\Cors'un boş listede sessizce her origin'i kabul etme davranışına asla düşülmez. `bootstrap/app.php`: `$middleware->trustHosts()` (argümansız — `APP_URL`'nin host'u + subdomain'lerini otomatik kullanır, zaten `local`/`testing`'de kendiliğinden pasif) ve `TRUSTED_PROXIES` env'i (`App\Support\TrustedProxyList`) set edilmişse `$middleware->trustProxies(at: ...)`; boşsa hiçbir proxy güvenilmez (varsayılan davranış, local dev'i bozmaz). Sanctum bearer-token modeli değişmedi — `supports_credentials=false` kalır, `statefulApi()` hâlâ etkin değil. Reverb (ayrı websocket transport) ve public media URL'leri (`cors.php`'nin `paths`'i dışında) bu değişiklikten etkilenmez. `/up` framework health route'u CORS path'lerine dahil değil. Yeni testler: `CorsProductionTest`, `CorsPreflightTest`, `CorsCredentialsTest`, `HostTest`, `TrustedProxyListTest`, `SanctumRegressionTest`; mevcut `CorsApiTest`/`EnvironmentConfigTest`/`MailConfigTest` korunur. Backend 301/301 test yeşil; 108 route aynı, `docs/API_CONTRACT.md` değişmedi; yeni route/audit event eklenmedi.

**P3-8 → P3-14 not (FINAL P3 production/release mega-task):** Bu turda
gerçek production credential/hesap/sunucu erişimi **yoktu**; kural gereği
hiçbiri uydurulmadı. Aşağıda her madde için ne yapıldığı ve nerede
insan/hesap işlemi gerektiği var — ayrıntılı prosedürler
`docs/DEPLOYMENT.md` ve `docs/EXTERNAL_ACCOUNTS.md`'de.

- **P3-8 (READY_FOR_CUTOVER):** Aynı migration seti değişmedi;
  `php artisan migrate --force` (asla `migrate:fresh`) prod prosedürü
  `docs/DEPLOYMENT.md §3`'te doğrulandı (`config:cache`/`route:cache`/
  `view:cache` bu 108 route'ta test edildi — hepsi controller-based,
  closure yok, `route:cache` güvenli). Yeni:
  `App\Console\Commands\MigrateSqliteToPgsql`
  (`php artisan db:migrate-sqlite-to-pgsql`) — kaynak/şema **değiştirmez**,
  yalnız zaten migrate edilmiş bir PostgreSQL'e satır kopyalar; tablo sırası
  `Schema::getForeignKeys()` ile runtime'da FK grafiğinden topological
  sort edilir (elle sıralanmadı — migration setiyle asla çelişemez);
  `insertOrIgnore` ile idempotent; `--dry-run` yalnız sayım yapar; gerçek
  yazım `--confirm-source-backup` bayrağı olmadan reddedilir; PostgreSQL
  identity sequence'ları kopyadan sonra `setval` ile resetlenir; hedefte
  zaten var olan satırlar asla silinmez/güncellenmez. Legacy
  `chat_messages`: zaten var olan
  `2026_08_23_030001_convert_chat_messages_to_conversations.php`
  migration'ı bunu `conversations`/`messages`'a çeviriyor (aynı DB içinde,
  dialect-agnostic) — SQLite→PG geçişinde asıl taşınan veri zaten
  dönüştürülmüş `conversations`/`messages` tablolarıdır, `chat_messages`
  tablosunun kendisi değil. Testler: `MigrateSqliteToPgsqlCommandTest`
  (4 test — source/target driver validasyonu, backup-confirmation guard,
  gerçek şema üzerinde topological sort). Bu makinede `127.0.0.1:5432`'de
  dinleyen bir PostgreSQL **var** (önceki P3-3 oturumundan), ama kimlik
  bilgisi bilinmiyor — tahmin/deneme yapılmadı (güvenlik). ARUCAD ya bu
  sunucunun `arucad`/`arucad_test` rolünü kendi oluşturur (`docs/TESTING.md`
  §4'teki SQL) ya da gerçek bir prod sunucusu sağlar. Gerçek clean/staging
  PostgreSQL smoke bu yüzden **yapılamadı** — prosedür hazır, sunucu yok.
- **P3-9 (`SMTP_ACCOUNT_REQUIRED`):** Kod tarafı zaten P3-5'te tam;
  bu turda ek değişiklik gerekmedi. `MailSecretTest` SMTP şifresinin
  email_logs/API/audit'e hiç yazılmadığını zaten doğruluyor. Gerçek SMTP
  hesabı/kutu smoke testi yapılamadı (kimlik bilgisi yok).
- **P3-10 (`BLOCKED_EXTERNAL_DEPENDENCY`):** `HttpFcmClient`/
  `NullFcmClient`/`DeliverFcmNotification` (P3-2) değişmedi — zaten boş
  `FIREBASE_*` ile no-op, ikisi de eksiksiz-veya-hiç kuralını
  `EnvironmentGuard`'da koruyor. Gerçek Firebase projesi yok →
  `google-services.json`/`GoogleService-Info.plist` yok (gitignore'da
  zaten, hiç olmadı). Android paket adı P3-13 ile güncellendiği için
  Firebase Android app'i **final** paket adıyla (`com.arucad.arucadCampusPrototype`)
  oluşturulmalı. Gerçek cihaz push smoke'u (Android/iOS) bu nedenle
  yapılamadı.
- **P3-11 (`BLOCKED_EXTERNAL_DEPENDENCY`):** `config/sentry.php`,
  `SentryScrubber`, `AttachSentryContext` (P3-6) değişmedi. Gerçek Sentry
  organizasyonu/DSN yok → gerçek event capture smoke'u yapılamadı; mevcut
  `SentryExceptionTest`/`SentrySecretScrubTest`/Flutter
  `sentry_bootstrap_test.dart` sahte transport ile davranışı zaten
  doğruluyor (kontrollü exception → capture edilir, request_id/environment/
  release tag'lenir, secret'lar redakte edilir). Performance tracing hâlâ
  kapalı (`traces_sample_rate=null`), bu turda açılmadı.
- **P3-12 (`PARTIAL`):** Yeni `docs/DEPLOYMENT.md` — provider adayları
  (karar verilmedi), production `.env` şablonu, build/cache prosedürü
  (gerçekten çalıştırılıp doğrulandı: `route:cache`+`config:cache`+
  `view:cache` sonrası `php artisan test` yine 305/305), queue worker
  ihtiyacı (`DeliverFcmNotification` için `queue:work` + process manager),
  Reverb process planı, backup stratejisi (daily + pre-deploy + restore
  test), HA/replication gereksinimleri (`NOT_DEPLOYED`), rollback
  prosedürü (backup→deploy→migrate→smoke→rollback; migration `down()`
  metodları prod rollback için güvenilir kabul edilmedi, backup restore
  tercih edildi). Hiçbir gerçek sağlayıcı/sunucu/process manager
  kurulmadı — bu bir prosedür dokümanı, deploy değil.
- **P3-13 (`PARTIAL`, `KEYSTORE_REQUIRED`):** `frontend/android/app/build.gradle.kts`:
  `applicationId`/`namespace` `com.example.arucad_campus_prototype`'tan
  **`com.arucad.arucadCampusPrototype`**'a değiştirildi — bu yeni bir isim
  uydurmak değil, repoda zaten var olan iOS bundle id'siyle
  (`ios/Runner.xcodeproj/project.pbxproj`) birebir eşleştirmek; ARUCAD ile
  teyit edilmeden gerçek Play Console'a yüklenmemeli (bkz.
  `docs/EXTERNAL_ACCOUNTS.md` §7). Entra OAuth redirect scheme
  (`com.example.arucad_campus_prototype`) kasıtlı olarak **değiştirilmedi**
  — iOS'ta da bundle id'den ayrı tutuluyor, Azure tarafı ayrı bir karar.
  Release signing: `key.properties` varsa gerçek keystore ile imzalar,
  yoksa (bugün) debug anahtarına düşer — mevcut `flutter run --release`/CI
  davranışını bozmadı. `frontend/android/key.properties.example` eklendi
  (gerçek dosya zaten `.gitignore`'da). Doğrulama: `flutter build appbundle
  --release` (yeni applicationId + debug-fallback signing ile) gerçekten
  çalıştırıldı ve `app-release.aab` üretti. Gerçek production keystore yok
  → `KEYSTORE_REQUIRED`; gerçek imzalı AAB üretilmedi.
- **P3-14 (`BLOCKED_EXTERNAL_DEPENDENCY`):** iOS zaten gerçek bir bundle id
  kullanıyordu (`com.arucad.arucadCampusPrototype`, değiştirilmedi),
  `UIBackgroundModes: remote-notification` `Info.plist`'te ve
  `Runner.entitlements`'ta `aps-environment` zaten var (P3-2/P3-10
  iskeleti). Bu turda değişiklik yapılmadı çünkü: (1) gerçek Apple
  Developer hesabı yok → `APPLE_DEVELOPER_ACCOUNT_REQUIRED` (team/APNs
  key/provisioning hiçbiri oluşturulamaz), (2) bu makine Windows —
  Xcode/macOS yok, `flutter build ipa` fiziksel olarak çalıştırılamaz
  (kimlik bilgisi sorunu değil, platform sorunu). `CODE_SIGN_STYLE =
  Automatic` kalırken `DEVELOPMENT_TEAM` hiçbir yerde ayarlı değil —
  gerçek bir Mac + Apple hesabıyla Xcode'da tek seferlik ayarlanacak.
  Store metadata (koddan yönetilebilen kısım): app adı "AruSocial"
  (`Info.plist` `CFBundleDisplayName`, zaten doğru), bundle id
  `com.arucad.arucadCampusPrototype`, versiyon `pubspec.yaml`
  (`0.1.0+1` — ilk gerçek release öncesi ARUCAD ile teyit edilmeli),
  privacy/support URL ve açıklama metni bu repoda **placeholder olarak bile
  yok** (gerçek metin ARUCAD'den gelmeli) — App Store Connect/Play Console
  kaydı bu milestone'da yapılmadı, yapıldı gibi raporlanmadı.

**P3-6 not:** `sentry/sentry-laravel` mevcut exception pipeline'ına bağlandı (`SentryIntegration::handles($exceptions)`, `bootstrap/app.php`); Laravel'in kendi `report()`/render() davranışı, API zarfı (`data/meta/error`) ve `request_id` değişmedi. Local `SENTRY_DSN` boş → SDK no-op transport (gerçek network çağrısı yok). `AttachSentryContext` middleware `request_id`/route/`user.id`'yi Sentry scope'una API response'daki aynı `request_id` ile (`ApiResponds::requestId()`) tag'ler. `App\Support\SentryScrubber` (`config/sentry.php` `before_send`) request body alanlarını (password/token/apiKey/...) ve bilinen secret değerlerini (SMTP, Firebase private key, Reverb secret, WP token, moderation key) event'ten redakte eder — `SentrySecretScrubTest`. FCM job (`DeliverFcmNotification`) ve `EmailService` send/retry hataları ek olarak `\Sentry\captureException` ile raporlanır, mevcut swallow/retry semantiği değişmedi (`SentryQueueTest`). Performance tracing kapalı (`traces_sample_rate`/`profiles_sample_rate` null). Flutter tarafında `sentry_flutter` `main()`'in en başında `SentryFlutter.init(..., appRunner: ...)` ile sarmalanır (`SentryBootstrapOptions`, `SENTRY_DSN` --dart-define, `AppConfig` henüz kurulmadan) — kendi `FlutterError.onError`/`PlatformDispatcher.onError`/`runZonedGuarded` eklenmedi (SDK'nın appRunner'ı bunları zaten kurar; duplicate event yok). `sendDefaultPii=false` her iki tarafta da. Backend 270/270, Flutter 69/69 test yeşil; 108 route aynı; yeni public route yok; audit semantics değişmedi. Gerçek Sentry projesi/dashboard smoke yapılmadı (organization/DSN yok) — fake transport (backend) ve saf `SentryBootstrapOptions` testleri (Flutter) ile doğrulandı.

### P3.5 — Domain Cleanup / P4 Öncesi Temizlik

External hesap beklemeden yapılabilecek, P0-P3'ten kalan local-state/data-model borçlarını kapatan ayrı bir milestone. P3-8→P3-14'ten (external account setup) tamamen bağımsız.

| ID | Görev | Durum |
|---|---|---|
| DC-1 | `club_members` gerçek kulüp üyeliği (SharedPreferences → REST) | **TAMAMLANDI** |
| DC-2 | Profil bio → REST | **TAMAMLANDI** |
| DC-3 | First 30 Days → server-side | **TAMAMLANDI** |
| DC-4 | Görünürlük seviyesi → server-side | **TAMAMLANDI** |
| DC-5 | Place photo pointer → backend | **TAMAMLANDI** |
| DC-6 | `places.density` → check-in-derived | **TAMAMLANDI** |
| DC-7 | `places.rating` → review-derived | **TAMAMLANDI** |
| DC-8 | `quests.progress` → event-derived | **TAMAMLANDI** |
| DC-9 | Yıllık XP / ledger product rule | **TAMAMLANDI** |
| DC-10 | "Sana Özel" semantics | **TAMAMLANDI** |
| DC-11 | "Kampüs Nabzı / online" semantics | **TAMAMLANDI** |
| DC-12 | Hardcoded kampüs POI/shuttle/tours CMS kararı | **TAMAMLANDI** |

**DC-2 not:** `department`/`year`/`university`/`clubs`/`achievements`/`projects` (Sosyal sekmesi "Profili Düzenle" formu) daha önce yalnızca client `ProfileBioStore` SharedPreferences'ında (`profile.bio_edits.v1`) tutuluyordu — hem REST hem Mock modda, `SocialProfileScreen` bu store'u repository'yi bypass ederek doğrudan okuyup yazıyordu; REST modda backend bu altı alanı hiç bilmiyordu (ikinci cihaz/reinstall'da veri kaybı). Yeni migration `users` tablosuna altı nullable kolon ekliyor (`clubs`/`achievements`/`projects` JSON — **`club_members`'la ilgisi yok**, öğrencinin serbest metin yazdığı bir gösterim listesi). `User::toApiArray()` (hem `/me` hem login response'u besliyor) artık bu alanları döndürüyor. Yeni `POST /me/profile` (`ProfileController::updateBio`, `UpdateProfileBioRequest`) — `currentUser()`'a scope'lu, kısmi güncelleme (gönderilmeyen alan dokunulmadan kalır); rota sayısı 111 → **112**. `CampusRepository.updateProfileBio(...)` yeni sözleşme metodu: Rest `POST /me/profile`'a yalnız verilen alanları gönderir; Mock hâlâ `ProfileBioStore`'a yazar ama artık overlay mantığı `SocialProfileScreen`'den `MockCampusRepository.getMe()`'ye taşındı — ekran artık mod farkı gözetmeden `repository.getMe()`/`updateProfileBio()` çağırıyor, `ProfileBioStore`'u doğrudan hiç görmüyor. `CampusUserDto` (`campus_dtos.dart`) altı alanı da parse ediyor. Bir alanı gerçekten boşaltma (temizleme) UI'da hâlâ mümkün değil — boş text field `null` üretiyor ve hem Mock (`copyWith`'in `??` davranışı) hem Rest (`if (value != null)` filtresi) bunu "dokunma" olarak yorumluyor; bu **öncesinde de aynıydı** (regresyon değil), kapsam dışı bırakıldı. Testler: backend `ProfileBioTest` (6 test — default/update/partial-update/isolation/validation/401), Flutter `profile_bio_test.dart` (6 test — Mock + Rest). Backend 321/321, Flutter 81/81 yeşil.

**DC-1 not:** Kulüp üyeliği daha önce yalnızca cihaz-local `AppSettingsStore.joinedClubs()`'taydı (`settings.clubs.joined`, SharedPreferences) — paylaşılan bir roster hiç yoktu, iki öğrencinin cihazı birbirinden habersiz "katıldım" diyebiliyordu. Yeni `club_members` tablosu (`user_id` + `club_id`, unique çift, ikisi de `cascadeOnDelete`) `saved_posts`'un aynı pivot şeklini taklit eder. Yeni `ClubMemberController` (`index`/`join`/`leave`) — join/leave **toggle değil**, ayrı idempotent aksiyonlar (`PostLike`/`SavedPost`'un "tekrar tıkla → geri al" davranışından farklı: kulüpte tekrar "Katıl"a basmak asla üyelikten çıkarmaz — testler bunu doğrular). Yeni route'lar: `POST /clubs/{id}/join`, `POST /clubs/{id}/leave`, `GET /club-memberships` — `currentUser()`'a bağlı, client'ın gönderdiği user id'ye değil. Rota sayısı 108 → **111** (`docs/API_CONTRACT.md`, `ApiContractInventoryTest` güncellendi). `ClubDetailScreen` artık `CampusRepository.getJoinedClubIds/joinClub/leaveClub` kullanıyor (üç çağrı noktası: `explore_screen.dart`, `ask_arucad_screen.dart`, `guide_sheet.dart` hepsi `repository`'i geçiyor). Mock modda eski davranış aynen korundu (`MockCampusRepository` hâlâ `AppSettingsStore`'a delege eder) — REST modda SharedPreferences artık source of truth değil. Legacy cihaz-local üyelik REST'e otomatik taşınmadı (güvenli otomatik eşleme yok — hangi cihazın hangi backend kullanıcısına ait olduğu bilinmiyor). Üye sayısı gösterimi (`campusClubMemberEstimate`) bu turda gerçek `COUNT(club_members)`'a çevrilmedi — kapsam yalnız "kimin üye olduğu" idi, "kaç kişi" ayrı bir küçük iyileştirme olarak bırakıldı. Testler: backend `ClubMembershipTest` (10 test — join/duplicate/leave/rejoin/404/401/isolation/cascade), Flutter `club_membership_test.dart` (6 test — Mock + Rest). Backend 315/315, Flutter 75/75 yeşil.

**DC-3 not:** "İlk 30 Gün" tikleri `AppSettingsStore.onboardingDone` / `onboardingStartedAt` (cihaz-local) idi. Yeni `onboarding_progress` (`user_id` + `step_id`, unique, cascade). Checklist içeriği (`onboarding_config.dart`) statik ürün kopyası olarak Flutter'da kaldı; yalnız tamamlanma + `startedAt` (`users.created_at`, cihazlar arası gerçek "1. gün") sunucuya taşındı. `GET /me/onboarding`, `POST /me/onboarding/{stepId}` — idempotent, `currentUser()` scope. `NewStudentScreen` / `HomeScreen` repository üzerinden gidiyor.

**DC-4 not:** `CampusVisibility` / nearby / check-in görünürlüğü / personalization `AppSettingsStore`'daydı. `users` üzerine dört kolon (`location_visibility` default `ghost`, `nearby_discoverable` default false, `check_in_visible` default true, `personalization` default true). `GET`/`POST /me/settings` kısmi güncelleme. `CampusShell` ve `PlaceDetailScreen` check-in görünürlüğünü repository'den okuyor. Dil ve biyometrik cihaz tercihi olarak `AppSettingsStore`'da kaldı.

**DC-5/6/7 not:** Kapak `PlacePhotoStore` (SharedPreferences pointer) idi; `places.cover_url` + `POST /places/{id}/cover` + Place JSON `coverUrl`. `density` artık son 2 saatteki check-in sayısından (`quiet` <2, `moderate` 2–4, `busy` ≥5); `rating` review ortalaması (yoksa 0). Statik kolonlar duruyor ama API onları döndürmüyor. `recentCheckins` yeni alan (mevcut Place şekline ek; kırıcı değil). `PlacePresence` GET /places N+1 yapmasın diye batch.

**DC-8 not:** `quests.progress` seed'de 3'tü ve hiç artmıyordu. `quests.kind` (`distinct_checkins` / `event_joins` / `static`). GET /me/quests progress'i gerçek check-in (distinct place) / event_join sayısından hesaplıyor, `target`'ta cap. JSON şekli aynı.

**DC-9 not (product rule):** `xp_transactions` ledger'ı geri getirilmedi. `users.xp` ömür boyu toplam ve **yıllık sıfırlanmaz**. Yıllık XP, Activity sekmesinde `GET /me/activity` içindeki `createdAt` + `xp` toplamı (check-in her zaman +10, event join subtitle'daki `+$N XP`). Leaderboard hâlâ lifetime `users.xp`.

**DC-10 not:** "Sana Özel" hâlâ client-side (unvisited + `.take(3)`); GPS varsa gerçek mesafeye göre sıralanır, yoksa liste sırası. Alt yazı "yakın yerler" iddiasını yalnız konum varken kullanıyor — konum yokken "Henüz check-in yapmadığın yerler." Recommendation endpoint yok (bilinçli: mevcut iki GET yeterli).

**DC-11 not:** `campusOnlineCount(name.hashCode)` emekli. Sayı `place.recentCheckins` (son 2 saat, backend). Home "Kampüs Nabzı" bu sayıya + density'ye göre sıralıyor. Harita "X çevrimiçi" → "X kişi son 2 saatte check-in yaptı"; uydurma "E. · 3 dk önce" satırları kalktı.

**DC-12 not (CMS kararı):** REST'te yer listesi/koordinat kanonu `GET /places` (live map zaten `widget.places` kullanıyor). `poi_config.dart` yalnız mock seed + bina dizini geo fallback. `shuttle_config.dart` **local kalır** (shuttle API yok; sabit sefer saatleri). `campus_sites.dart` geofence / tur URL / harita extent — `place.tourUrl` ile kısmi örtüşme, şimdilik duruyor. `tours_config.dart` kullanılmayan kopya; silinmedi, yorumla işaretlendi. `campus_life_config.dart` katalog API'lerinin seed/fallback'i (REST zaten `/clubs|/sports|/services` kullanıyor). `onboarding_config.dart` / `normalizeCategory()` ürün sabiti, local kalır. Bu turda yeni CMS tablosu açılmadı.

Rota sayısı domain cleanup sonunda **117** (108 → 111 kulüp + 1 bio + 2 onboarding + 2 settings + 1 cover).

### P4
**P4-MEGA1-COMPLETE** — Achievements + Personal Gallery + Bandabuliya + Career Hub + Communities filter.

| Alt paket | Durum | Not |
|---|---|---|
| Achievements | Server-backed | `achievement_definitions` + `user_achievements`; unlock yalnız domain event sonrası (`AchievementEvaluator`); `GET /me/achievements`; bio `users.achievements` ayrı kaldı |
| Personal Gallery | Backend-backed | `media_items.user_id`; `GET/POST /media/mine`, `POST /media/mine/{id}/delete`; admin `/media*` ayrı (`media.manage`) |
| Bandabuliya | Events reuse | `GET /events?category=Bandabuliya` (+ `placeId`); seed place + events; Explore Bandabuliya bölümü |
| Career Hub | Backend-backed | `career_opportunities` + `career_profiles`; `career.manage` admin write + audit; own profile isolation |
| Communities filter | Clubs reuse | `GET /clubs?category=Community`; Explore catalog chips All/Clubs/Sports/Communities/Services |

**Rota:** 117 → **126** (+9: achievements, media/mine×3, career opportunities, me career-profile×2, admin career×2).

**Migration:** `2026_08_25_000000` achievements, `010000` media user_id, `020000` career tables.

**Kapsam dışı (Mega-2/3):** 360 map, routing, video moderation, AI poster, human mod queue, admin web rewrite, Ask ARUCAD redesign.

### P4 Mega-2
**P4-MEGA2-COMPLETE** — 360 campus drill-down + walking routing abstraction + video upload/moderation + poster→AI draft + human visual queue.

| Alt paket | Durum | Not |
|---|---|---|
| 360 Campus | Backend-backed | Soft hierarchy on `directory_entries`; `GET /directory/buildings|…/floors|…/rooms` (+ `?building=&floor=` filter). No separate buildings DB. |
| Routing | Abstraction + optional provider | `POST /routing/directions`; `ROUTING_BASE_URL` OSRM-compatible. Empty → 501 `ROUTING_NOT_CONFIGURED` (no fake turn-by-turn). |
| Video | Backend-backed | MP4/WEBM/MOV ≤64MB on existing media disk; `moderation_status=pending` → human queue (no auto-approve). |
| AI Event Draft | Abstraction + optional Groq vision | `POST /admin/events/draft-from-poster` (`events.manage`); always draft; 501 without `GROQ_API_KEY`. Never auto-publishes. |
| Human Moderation | Backend-backed | `GET/POST /admin/moderation/queue*`; `moderation.moderate`; flagged ≠ instant reject. |

**Rota:** 126 → **133** (+7: buildings, floors, rooms, routing, draft-from-poster, queue, queue resolve).

**Migration:** `2026_08_25_100000` media `moderation_status`; `110000` events `ai_draft` + `ai_source_media_id`.

**External blockers:** `ROUTING_PROVIDER_REQUIRED` / `BLOCKED_EXTERNAL_DEPENDENCY` when `ROUTING_BASE_URL` unset; poster AI `BLOCKED_EXTERNAL_DEPENDENCY` when `GROQ_API_KEY` unset; no automated video moderation provider.

**Kapsam dışı (eski Mega-3 notu):** admin web shell + Ask ARUCAD — **Mega-3'te ele alındı** (aşağıya bak).

### P4 Mega-3 / FINAL
**P4-FINAL-COMPLETE** — Admin shell split + Ask ARUCAD 5-tab contract + product/security re-audit.

| Alan | Durum | Not |
|---|---|---|
| Admin web shell | Split | Shell ~570 satır + `admin/sections/*` + `admin/widgets/*` (`part of`); UI/nav aynı; data → `CampusRepository` |
| Ask ARUCAD | 5 sekme | Bottom nav: Home·Explore·Social·Quests·Profile. Ask = sheet + full-screen push. `AskArucadStore` = cihaz sohbet cache. REST `/ai/query`; unavailable → clear message |
| Product contract | Verified | DC-10/11 korunuyor; unused `tours_config.dart` silindi |
| Security | Verified | AI/routing keys backend-only; audit REST server-side |
| API contract | 133 | Mega-3 route eklemedi |

**Rota:** **133**.

**External blockers:** PostgreSQL prod, SMTP, Firebase, Sentry, `ROUTING_BASE_URL`, `GROQ_API_KEY`, hosting, Apple/Google Play.

---

## 13. Migration Gerektiren Değişiklikler

| Görev | Yeni/değişen tablo | Geri alınabilir mi | Veri kaybı |
|---|---|---|---|
| P1-5 | `role_assignments.permissions` (json, nullable) | Evet | Yok |
| P1-6 | `post_likes` (+ `feed_posts.liked_by_me` drop) | Evet (down ile) | `liked_by_me` anlamsız veri, kayıp önemsiz |
| P1-7 | `conversations`, `conversation_participants`, `messages` | Evet | Mevcut `chat_messages` **taşınmalı** (tek yönlü kayıtlar ikili konuşmaya map edilir) |
| P1-8 | `social_follows.followed_user_id`, `social_blocks.blocked_user_id` | Evet | İsim→id eşleşmeyen satırlar boşta kalır |
| P1-11 (ops.) | `post_comments.user_id` | Evet | Eski yorumlarda null |
| P2 (ops.) | `club_members`, `xp_transactions` | Evet | Yok (yeni) — `club_members` DC-1'de geldi; `xp_transactions` **bilinçli olarak getirilmedi** (DC-9 product rule) |
| DC-3 | `onboarding_progress` | Evet | Yok (yeni) |
| DC-4 | `users.location_visibility` / `nearby_discoverable` / `check_in_visible` / `personalization` | Evet | Yok (default'lar eski client default) |
| DC-5 | `places.cover_url` | Evet | Yok (nullable) |
| DC-8 | `quests.kind` | Evet | Yok (default `static`) |
| P4-M1 | `achievement_definitions`, `user_achievements` | Evet | Yok (yeni) |
| P4-M1 | `media_items.user_id` (nullable FK) | Evet | Yok (admin satırlar null) |
| P4-M1 | `career_opportunities`, `career_profiles` | Evet | Yok (yeni) |
| P4-M2 | `media_items.moderation_status` | Evet | Yok (default `approved`) |
| P4-M2 | `events.ai_draft`, `events.ai_source_media_id` | Evet | Yok (nullable) |

**Kural:** Mevcut migration dosyaları **silinmez/düzenlenmez**; her değişiklik yeni `add_*/create_*` migration'ı ile yapılır.

---

## 14. Frontend Kırma Riski Taşıyan Değişiklikler

| Değişiklik | Neden riskli | Azaltma |
|---|---|---|
| `auth:sanctum` açılması (P1-2) | Token'sız her REST çağrısı 401 | P1-1+P1-3 ile aynı milestone'da; Mock mod etkilenmez |
| Login akışına `startSession` eklenmesi (P1-3) | `_DemoSession._finishSignIn` sırası değişir | `MockAuthProvider` davranışı korunur; `CampusRepository`'ye opsiyonel metot |
| `/admin/*` permission (P1-5) | Admin panel 403 alabilir | Rol ataması seed'i + panelde 403 mesajı |
| `post_likes` (P1-6) | `likedByMe` DTO alanı | **API alan adı değişmez** → Flutter kodu aynı kalır |
| Chat modeli (P1-7) | `/chat/{peer}/messages` imzası | Endpoint yolu ve JSON alanları (`id/fromMe/text/sentAt`) korunur |
| Medya `dataUri` → `url` (P2-2) | **Tamamlandı** — blok/kapak `url` tercih eder, legacy `dataUri` okunur; REST yeni kayıtlar URL |
| Yemek repository'ye taşınması (P2-1) | **Tamamlandı** — `CampusFoodVenue` / `DailyMenu` şekli korundu; menü `date` REST'te `Y-m-d` |
| Pagination (P2-8) | **Tamamlandı** — feed/notifications/audit-log/email-logs `page`/`perPage` + `meta.pagination` | Eski 100/200 cap kalktı; `getFeed()` ilk sayfayı döner; load-more `get*Page` |
| `USE_REST_API` varsayılanı (P0-4) | Mock demo akışı | Ayrı karar; bu turda değiştirilmez |

**Dokunulmayacaklar:** ekran yapıları, tasarım sistemi (`arucad_theme.dart`), navigasyon, mevcut endpoint isimleri, mevcut migration dosyaları, `{data, meta, error}` zarfı, `request_id`.

---

## 15. Önerilen İlk Milestone

**M0 = P0-1 + P0-2 + P0-3** (üçü birlikte, tek doğrulanabilir paket)

- Neden: iş mantığına hiç dokunmaz, geri alınması kolay, ve **P1'i test edebilmenin ön koşulu** — bugün web istemcisiyle backend'e hiç ulaşılamıyor.
- Doğrulama: `php artisan test` + `dart run tool/verify_rest_backend.dart` + Chrome'dan gerçek giriş denemesi.
- Migration: yok. Frontend kırma riski: yok.

---

## 16. REAL PRODUCT HARDENING (2026-08-26) — PARTIAL

Detaylı checklist: [`docs/REAL_PRODUCT_AUDIT.md`](REAL_PRODUCT_AUDIT.md).

**Hardening 1:** server check-in geofence, XP +10, session restore, onboarding `eligible`, club `memberCount`, occupancy kaldırıldı, aktivite notify/email.

**Hardening 2:** `staff_profiles` CRM + filtreler; `participation_applications` submit/approve/reject + notify/email; appointments + double-book guard; achievements admin API; stats applications; admin Başvurular/Personel; club join → başvuru formu; chat navy UX. Routes **151**.

Hâlâ açık: sport/service/career UI apply yüzeyleri, appointment Flutter UI, achievements admin sekmesi, dashboard↔stats birleşimi, Explore/Activity derin polish, MapLibre style sprite 404 (üçüncü taraf), browser smoke, external credentials.

Test: backend **389**, Flutter **102**, routes **151**.

---

READY FOR IMPLEMENTATION
