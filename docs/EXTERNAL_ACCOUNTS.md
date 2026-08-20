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

**Backend tarafında hâlâ eksik olan:** Entra'dan gelen token'ı doğrulayıp
gerçek bir rol ata (bkz. `docs/EKSIKLER.md` §Auth) — bu, Tenant/Client ID
verildikten SONRA yapılacak ayrı bir backend işi.

---

## 2. Firebase (push bildirimleri)

**Kod tarafı:** `firebase_core` paketi kurulu, `main.dart`'ta
`Firebase.initializeApp()` çağrılıyor (yapılandırma yoksa sessizce
atlanıyor, hata basmıyor — bkz. kod yorumu).

**ARUCAD'in yapması gerekenler:**
1. [Firebase Console](https://console.firebase.google.com) → yeni proje
   oluştur (veya mevcut ARUCAD Google Workspace projesini kullan).
2. Android app ekle → paket adı `com.example.arucad_campus_prototype` →
   `google-services.json` indir.
3. iOS app ekle (gerekiyorsa) → `GoogleService-Info.plist` indir.
4. Bana şunları ver: indirilen `google-services.json` /
   `GoogleService-Info.plist` dosyaları, veya FlutterFire CLI ile
   `firebase_options.dart` üretmemi istiyorsan Firebase proje erişimi.

**Ben ne yapacağım (dosyalar gelince):**
- `google-services.json` → `frontend/android/app/`
- `GoogleService-Info.plist` → `frontend/ios/Runner/`
- `firebase_messaging` paketini gerçek sürümle ekleyip Android/iOS/web
  entegrasyonunu tamamlamak, backend'den (`backend/`) bildirim
  gönderebilecek bir uç eklemek.

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
- REST modda: `backend/.env`'de `GROQ_API_KEY=...`
- Mock modda (opsiyonel, sadece demo için): `flutter run --dart-define=GROQ_API_KEY=...`

> ⚠️ **Önemli — eski key iptal edilmeli:** Bu dosyada daha önce gerçek bir
> Groq key'i doğrudan kaynak koduna gömülü olarak duruyordu. Bu oturumda
> koddan kaldırıldı, ama git geçmişinde (daha önceki commit'lerde) hâlâ
> duruyor olabilir — kod tarafından kaldırmak geçmişi silmiyor. Bu repo
> hiç GitHub'a push edilmediyse bile, güvenli tarafta kalmak için Groq
> Console'dan o eski key'i **iptal edip yenisini oluşturmanı** öneririm.

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

**Kod tarafı:** ❌ henüz yazılmadı (bkz. `docs/EKSIKLER.md`).

**ARUCAD'in yapması gereken (ne zaman istersen):** SendGrid/Postmark gibi
bir sağlayıcı hesabı, veya ARUCAD'in kendi Google Workspace/Exchange SMTP
kimlik bilgileri (host, port, kullanıcı adı, şifre/app-password).

**Nereye giriliyor:** `backend/.env`'de `MAIL_*` değişkenleri (Laravel'in
kendi standart mail yapılandırması) — key/şifre geldiğinde gerçek
`nodemailer`-benzeri (Laravel'de `Mail` facade) entegrasyonunu yazarım.

---

## 6. PostgreSQL (gerçek üretim veritabanı)

**Kod tarafı hazır:** Migration'lar Postgres'e taşınmaya hazır (bkz.
`sql/README.md`).

**ARUCAD'in yapması gereken:** Gerçek, erişilebilir bir PostgreSQL sunucusu
— Railway/Render/Fly.io gibi bir yönetilen servis, ya da ARUCAD'in kendi
sunucusu. Bana host/port/database adı/kullanıcı adı/şifre lazım.

**Nereye giriliyor:** `backend/.env`'de `DB_CONNECTION=pgsql` +
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`.

---

## 7. Apple Developer / Google Play Developer

**Kod tarafı:** iOS iskeleti (`frontend/ios/`) hazır ama bu ortamda
derlenip test edilemez (gerçek bir Mac + Xcode gerekiyor).

**ARUCAD'in yapması gereken:** Apple Developer Program üyeliği ($99/yıl),
Google Play Console geliştirici hesabı ($25 tek seferlik). Bunlar
tamamen ARUCAD'in kendi hesapları olmalı — ben oluşturamam.

---

## Özet tablo

| Servis | Kod hazır mı | Bekleyen bilgi |
|---|---|---|
| Microsoft Entra | ✅ | Tenant ID, Client ID |
| Firebase / Push | ✅ (yapılandırma bekliyor) | `google-services.json` / `GoogleService-Info.plist` |
| Groq AI | ✅ | API key |
| Görsel Moderasyon | ✅ | API key |
| SMTP | ❌ (yazılmadı) | Sağlayıcı kimlik bilgileri + kod işi |
| PostgreSQL | ✅ (migration'lar hazır) | Sunucu bağlantı bilgileri |
| Apple/Google Developer | Uygulanamaz burada | Hesap oluşturma (sadece ARUCAD) |
