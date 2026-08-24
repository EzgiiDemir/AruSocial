# AruSocial — Durum Sunumu

**Tarih:** 22 Ağustos 2026  
**Kapsam:** Mevcut kod tabanı (frontend / backend / SQL / API / Android / iOS / UI-UX / tema). Kod değiştirilmedi; bu dosya yalnızca analiz.

**Okuma notu:** Durum işaretleri tasarım sözleşmesindeki dil ile aynıdır.

| İşaret | Anlam |
|---|---|
| **TAMAM** | Var, çalışıyor, sözleşmeyle uyumlu |
| **DÜZELT** | Var ama yanlış, yarım, çelişkili veya kırılgan |
| **EKSİK** | Ürün/sözleşme istiyor, kodda yok veya dış hesaba bağlı |

---

## 0. Gidişat — tek slayt

AruSocial, ARUCAD için **öğrenci işletim katmanı** iddiasında bir prototip: harita uygulaması değil, Instagram kopyası değil. Akademik, idari, sosyal, yaratıcı, kampüs, iyilik hali, kariyer ve günlük yardım aynı kabukta.

**Bugünkü gerçek:** özellik yüzeyi geniş, altyapı henüz üretim değil.

```
Ürün yüzeyi     ████████████████████  geniş (öğrenci + admin CMS)
UX sözleşmesi   ████████████░░░░░░░░  yazılmış; kabuk kısmen sapıyor
Backend API     ██████████████░░░░░░  çok uç var; kimlik yok
Veri katmanı    ████████████░░░░░░░░  SQLite + migration; şema dökümü eski
Mobil native    ██████░░░░░░░░░░░░░░  Android yürür; iOS iskelet
Üretim güvenliği ████░░░░░░░░░░░░░░░  CORS/auth/RBAC/push/SMTP yarım
```

**Tek cümlelik teşhis:** Öğrenciye gösterilecek bir kampüs deneyimi **demo olarak anlatılabilir**. Aynı deneyim **çok kullanıcı + gerçek kimlik + mağaza yayını** olarak henüz savunulamaz.

Dünün (21 Ağustos) kimlik / RBAC / realtime işleri **bilinçli olarak geri alındı**. Bu sunum o geri alınmış haline göre yazıldı: API hâlâ **tek demo hesaba** düşüyor.

---

## 1. Ürün nedir, kimler için

| Katman | Kim | Nasıl açılır |
|---|---|---|
| Öğrenci uygulaması | Öğrenci / personel | Flutter: Android + Web (iOS derlenmemiş) |
| Yönetim paneli | superAdmin | Web `/admin` veya profildeki Yönetim Paneli |
| Ask ARUCAD | Herkes | Alt menü + Home arama + bottom sheet |
| Aktivite formları | E-posta alan öğrenci | Laravel HTML (imzalı URL) — kısmen |

**İki çalışma modu (kritik):**

1. **Mock (varsayılan `flutter run`)** — `MockCampusRepository`. Veri cihazda / bellek + `SharedPreferences`. Backend gerekmez.
2. **REST (`--dart-define=USE_REST_API=true`)** — aynı UI, Laravel `http://…:4000/api/v1`.

Bu ayrım hem güç hem risk: aynı ekran iki farklı gerçeğe bağlanıyor; Yemek / Medya / Site Ayarları REST’te bile **cihaz deposunda** kalıyor.

---

## 2. Ne hizmetler var (öğrenci)

### 2.1 Kabuk

Alt gezinme **6 sekme:** Ana Sayfa · Keşfet · Sosyal · Arucad’a Sor · Aktivite · Ayarlar.

UX sözleşmesi (`docs/UX_DESIGN_SYSTEM.md`) **5 sekme** ister ve Ask ARUCAD’ın sekme **olmamasını** ister. Bu, en görünür mimari sapma.

### 2.2 Ana Sayfa — “şimdi ne oluyor?”

Sıra (sözleşmeyle büyük ölçüde TAMAM): Yakınında → Kampüs Nabzı → Bugün → Kampüste Şimdi → Sana Özel → Hizmet kısayolları. Harita kahraman değil, elden teslim kart.

Ayrıca: anket popup, First 30 Days kartı, kendi aktivite oluşturma.

**DÜZELT / EKSİK**

- “Sana Özel” kişiselleştirme iddiası zayıf; çoğu yer mesafe / seed.
- Nabız / online sayıları dürüstçe tahmin; canlı sayaç değil.
- Home’dan Aktivite’ye giden skor chip’i var; XP tekrarı azaltılmış.

### 2.3 Keşfet — “ne var?”

Kampüs haritası elden teslim, nabız, yaratıcı kampüs, kulüpler, spor, yemek, hizmetler, Yardım Al, Kariyer.

**TAMAM:** Kulüp detayı, hizmet detayı şablonu, Career hub ve Need Help ekranları kodda var.

**DÜZELT:** Kulüp listesi hâlâ katalog hissi (isim/kategori/açıklama). Bandabuliya “bu gece / şimdi / bu hafta” programı yok. Yemek büyük ölçüde The Garden seed.

### 2.4 Sosyal — “insanlar ne yapıyor?”

Feed + hikâye (24s) + filtre (Tümü / Kampüs / Check-in) + resmi rozet + keşfet/takip/engelle + mesaj + bildirimler. Sosyal sekmenin **kendi iç kabuğu** var (Akış / Mesajlar / Kişiler / Profil) — dar telefonda **çift alt bar**.

**TAMAM (REST açıkken):** post, beğeni, yorum, şikayet, hikâye, kaydetme, takip, sohbet, check-in’in akışa düşmesi.

**EKSİK:** Topluluklar filtresi, canlı (WebSocket) sohbet, video, galeri/albüm, kulüp üye listesi.

**DÜZELT:** Mock’ta sosyal grafik cihaza kilitli. REST’te bile her istek **aynı `users` satırına** yazılıyor — çok hesap illüzyonu.

### 2.5 Aktivite (Skor) — “ben ne kadar katılıyorum?”

Yıl skoru, seviye rampı (grafit → premium mavi), Campus Journey özeti, görevler, liderlik tablosu, akademik yıl filtresi. Beğeni **0 XP** (bilinçli).

**EKSİK:** İsimli başarı rozetleri (Achievements bloğu).

### 2.6 Ayarlar / Profil — “ben kimim?”

Nav etiketi “Ayarlar”; ekran hâlâ profil + gizlilik + dil (TR/EN/RU) + First 30 Days + admin girişi. Gönderi grid’i var.

**EKSİK:** Kişisel galeri (Campus Life / Projelerim). Görünürlük seviyesi (Gizli / Arkadaşlarım / Topluluğum / Herkes) kısmen store’da, sosyal bildirimler buna tam bağlı değil.

### 2.7 Harita ve 360

MapLibre + OpenFreeMap (anahtarsız OSM). POI, yoğunluk ısı haritası, “ben buradayım”, servis ring hattı, düz çizgi yürüme tahmini.

**EKSİK:** Gerçek turn-by-turn (OSRM/Valhalla). Bina → kat → oda → kişi 360 drill-down UI düz liste.

**DÜZELT:** Merkezleme / mavi nokta kodda doğru görünüyor; etkileşimli doğrulama yok.

### 2.8 Ask ARUCAD

Cevap → aksiyon (aç / konum / iletişim) hedefi. REST’te Groq backend proxy; Mock’ta istemci çağrısı veya kural tabanlı yedek.

**DÜZELT:** 6. sekme. Harita mini sohbetindeki “devam et / büyüt” sahada doğrulanmamış. AI’ye özel rate limit yok.

### 2.9 Kampüs hizmetleri (öğrenci işleri vb.)

Detay şablonu: konular, saat, konum, kişiler, İletişime Geç / 360 / Konumla. Sözleşmeyle TAMAM iskelet.

**EKSİK:** Kariyer’in tam hub’ı (staj, CV, mock interview, mezun) henüz Service Detail + kısmi hub; SIS / ders / danışman yok (bilinçli — harici sistem).

---

## 3. Admin paneli — ne yönetiliyor

Tek dosyada yoğun CMS (`admin_panel_screen.dart`, binlerce satır). Web-first niyet; bugün uygulamaya gömülü.

| Bölüm | Öğrenciye etkisi | Veri neresi |
|---|---|---|
| Dashboard / İstatistik | Özet | REST `admin/stats` (REST mod) |
| Etkinlikler + Yoklama | Katıl, e-posta, onay | Backend |
| Bekleyen aktiviteler | Öğrenci “kendi aktivite” | Backend |
| Kulüp / Spor / Hizmet / Dizin / Sayfa | Keşfet içerik | Backend (REST) / local (Mock) |
| Anket / Akademik yıl | Home popup, skor filtresi | Backend |
| E-posta günlüğü + toplu mail | Katılım akışı | Laravel mail (`log` sürücü) |
| Moderasyon kuyruğu | Şikayetler | Backend |
| Kullanıcılar / Roller | Giriş rolü | Rol tablosu var; **route zorlaması yok** |
| Yemek | Keşfet yemek | **Cihaz `AdminContentStore`** |
| Medya kütüphanesi | Kapak / blok görseli | **Cihaz base64 `SharedPreferences`** |
| Site Ayarları (Entra, WP, AI) | Giriş / AI | **Cihaz `SiteSettingsStore`** |

Hedef admin kabuğu (sol menü, masaüstü) henüz ayrı bir web app değil.

**EKSİK:** Poster → AI taslak etkinlik. İnsan “bekletmeli” görsel inceleme kuyruğu (şu an flagged = red).

---

## 4. Dış hizmetler (kod hazır / hesap bekliyor)

| Servis | Kod | Gerçek çalışma |
|---|---|---|
| Microsoft Entra (PKCE) | `EntraAuthProvider` | Tenant / Client ID yoksa Mock giriş |
| Groq (Ask ARUCAD) | Backend proxy + Mock yedek | `GROQ_API_KEY` |
| Görsel moderasyon | `POST /moderation/check-image` | OpenAI-uyumlu key; yoksa tarama atlanır |
| Firebase / FCM / APNs | `firebase_core` yutularak init | Proje dosyası yok; `firebase_messaging` web yüzünden çıkarılmış |
| SMTP | Laravel `Mail` + SMTP (P3-5) | Local `log`; prod kutu için `MAIL_*` |
| PostgreSQL | Migration’lar SQLite+PG (P3-3) | Prod cutover yok; local SQLite |
| Ortam | local / staging / production config (P3-4) | Deploy yok |
| WordPress / WPForms | Tek yönlü çekme bahsi | Tam form CMS değil |
| Apple / Google mağaza | — | Hesap + paket kimliği + imza |

Paket / OAuth şeması hâlâ `com.example.arucad_campus_prototype`. Mağaza ve Entra için değiştirilmeli.

---

## 5. Mimari — nasıl duruyor

```
[Flutter UI]
    │  MockCampusRepository     ← varsayılan
    │  RestCampusRepository     ← dart-define
    ▼
[ApiClient]  baseUrl + /path     ← token adapter VAR ama main bağlanmıyor
    ▼
[Laravel /api/v1]  throttle + not-banned
    currentUser() = User::first()   ← her istek aynı kişi
    ▼
[SQLite]  backend/.env → sql/database.sqlite
```

**Güç:** Ekranlar repository arkasında; mock/rest aynı sözleşme.

**Zayıf:** Sözleşme deliniyor (Yemek, Medya, Entra ayarı doğrudan store). Auth adapter boş. Tek kullanıcı.

---

## 6. Frontend — sorunlar

### 6.1 Bağlantı (dün görülen, hâlâ kodda)

1. **`USE_REST_API` varsayılan false.** Düz `flutter run` backend’e gitmez. REST açılmazsa “API çalışmıyor” sanılır; aslında mock’tasınız.
2. **`API_BASE_URL` varsayılanı `http://10.0.2.2:4000/api/v1`.** Bu yalnızca Android emülatör loopback’i. **Web/Chrome/masaüstü** bu adresi çözemez veya yanlış makineye gider.
3. **CORS yalnızca `http://localhost:8090`.** `flutter run -d chrome` rastgele port (ör. `:56918`) açar → preflight düşer → `auth/session` olmasa bile her tarayıcı `fetch`’i `Failed to fetch`.
4. **`GET /api/v1` diye uç yok.** Base URL tarayıcıda açılırsa Laravel 404: *The route api/v1 could not be found.* Asıl uçlar `/auth` yok, `/places`, `/me`, … Health Laravel’de `/up`.

### 6.2 Çift kaynak gerçeği

REST açıkken bile:

- Yemek takvimi local JSON (backend günlük satır modeliyle uyumsuz).
- Medya base64 SharedPreferences (backend multipart + URL hazır).
- Entra / site ayarları cihaz tercihi (tüm kullanıcılar / cihazlar paylaşmaz).
- `ChatStore` hâlâ duruyor; mesaj ekranı repository kullanıyor — ölü / sapma riski.

### 6.3 Kabuk ve gezinme

- 6 sekme vs 5 sekme sözleşmesi.
- Sosyal içinde ikinci nav.
- Alt bar yüksekliği **64** — sözleşme 72–84.
- Logout diyaloğu sabit Türkçe; dil katmanı tam kapsamıyor.
- `admin_panel_screen.dart` bakımı zor tek parça.

### 6.4 Yerelleştirme

TR / EN / RU **krom** (nav, başlık, boş durum) için el tablosu. Seed içerik, olay açıklamaları, Galatea cevapları çevrilmiyor. `pubspec` `gen_l10n` tanımlı; asıl çeviri `AppStrings`.

### 6.5 Kalite

- Widget test yüzeyi ince (EKSIKLER: 7 test bandı).
- `google_fonts` ilk açılışta ağ ister; offline’da sistem fontuna düşer.
- Analytics `MockAnalyticsTracker` — gerçek ürün analitigi yok.
- Dark mode yok.

---

## 7. Backend — sorunlar

### 7.1 Kimlik (en kritik)

`ApiResponds::currentUser()` → `User::firstOrFail()`. Sanctum tablosu var, **route’ta `auth:sanctum` yok.** `POST /auth/session` yok.

Sonuç: beğeni, mesaj, check-in, yasak, XP **tek seed hesaba** yazılır. Admin ve öğrenci API’de aynı kişidir.

Giriş ekranı Mock’ta e-posta/şifre kontrol eder; REST bu kimliği sunucuya taşımaz.

### 7.2 Yetki

`role_assignments` ve admin UI var. **Permission middleware yok** (EnsurePermission bu ağaçta yok). Herhangi biri `/admin/events` vs. çağırabilir. Rate limit (300/dk) ve `EnsureNotBanned` var; yasak, admin dışını 403’ler — tek hesap prototipinde kendini kilitleme riski yüzünden admin hariç tutulmuş.

### 7.3 Validasyon ve API hijyeni

- FormRequest katmanı yok; `Request::input()`.
- Sayfalama yok; listeler komple döner.
- Silme çoğu yerde `POST …/delete` (REST geleneği değil; istemciyle tutarlı).
- `GET /api/v1` / `/health` yok (sadece `/up`).

### 7.4 E-posta ve kuyruk

Katılım akışı (kulüp başkanı + öğrenci formu + onay) kodda gerçek. Local `MAIL_MAILER=log` — kutu gitmez. Staging/prod `smtp` (P3-5). Retry / bulk / email_logs (`sent`/`failed`) var. Gönderim senkron (`Mail::send`); ayrı mail kuyruğu yok.

**EKSİK:** Form versioning, JSON export, form↔etkinlik bağının tam CMS’i. Gerçek SMTP hesabı (host `.env`).

### 7.5 AI

Proxy doğru (key istemcide değil). Modele özel güvenlik / prompt enjeksiyon / kullanıcı başına kota yok.

### 7.6 Doküman çelişkisi

`docs/EKSIKLER.md` içinde görsel moderasyon bir yerde “sunucuda”, başka yerde “henüz taşınmadı”. `docs/API_CONTRACT.md` hâlâ `/memories`, `/map`, `/routes` listeler — **bu uçlar yok**.

---

## 8. SQL / veri — sorunlar

**Kaynak gerçeği:** `backend/database/migrations/` (onlarca migration).  
**Ayna:** `sql/schema.sql` — dokümanın kendi uyarısı: eskir. Şu an döküm **erken şema:** `clubs`, `food_venues`, `surveys`, `notifications`, `role_assignments` vb. yok. `schema.sql`’e bakmak yanıltır.

**Canlı dosya:** `sql/database.sqlite` git’te yok; `migrate:fresh --seed` ile üretilir.

### Şema kalitesi

| Konu | Durum |
|---|---|
| FK’ler (check-in, join, quest) | Çoğunda var |
| `feed_posts.liked_by_me` | Tek kolon; çok kullanıcıda herkese aynı “beğendim” |
| `events.time` string | Saat tipi değil; boş string / null tarihi |
| JSON alanlar (`interests`, menü `items`) | SQLite’de esnek; Postgres’te kontrol edilmeli |
| UUID string PK + integer `users.id` | Karışık kimlik modeli |
| `personal_access_tokens` | Kullanılmıyor |
| XP / akademik yıl sıfırlama | Uygulama mantığı; DB constraint değil |

**EKSİK:** Prod Postgres, yedekleme, migration’ların gerçek Postgres’te koşulması, `schema.sql` yenileme disiplini.

**Uyarı:** 21 Ağustos migration’ları (post_likes, realtime, academic_staff, …) geri alma ile **kaynak ağaçtan çıktı**. Eski local `database.sqlite` o tabloları içeriyorsa migrate durumu kirli olabilir — taze `migrate:fresh --seed` en temiz yol.

---

## 9. API — sorunlar

### 9.1 Sözleşme sapması

`API_CONTRACT.md` çekirdek uçları dar ve kısmen hayali. Gerçek `routes/api.php` çok daha geniş (feed, stories, clubs, surveys, admin, media, chat, …) ama **login yok**.

Standart zarf `{ data, meta.request_id, error }` **TAMAM** ve Flutter bunu parse ediyor.

### 9.2 Güvenlik yüzeyi (REST açıkken)

- Kimlik yok → herkes admin uçlarını çağırır.
- CORS dar → web istemci kırılır; native’de Origin olmadığı için CORS sessiz.
- Moderasyon key sunucuda (iyi). Groq key sunucuda (iyi). Entra secret native PKCE (iyi) ama token doğrulama backend’de yok.
- HTTPS / ortam ayrımı yok.

### 9.3 İstemci URL birleştirme

`ApiClient`: `baseUrl + path`. Base `…/api/v1` olmalı, path `/places`. Base’e `/api/v1` eklenmezse veya iki kez eklenirse 404.

### 9.4 Olmayan / yarım uçlar (sözleşme veya ürün)

- `POST /memories`, `GET /map`, `POST /routes` — yok.
- Realtime / poll events — geri alındı.
- Kullanıcı CRUD / ban admin API — bu ağaçta yok (store / tek hesap).
- Push register tablosu var; FCM gönderimi yok.

---

## 10. Android — sorunlar

| Madde | Durum |
|---|---|
| Uygulama kimliği | `com.example.arucad_campus_prototype` — örnek alan, mağaza için değiştirilmeli |
| minSdk | Flutter varsayılanı; ikon config 21 |
| İzinler | Konum, kamera, biyometri. INTERNET Flutter birleşiminden gelir |
| Imza | Release **debug keystore** — Play’e çıkmaz |
| Entra scheme | Manifest placeholder var; Azure kaydı şart |
| Emülatör API | `10.0.2.2` doğru; **fiziksel cihaz** LAN IP `dart-define` ister |
| Launcher ikon | Android + web üretilir |
| Firebase | `google-services.json` yok |
| Push | `firebase_messaging` yok |

Biyometri web’de etkisiz (beklenen). Konum izni girişte isteniyor.

---

## 11. iOS — sorunlar

| Madde | Durum |
|---|---|
| Proje iskeleti | `frontend/ios/` var |
| Bu ortamda derleme | Mac + Xcode yok; **test edilmemiş** |
| Launcher ikon | `pubspec`: `ios: false` |
| Info.plist | Konum, kamera, galeri, Face ID metinleri TR ve yerinde |
| OAuth URL scheme | Android ile aynı `com.example…` |
| Yön | Portrait tanımlı |
| APNs / GoogleService-Info | Yok |
| Apple Developer | Dış hesap |

iOS “hazır” değil; **iskelet + izin metinleri**. TestFlight için ayrı iş paketi.

---

## 12. UI / UX — sözleşme vs gerçek

Kaynak: `docs/UX_DESIGN_SYSTEM.md`.

### TAMAM’a yakın

- Home timeline, harita kahraman değil.
- Hizmet detay şablonu.
- Sosyal resmi / öğrenci ayrımı.
- Skor olgun palet (kırmızı-sarı-yeşil yok).
- Montserrat + Oswald (ağ ile).
- Campus Journey ayrı kart (tek “7/10 öğrenci” skoru yok).
- Kart köşesi ~18 (sözleşme 16–20).
- Career hub / Need Help ekranları başlamış.

### DÜZELT (çatışıyor)

1. **6 sekme + Ask ARUCAD tab** — sözleşme ihlali. Bilişsel yük, dar telefonda taşma için `nav_ask_short` yaması var; asıl çözüm sekmeyi kaldırmak.
2. Sosyal **iç içe nav** — “birincil CTA / bir iş” kuralına aykırı sıkışıklık.
3. Alt bar 64 vs 72–84.
4. Profil nav adı Ayarlar; sözleşme hâlâ “Profil + dişli”. İsim karışık.
5. Etkinlik kartında görsel slot sözleşmede var; birçok kart metin ağırlıklı.
6. Discover’da kulüpler spor/yemek üstüne çıkmamış (sözleşmede gap).
7. Admin mobil kabuk — hedef masaüstü; bugün telefonda sıkışık CMS.
8. Çift boşluk/token: `ArucadSpacing` (6/10/16/22/32) ile sözleşme (20–24 yatay, 24–32 bölüm). İki sistem yan yana.

### EKSİK (sözleşme istiyor)

- Achievements.
- Topluluklar bloğu (Sosyal).
- Bandabuliya canlı program.
- Kariyer hub’ın tam iş ilanı / staj / mezun ağı.
- Yardım’ın tek “neye ihtiyacın var?” fan-out’unun ürün ağırlığı.
- Galeri / albüm (feed’den ayrı, varsayılan gizli).
- Bildirim kategorileri (Sosyal / Topluluk / Etkinlik / Servis / Kampüs / Güvenlik) — bugün dar inbox + local check-in.
- 360 kat/oda drill-down.
- Gerçek routing.
- İnsan görsel moderasyon kuyruğu.
- Poster → taslak.

### Etkileşim borçları (kod doğru, sahada şüphe)

Harita ortalama, mavi konum, nabız renkleri, Ask ARUCAD büyütme — kod okumasıyla mantıklı; tıklanarak kilitlenmemiş.

---

## 13. Tema — uyan / uymayan

### Resmî kural (iki katman)

1. **Logo:** ARUCAD kırmızı `#E4002B` / mavi `#10069F` / sarı `#FBE122` / gri `#4B4F54` — dokunulmaz.
2. **Uygulama kromu:** charcoal / grafit / navy / slate + sakin mavi. Kırmızı/sarı **UI kromu değil**.

`ArucadColors` bu kararı tutuyor: `primary #2B4C7E`, `navy`, `slate`, `blue #3B7DD8`, `paper #F6F7F9`. Seviye rampı grafit → premium mavi. Durum renkleri `success / warning / danger` ısı haritası için.

Kategori aksanları (adaçayı, bal, kiremit, gül) kartları çeşitlendirmek için; marka kırmızısı/sarısı değil.

### Uyan

- Material 3 tema `ArucadColors` üzerine kurulu.
- Ham `Colors.red` / `Colors.yellow` taraması uygulama kodunda pratikte yok; mavi kullanımlar `ArucadColors.blue`.
- Logo varlıkları ayrı (`ARUCAD_MAIN_LOGO.jpg`).
- Heatmap yeşil/sarı/kırmızı **yoğunluk anlamı**, marka paleti değil — sözleşme bunu status olarak açıyor.

### Uymayan / risk

1. **Honey `#F2D388`** sıcak sarı — “kromda sarı yok” kuralına yakın duruyor; kart aksanı olarak bilinçli ama tam tarama yapılmamış (EKSIKLER #6/#29).
2. **Danger `#BC4050`** kırmızıya yakın; hata/yoğun için OK, buton kromunda kaçınılmalı.
3. `ColorScheme.fromSeed` + kopya: bazı Material varsayılanları (ripple, overlay) palette dışına kayabilir.
4. Admin vs öğrenci görsel dili **ayrışmamış** — aynı `ArucadTheme`, CMS yoğunluğu mobil öğrenci temasıyla aynı.
5. Dark theme yok; kampüs gecesi / AMOLED yok.
6. Google Fonts yüklenmezse Montserrat düşer → marka tipi kaybı.
7. Tam ekran palet audit’i yapılmamış; yeni ekranlar izole tasarlanabilir (sözleşmenin asıl korkusu).

---

## 14. Test, CI, yayın

| Katman | Durum |
|---|---|
| Flutter test | İnce |
| `verify_rest_backend.dart` | REST canlıyken sözleşme turu |
| PHPUnit | Feature testleri var; sayı dokümanlarda 26–38 arası **tutarsız** |
| GitHub Actions | analyze + test + web build + phpunit; **bu ortamda yeşil koşu doğrulanmamış** |
| Hosting / HTTPS / KVKK | EKSİK |
| Mağaza | Paket adı, imza, iOS ikon, gizlilik metni EKSİK |

---

## 15. Olgunluk skoru (özet)

| Alan | 10 üzerinden | Neden |
|---|---|---|
| Öğrenci senaryo zenginliği | 8 | Home–Sosyal–Hizmet–Skor anlatılabilir |
| Tasarım sistemi uyumu | 6 | Palet iyi; kabuk 6 sekme, nested nav |
| Backend işlev | 7 | Çok uç; iş kuralları (yoklama, slot, moderasyon) var |
| Kimlik / çok kullanıcı | 2 | `User::first()`, Mock şifre, Entra bekliyor |
| Veri / SQL prod | 5 | SQLite prototip; şema dökümü eski |
| API güvenlik | 3 | Auth yok, CORS kırılgan, admin açık |
| Android | 5 | Çalışır; example id + debug imza |
| iOS | 2 | İskelet, test yok |
| Admin CMS | 6 | Geniş; yemek/medya/ayar local; tek dosya |
| Yayın / gözlem | 1 | Sentry yok, ortam yok, mağaza yok |

---

## 16. Önerilen gidişat (öncelik)

Kod yazılmadı; sıra önerisi:

### P0 — “REST’i tarayıcıda gerçekten aç” (1–2 gün)

1. Web için `API_BASE_URL=http://localhost:4000/api/v1`; Android emülatörde `10.0.2.2`.
2. CORS: `localhost` / `127.0.0.1` her port (veya debug origin pattern).
3. İsteğe bağlı `GET /api/v1` veya `/api/v1/health` — base URL 404 olmasın.
4. README / TESTING’de “düz flutter run = mock” cümlesini kalın tut.

Bunlar olmadan web + Laravel demo **yine CORS/host yüzünden düşer.**

### P1 — “İki kişi aynı kampüs” (asıl ürün eşiği)

1. `POST /auth/session` (veya Entra token verify) + Sanctum + `currentUser()` = token sahibi.
2. `liked_by_me` yerine gerçek `post_likes` (bu iş geri alınmıştı).
3. Admin route’larında permission middleware.
4. Flutter `ApiClient`’a gerçek bearer (main’deki adapter’ı bağla).

Bunsuz sosyal, XP, ban, mesaj **demo yalanı** olarak kalır.

### P2 — Sözleşme ve tek kaynak

1. Ask ARUCAD’ı 6. sekmeden çıkar; Home arama + sheet.
2. Yemek Flutter’ı backend günlük menü uçlarına taşı.
3. Medya: `dataUri` → gerçek URL.
4. Site ayarlarını (Entra/Groq görünürlüğü) sunucuya al.
5. `API_CONTRACT.md` ve `EKSIKLER.md` / `EXTERNAL_ACCOUNTS.md` çelişkilerini tek gerçek yap.
6. `schema.sql`’i migration’dan yeniden üret.

### P3 — Native ve yayın

1. `applicationId` / bundle id + Entra redirect.
2. Release imza, iOS ikon, gerçek cihazda harita/konum.
3. Firebase dosyaları + messaging (web stratejisi ayrı).
4. SMTP `.env`.
5. Postgres + HTTPS host.

### P4 — Deneyim derinliği (özellik eklemeden önce P0–P2)

Achievements, galeri, Bandabuliya programı, kariyer hub, 360 drill-down, gerçek routing, bildirim kategorileri.

Sözleşmenin kendi sırası: **Home → Social → Discover → Service → Academic/Help → Score → Profile → Event/Club → Ask → Map → 360 → Notifications → Admin web.** Login/Entra sona yakın — çünkü sahte giriş ürünü “doğru” göstermez; P1 yine de bloklayıcı.

---

## 17. Demo / yöneticiye dürüst cümleler

**Söylenebilir**

- Öğrenci kabuğu (Home, Keşfet, Sosyal, Skor, hizmet detayı, harita, Ask ARUCAD) tasarlanmış ve dolu.
- Admin’den etkinlik / yoklama / anket / kulüp (REST) gerçek kayda gidebiliyor.
- Marka kararı net: logo resmi, uygulama kromu sakin lacivert.
- “Sahte başarı toast’ı yok” kuralı kod kültüründe var.

**Söylenmemeli**

- “Giriş Microsoft; her öğrenci kendi verisi.” (Mock domain + tek DB kullanıcısı.)
- “Web’den API’ye bastım, çalışır.” (CORS + 10.0.2.2.)
- “iOS hazır.” (İskelet.)
- “Şema.sql = canlı DB.” (Eski döküm.)
- “Push / gerçek e-posta / canlı sohbet var.”

---

## 18. Kapanış

Proje **özellik olarak erken-olgun bir kampüs prototipi**, **platform olarak erken-orta**. Darboğaz harita veya renk değil: **kimlik, çok kullanıcılık, web-API köprüsü, iOS/mağaza, ve admin’in hâlâ cihazda kalan üç adası (yemek, medya, site ayarı).**

Gidişatı görmek için tek metrik: *İki gerçek @arucad.edu.tr hesabı, iki cihaz, aynı backend — biri post atınca diğeri görüyor mu, beğeni izolasyonu var mı, admin diğerini yasaklayabiliyor mu?* Bugün cevap **hayır**. O evet olunca ürün cümlesi değişir.
)
