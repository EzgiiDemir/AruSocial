# Dış Hesaplar — Kurulum Rehberi

Bu doküma, kod tarafı **zaten hazır** ama gerçek çalışması için ARUCAD'in
kendi hesap/kimlik bilgilerini gerektiren her şeyi tek yerde topluyor. Sen
bu bilgileri verdiğinde tek yapılacak olan ilgili alanları doldurmak —
kod değişikliği gerekmiyor.

---

## 1. Microsoft Entra (giriş)

**Kod tarafı hazır:** `frontend/lib/core/auth/entra_auth_provider.dart` —
gerçek PKCE OAuth akışı, `flutter_appauth` ile.

**Redirect scheme (Android + iOS'ta zaten yapılandırılı):**
`com.example.arucad_campus_prototype`

- Android: `frontend/android/app/build.gradle.kts` →
  `manifestPlaceholders["appAuthRedirectScheme"]`
- iOS: `frontend/ios/Runner/Info.plist` → `CFBundleURLTypes`

**ARUCAD'in yapması gerekenler:**
1. Azure Portal → Microsoft Entra ID → App registrations → New registration.
2. Platform: "Mobile and desktop applications" → Redirect URI olarak
   `com.example.arucad_campus_prototype://oauthredirect` ekle (yukarıdaki
   scheme ile birebir eşleşmeli — farklı bir scheme istenirse hem Azure'da
   hem `build.gradle.kts`/`Info.plist`'te birlikte değişmeli).
3. Şu üç değeri bana ver:
   - **Tenant ID**
   - **Client ID**
   - **Redirect URI** (yukarıdaki)

**Nereye giriliyor:** Uygulama içinde Admin Panel → Site Ayarları →
Microsoft Entra alanları (`SiteSettingsStore`) — girildiği an
`main.dart` gerçek `EntraAuthProvider`'ı devreye sokuyor, "Microsoft ile
Giriş Yap" butonu otomatik beliriyor. **Client Secret alanı yok** — native/
mobil OAuth PKCE kullanır, secret taşımaz.

**Backend:** `GET /api/v1/auth/entra/config` and `POST /api/v1/auth/entra`
verify the ID token (JWKS/RS256) and issue a Sanctum session. Empty
tenant/client → 501 `ENTRA_NOT_CONFIGURED`. Real Azure values still come
from ARUCAD's tenant.

---

## 2. Firebase (push bildirimleri)

**Kod tarafı (P3-2):** Backend `HttpFcmClient` + `DeliverFcmNotification` job
inbox satırı yazıldıktan sonra FCM HTTP v1 çağırır. Flutter
`firebase_messaging` token'ı mevcut `POST /push-tokens` ucuna kaydeder.
`FIREBASE_PROJECT_ID` / `FIREBASE_CLIENT_EMAIL` / `FIREBASE_PRIVATE_KEY`
boşsa gönderim no-op'tur; inbox satırı yine yazılır. Gerçek private key
git'e / Flutter bundle'a / API cevabına konmaz.

**ARUCAD'in yapması gerekenler:**
1. [Firebase Console](https://console.firebase.google.com) → proje oluştur.
2. Android app → paket **`com.arucad.arucadCampusPrototype`** (P3-13'te
   `com.example.*`'tan güncellendi — bkz. §7 karar notu) →
   `google-services.json` (yalnızca local; gitignore'da).
3. iOS: APNs key + `GoogleService-Info.plist` + Xcode Push Notifications
   capability. `Runner.entitlements` `aps-environment=development` iskeleti
   var; gerçek Apple hesabı / provisioning bu milestonda yok.
4. Service account (Firebase Admin / Cloud Messaging) JSON'dan
   `FIREBASE_PROJECT_ID`, `FIREBASE_CLIENT_EMAIL`, `FIREBASE_PRIVATE_KEY`
   → yalnız `backend/.env`.
5. FlutterFire CLI ile `lib/firebase_options.dart` üret (gitignore).

**Teslimat ayrımı:** uygulama açık + Reverb bağlı → in-app chat.
Arka plan / killed / websocket kapalı → FCM OS bildirimi. Ön planda OS
banner bastırılır.

---

## 3. Groq (AI — Ask ARUCAD)

**Kod tarafı hazır ve çalışıyor.** Şu an iki yol var:
- **`USE_REST_API=true` modu (önerilen)**: istemci Groq'u hiç görmüyor,
  çağrı `backend/`'in `/api/v1/ai/query` proxy'sinden gidiyor — key
  sadece `backend/.env`'de, istemci koduna/derlenmiş APK'ya/web
  bundle'ına hiç gömülmüyor.
- **Mock/varsayılan mod**: istemci doğrudan Groq'u çağırıyor
  (`frontend/lib/core/services/groq_ai_service.dart`), key
  `--dart-define=GROQ_API_KEY=...` ile build zamanında veriliyor — **kod
  içinde artık hardcoded değil** (önceden öyleydi, bu oturumda kaldırıldı
  ve git geçmişindeki eski key **iptal edilmesi gerekiyor**, bkz. aşağıdaki
  uyarı). Define verilmezse Ask ARUCAD sadece kural-tabanlı yedek
  cevaplara düşer, hata vermez.

**ARUCAD'in yapması gereken:** Gerçek bir Groq API key
([console.groq.com](https://console.groq.com)).

**Nereye giriliyor:**
- REST modda: `backend/.env`'de `GROQ_API_KEY=...` (Ask ARUCAD `/ai/query` + poster draft vision aynı key; use-case'ler ayrı endpoint)
- Mock modda (opsiyonel, sadece demo için): `flutter run --dart-define=GROQ_API_KEY=...`

> ⚠️ **Önemli — eski key iptal edilmeli:** Bu dosyada daha önce gerçek bir
> Groq key'i doğrudan kaynak koduna gömülü olarak duruyordu. Koddan
> kaldırıldı, ama git geçmişinde duruyor olabilir — Groq Console'dan eski
> key'i iptal edip yenisini oluştur.

---

## 3b. Walking routing (OSRM-compatible)

**Kod tarafı hazır (P4 Mega-2):** `POST /api/v1/routing/directions` →
`RoutingService` (`ROUTING_BASE_URL`). Flutter asla routing secret/base
tutmaz. Boşsa API **501** `ROUTING_NOT_CONFIGURED` — sahte turn-by-turn yok.

**ARUCAD'in yapması gereken:** Self-hosted veya yönetilen OSRM-compatible
base URL.

**Nereye giriliyor:** `backend/.env` → `ROUTING_BASE_URL=...`

---

## 4. Görsel Moderasyon (OpenAI-uyumlu)

**Kod tarafı hazır:** backend'deki `App\Services\ImageModerationService`,
`omni-moderation-latest` modelini çağırıyor — tarama tamamen sunucu
tarafında (docs/EKSIKLER.md §26), istemci sadece fotoğraf baytlarını
`POST /moderation/check-image`'a yolluyor.

**ARUCAD'in yapması gereken:** OpenAI (veya uyumlu bir sağlayıcı) API key.

**Nereye giriliyor:** Admin Panel → Site Ayarları'ndan, backend'in
`app_settings` tablosuna yazılıyor (`Admin\SettingsController`) — cihaza
hiç geri okunmuyor, sadece "yapılandırıldı/yapılandırılmadı" durumu
gösteriliyor. Artık tamamen sunucu tarafında, önceki `SharedPreferences`
sürümünden farklı olarak.

---

## 5. SMTP / E-posta sağlayıcısı

**Kod tarafı hazır (P3-5):** Laravel standart SMTP (`MAIL_MAILER=smtp`).
Local default `log` — gerçek kutuya gitmez. Production boot `smtp` +
host/from/username/password ister. Provider API (SendGrid/Mailgun REST)
yok; host SMTP yeter.

**ARUCAD'in yapması gereken:** SMTP host/port/kullanıcı/şifre (Workspace,
Exchange, veya herhangi bir SMTP). Değerler yalnız sunucu `.env`.

**Nereye giriliyor:** `backend/.env` `MAIL_*` (placeholder: `.env.example`).

---

## 6. PostgreSQL (gerçek üretim veritabanı)

**Kod tarafı hazır (P3-3):** Aynı Laravel migration'lar SQLite (local/test)
ve PostgreSQL'de çalışır. `sql/schema.sql` SQLite snapshot olarak kalır;
prod dump değildir. CI PostgreSQL job'ı `phpunit.pgsql.xml` çalıştırır.

**ARUCAD'in yapması gereken:** Gerçek, erişilebilir bir PostgreSQL sunucusu
— Railway/Render/Fly.io gibi bir yönetilen servis, ya da ARUCAD'in kendi
sunucusu. Bana host/port/database adı/kullanıcı adı/şifre lazım.
Production cutover (gerçek sunucu, backup, HA) bu milestone'da (P3-8)
**hâlâ yapılmadı** — `READY_FOR_CUTOVER`. SQLite → Postgres veri taşıma
scripti artık hazır (`php artisan db:migrate-sqlite-to-pgsql --dry-run`,
bkz. `docs/DEPLOYMENT.md` ve `docs/TESTING.md` §6b) — idempotent, dry-run
destekli, gerçek yazım için `--confirm-source-backup` ister, hiçbir veriyi
otomatik silmez.

**Nereye giriliyor:** `backend/.env`'de `DB_CONNECTION=pgsql` +
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`. Default
local `DB_CONNECTION=sqlite` kalır.

---

## 7. Apple Developer / Google Play Developer

**Kod tarafı:** iOS iskeleti (`frontend/ios/`) hazır ama bu ortamda
derlenip test edilemez (gerçek bir Mac + Xcode gerekiyor — bkz.
`docs/AUDIT_GERCEK_URUN.md` P3-14 notu, `BLOCKED_EXTERNAL_DEPENDENCY`).

**ARUCAD'in yapması gereken:** Apple Developer Program üyeliği ($99/yıl),
Google Play Console geliştirici hesabı ($25 tek seferlik). Bunlar
tamamen ARUCAD'in kendi hesapları olmalı — ben oluşturamam.

**Android applicationId (P3-13 — KARAR GEREKİYOR):** `com.example.*`
placeholder'ı, repo'da zaten var olan iOS bundle id'siyle
(`com.arucad.arucadCampusPrototype`, `ios/Runner.xcodeproj/project.pbxproj`)
birebir eşleşecek şekilde `com.arucad.arucadCampusPrototype` yapıldı
(`frontend/android/app/build.gradle.kts` `applicationId`/`namespace`).
Bu, kendi kafama göre uydurulmuş yeni bir isim **değil** — repodaki mevcut
tek gerçek ARUCAD konvansiyonunu iki platforma da uyguladı. **Ama bu string
daha önce ARUCAD ile teyit edilmediyse, gerçek Play Console kaydından/ilk
yüklemeden ÖNCE mutlaka onaylanmalı** — bir kez Play Console'a yüklenen
`applicationId` bir daha değiştirilemez. Microsoft Entra OAuth redirect
scheme'i (`com.example.arucad_campus_prototype`) kasıtlı olarak
değiştirilmedi (iOS'ta zaten aynı ayrım var — bkz. §1); bu, ayrı bir karar.

**Android release keystore (P3-13 — `KEYSTORE_REQUIRED`):** Gerçek bir
production keystore repo'da yok ve olmamalı. `frontend/android/app/build.gradle.kts`
artık `android/key.properties` varsa gerçek imzalama, yoksa (bugünkü durum)
debug anahtarlarına düşüyor — `frontend/android/key.properties.example`'a
bak. ARUCAD (veya belirlenen release sorumlusu) gerçek bir keystore
üretmeli (`keytool -genkey ...`, örnek `key.properties.example` içinde) ve
onu **hiçbir zaman** bu repoya değil, güvenli bir yere (parola yöneticisi /
CI secret store) koymalı — kaybedilirse Play Store'da aynı uygulamayı bir
daha güncelleyemezsiniz.

---

## Özet tablo

| Servis | Kod hazır mı | Bekleyen bilgi |
|---|---|---|
| Microsoft Entra | ✅ | Tenant ID, Client ID |
| Firebase / Push (Android) | ✅ (yapılandırma bekliyor) | `google-services.json` (paket: `com.arucad.arucadCampusPrototype`) |
| Firebase / Push (iOS) | ✅ (yapılandırma bekliyor) | `GoogleService-Info.plist`, Apple Developer hesabı olmadan tamamlanamaz |
| Sentry | ✅ (P3-6 kod hazır) | Gerçek organizasyon + `SENTRY_DSN` (backend + Flutter) |
| Groq AI | ✅ | API key |
| Görsel Moderasyon | ✅ | API key |
| SMTP | ✅ (P3-5 standart SMTP) | `SMTP_ACCOUNT_REQUIRED` — host/port/user/password |
| PostgreSQL | ✅ (P3-3 uyumluluk + P3-8 data migration script) | `READY_FOR_CUTOVER` — prod sunucu erişimi |
| Android release keystore | ✅ (P3-13 signing config hazır) | `KEYSTORE_REQUIRED` — gerçek `.jks` |
| Apple/Google Developer | Uygulanamaz burada | Hesap oluşturma (sadece ARUCAD), `APPLE_DEVELOPER_ACCOUNT_REQUIRED` |
