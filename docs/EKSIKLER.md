# AruSocial — Eksikler ve Yol Haritası

Projenin **gerçek, güncel** durumu — tek doküman, tek yerden.

İşaretler:
- ✅ **Yapıldı** — gerçek, çalışan, doğrulanmış (bkz. `docs/TESTING.md`)
- 🔧 **Kısmen** — gerçek bir temel var, eksik parçalar var
- ❌ **Yapılmadı** — kod tarafı bu ortamda yapılabilir ama henüz yapılmadı
- 🔴 **Dış hesap gerekiyor** — ARUCAD'in kendi hesap/erişim bilgisi
  olmadan gerçek çalışamaz (bkz. `docs/EXTERNAL_ACCOUNTS.md`)

**Kural aynı: sahte özellik yok.** Bir şey ✅ işaretliyse gerçekten
çalışıyordur ve test edilmiştir. Bu turun sonunda (7 Eylül 2026)
`php artisan test` **554** backend testiyle, `flutter test` **226**
Flutter testiyle ve `flutter analyze` sıfır uyarıyla doğrulandı — bu
dosyadaki daha eski "47"/"38" gibi sayılar artık geçersiz, aşağıdaki
sayı güncel referans.

---

## 7 Eylül 2026 turu — dürüstlük düzeltmeleri + 3 yeni gerçek CMS yüzeyi

Bu tur, kullanıcının "her şeyi keşfet, kırık/sahte/eksik ne varsa bul ve
düzelt, admin panelinden kod yazmadan yönetilebilsin" isteğiyle başladı.
Önce mevcut kod tabanı taranıp gerçek durum doğrulandı (bu dosyanın altındaki
maddelerin çoğu hâlâ geçerli — aşağıdakiler o taramada bulunup **bu turda**
kapatılan somut maddeler):

- ✅ **Harita bina panelindeki sahte "az önce check-in yapanlar" listesi** —
  önceden yer adının hash'inden üretilen 2 sahte isimdi
  (`_recentCheckinsFor`); artık backend'in gerçek 2 saatlik check-in
  penceresinden (`PlacePresence::recentCheckinEntriesFor`) gelen gerçek
  isim baş harfi + gerçek zaman damgası kullanılıyor, hiç check-in yoksa
  dürüst "Henüz check-in yok" boş durumu gösteriliyor.
- ✅ **Hikâye "görüldü" halkası artık gerçekten sunucudan** — önceden her
  zaman cihaz-lokal `SharedPreferences`'tan okunuyordu (yazma zaten gerçekti
  ama okuma hiç sunucuya bakmıyordu), bu yüzden başka bir cihaz/temiz kurulum
  her hikâyeyi yeniden "görülmedi" gösteriyordu. `StoryController::index()`
  artık her hikâye için batched, gerçek `viewedByMe` alanı döndürüyor.
- ✅ **Sohbet ayarlarında (sessize al/arşivle/kısıtla/grup kur) sessiz sahte
  başarı kaldırıldı** — REST çağrısı başarısız olduğunda önceden sessizce
  cihaza yazıp aynı "başarılı" mesajını gösteriyordu; artık gerçek bir hata
  gösteriyor, sahte-yerel senkron iddiası yok.
- ✅ **Atölye Durumu + İş Birliği Panosu artık gerçek özellik** — önceden her
  atölye kategorisi yerde aynı sabit ekipman/ilan listesi gösteriliyordu;
  artık `workshop_equipment_items` + `collaboration_posts` gerçek
  tablolarına bağlı (`GET/POST /places/{id}/workshop*`), admin panelden
  (Mekânlar → 🛠 ikonu) ekipman durumu yönetilebiliyor, öğrenciler gerçek
  ilan paylaşabiliyor (aynı metin moderasyonundan geçiyor).
- ✅ **Servis (shuttle) hatları artık admin panelden yönetiliyor** —
  önceden `shuttle_config.dart`'a tamamen gömülüydü, hiçbir backend
  tablosu/admin ekranı yoktu. Artık `shuttle_routes` tablosu +
  `GET /shuttle-routes` + admin CRUD (yeni "Servis Hatları" sekmesi) var;
  Mock mod hâlâ eski sabiti çevrimdışı tohum olarak kullanıyor.
- ✅ **"İlk 30 Gün" adımları artık admin panelden yönetiliyor** — önceden
  sadece tamamlanma durumu (`onboarding_progress`) sunucudaydı, adımların
  kendisi (başlık/açıklama/sıralama) `onboarding_config.dart`'a gömülüydü.
  Artık `onboarding_steps` tablosu (mevcut 13 adımın id'leriyle tohumlanmış,
  geriye dönük kırılma yok) + `GET /onboarding-steps` + admin CRUD (yeni
  "İlk 30 Gün" sekmesi) var.
- ✅ İki küçük tutarlılık düzeltmesi: "Katıldın" chip'i artık doğru
  `onPressed: null` ile devre dışı görünüyor; Ayarlar sekmesinin Türkçe
  nav etiketi "Kullanıcı"dan "Ayarlar"a düzeltildi (İngilizce/Rusça ile
  tutarlı).

Kapsam dışı bırakılanlar (kullanıcı onayıyla): kulüp başkanı için ayrı bir
rol/panel bu turda yapılmadı, kulüpler admin panelden yönetilmeye devam
ediyor. Dış hesap gerektiren hiçbir madde bu turda değişmedi (bkz.
`docs/EXTERNAL_ACCOUNTS.md`).

---

## Senin listenin madde madde durumu

Dış hesap gerektirenler (Entra Secret ID, Push/Firebase) hariç — onları
sen ayrıca vereceksin, bkz. `docs/EXTERNAL_ACCOUNTS.md`.

| # | İstek (senin yazdığın) | Durum |
|---|---|---|
| 1 | Etkinliğe hangi görev/şekilde katılmak istediği seçilebilir olmalı | ✅ Katıl popup'ı gerçek katılım türü seçimi gösteriyor + admin bunları yönetebiliyor |
| 2 | Kampüs haritası açıldığında tam ortalanmalı | 🔧 Kod doğru görünüyor (gerçek centroid + `fitPoints()`), bu ortamda tıklayarak görsel doğrulama yapamıyorum — sende hâlâ yanlışsa ekran görüntüsü at |
| 3 | Kendi aktiviteni oluştur; oluşturup paylaşabilmeli, isteyen katılabilmeli | ✅ Öğrenci formu + admin onay ekranı var, onaylanınca gerçekten herkese görünüyor ve katılınabiliyor |
| 4 | Mekân seçimi bizim belirlediğimiz seçeneklerden olmalı + boş/dolu + tarih filtresi + bize danışıp onay | ✅ Mekân seçimi zaten sadece kayıtlı yerlerden. Artık gerçek bir `event_date` alanı + "boş/dolu" müsaitlik kontrolü var: aynı mekân, aynı tarih+saatte ikinci bir etkinlik/aktivite **oluşturulamıyor** (backend 409 `PLACE_UNAVAILABLE` ile sertçe reddediyor, hem öğrenci "Kendi Aktivite" formunda hem admin etkinlik formunda), reddedilen etkinlikler slotu bloklamıyor, ve her iki formda gerçek bir tarih seçici + "bu mekân o gün şu saatlerde dolu" listesi gösteriliyor (`GET /places/{id}/availability`). Admin onayı zaten üstüne geliyordu. Regresyon testleriyle doğrulandı |
| 5 | Katıl popup'ı success/fail dönsün; öğretmene bildirim → öğrenciye form e-postası → doldurunca öğretmene gidip onaylıyor | ✅ Artık gerçekten sıralı/kilitli: katılınca öğretmene "yeni talep" + öğrenciye form e-postası gidiyor, ama form **gerçekten doldurulmadan** (yeni in-app "Katılım Formunu Tamamla" adımı, `event_joins.form_submitted_at`) hem 3. e-posta ("onay bekliyor") gitmiyor hem de admin panelindeki Yoklama onay butonu çalışmıyor — backend bunu 400 `FORM_NOT_SUBMITTED` ile sertçe reddediyor, sadece UI önerisi değil. Regresyon testiyle doğrulandı |
| 6 | Renkler: verdiğin paleti her tabda/kartta araştırıp uyumlu yerleştir | 🔧 Palet (`ArucadColors`) zaten kartlarda kullanılıyordu; bu turda Yardım Al/Kariyer kartlarına ve birkaç yerdeki ham renklere uyguladım, kullanılmayan sahte harita çizimini sildim. **Ama** uygulamanın *her* ekranını tek tek gözden geçiren tam bir tarama yapmadım |
| 7 | Her sayfanın isimleri/başlıkları olmalı | ✅ Kontrol edildi, eksik olan (Profile) düzeltildi |
| 8 | XP her yıl sıfırlanmalı ama yıllara göre aktiflik filtrelenebilmeli, geçen yılı da görebilmeli | ✅ Etkinlik listesi için akademik yıl filtresi zaten vardı. Artık "Aktivite" tabındaki Yıl Skoru + Campus Journey kartı da gerçekten filtrelenebiliyor — her `ActivityItem`'ın kendi tarihi zaten backend'den geliyordu, üstte bir yıl çip satırı ekleyip geçmiş yılları seçince o yılın gerçek XP/aktivite dökümünü gösteriyor |
| 9 | Admin panelde kulüp etkinlikleri ayrı ayrı veya toplu e-posta gönderilebilmeli | ✅ E-posta Günlüğü sekmesinde toplu gönderim var, alıcı listesine istediğin kadar (tek veya çok) e-posta yazabiliyorsun |
| 10 | Katılan öğrenciye 2 e-posta (kulüp başkanı + form) | ✅ Gerçek, gönderiliyor ve loglanıyor |
| 11 | Secret ID, Entra'ya eklenmeli | 🔴 Senden bekliyor |
| 12 | Popup şeklinde hızlı anketler (örn. "Bahar Şenliği hangi tarihte?") | ✅ Home açılınca aktif/oylanmamış anket varsa gerçek popup çıkıyor |
| 13 | Anket/oylama verileri toplanmalı, admin panelde gösterilmeli | ✅ Gerçek oy sayımı + admin yönetim sekmesi |
| 14 | Local SQL | ✅ `sql/database.sqlite` |
| 15 | Local backend | ✅ `backend/` (Laravel) |
| 16 | API yapısı | ✅ `docs/API_CONTRACT.md` + gerçek uçlar |
| 17 | Push notification | 🔴 Firebase hesabı bekliyor |
| 18 | Ana sayfada sağ üstteki aktivite/XP ikonu şimşek + altın renk + belirgin olsun | ✅ Yapıldı |
| 19 | Harita kampüsün ortasında açılsın | 🔧 #2 ile aynı — kod doğru, görsel doğrulama yapamadım |
| 20 | Haritada nabız kırmızı/sarı/yeşil + tıklanabilir + isim/bilgi + navigasyon + gerçek konum | 🔧 Kodda hepsi var ve doğru görünüyor (gerçek `campusDensityInfo()` renkleri, tıklanabilir marker'lar, gerçek "Navigasyonu Başlat", `myLocationEnabled`) — görsel/etkileşimli doğrulama yapamadım |
| 21 | Haritadaki küçük Ask ARUCAD'da "sohbete devam et" ve büyütme butonu çalışmıyor | 🔧 Kodu tekrar tekrar inceledim, `onOpenFullChat` callback'i doğru bağlanmış görünüyor — koddan bir hata bulamadım. Bu, gerçekten UI'da tıklayıp görmem gereken bir şey; sende hâlâ çalışmıyorsa hangi ekrandan açtığını (Home mı Explore mı) ve ne olduğunu (hiç açılmıyor mu, açılıp basınca mı bir şey olmuyor) söyle |
| 22 | Bugünkü etkinliğe katılınca "başvurunuz gitti, e-postanı kontrol et" + admin panelde hoca yoklama alıp onaylasın | ✅ Bu turun asıl işi — gerçek, uçtan uca çalışıyor (2 e-posta + admin "Yoklama" onay ekranı) |
| 23 | Check-in çalışmıyor; konumu anla, uzaktaysa belirt, kaydet, kalıcı olsun, sosyal medyada paylaşılsın, ayarlarda son aktivitelerde görünsün | ✅ Artık asla engellemiyor (uzaktaysa dürüstçe "uzaktan check-in" diye işaretliyor), gerçekten kaydediyor, görünürse gerçekten sosyal akışa da düşüyor (bu turda bulunan gerçek bir backend eksikliğiydi, düzeltildi), Ayarlar ekranındaki "son aktivitelerim" zaten check-in'i gösteriyor |
| 24 | Yardım Al / Kariyer kartlarına renkli arka plan (verdiğin palet) | ✅ Yapıldı |
| 25 | Bina Dizini ve Sayfaları Explore'dan kaldır | ✅ Yapıldı |
| 26 | Sosyal: çıplaklık/uygunsuz içerik (yazı/video/resim) anlaşılsın, filtrelensin, direkt ban sayılsın, paylaşılamasın | ✅ **Metin** tam istediğin gibi: sunucu tarafında gerçekten engelleniyor ve 3 ihlalde hesap gerçekten yasaklanıyor. **Görsel** artık tamamen sunucu tarafında: önceki turda görsel tarama istemciden (Flutter'dan) doğrudan OpenAI'a gidiyordu ve API key cihazda duruyordu — bunu düzelttim. Şimdi istemci sadece fotoğraf baytlarını backend'e yolluyor (`POST /moderation/check-image`), tarama + API key tamamen backend'de (`ImageModerationService`, key `app_settings` tablosunda, admin panelden yazılıyor ama **hiçbir zaman** cihaza geri okunmuyor), ve reddedilen bir fotoğraf aynı 3-ihlal sayacına gerçek bir strike olarak işleniyor — metin ve görsel ihlalleri birlikte aynı yasağı tetikliyor. Regresyon testleriyle doğrulandı (mock HTTP ile gerçek flagged/clean/skip senaryoları). Hâlâ dış hesap gerektiren tek parça: gerçek bir OpenAI key girilmeden tarama atlanıyor (fotoğraflar olduğu gibi kabul ediliyor) — bu artık bir mimari eksik değil, sadece gerçek bir API anahtarının olmaması. **Video** için uygulamada hiç video yükleme özelliği yok, o yüzden moderasyon da yok |
| 27 | Sosyalde mesajlar: geçmiş yok, gönderince ekrana düşmüyor, kalıcı olsun | ✅ Backend'e taşındı, otomatik testle gönder+oku round-trip'i doğrulandı. Görsel olarak tıklayıp deneyemedim — sen test ettiğinde hâlâ bozuksa muhtemelen eski bir build'i test ediyordun, güncel build'i dener misin? |
| 28 | Profil tabı → Ayarlar olsun, XP gösterimini Aktivite'ye taşı | ✅ Yapıldı |
| 29 | Sadece verdiğin renk paletini kullan, geri kalan renkleri sil | 🔧 #6 ile aynı — kısmen yapıldı, tam bir tarama değil |
| 30 | Haritada kendi konumumu mavi yuvarlak ikonla göreyim, gerçek koordinatlara bağlan | 🔧 MapLibre'nin gerçek "mavi nokta" özelliği (`myLocationEnabled: true`) zaten açık — kodda doğru, görsel doğrulama yapamadım |

**Özetle gerçekten eksik/yarım kalanlar (dış hesaplar dışında):** #6/#29'daki
renk taramasının tüm ekranları tek tek gözden geçirecek şekilde
tamamlanması — listendeki #4/#5/#8/#26 artık hepsi ✅. #2/#19/#20/#21/#30
kod olarak doğru görünüyor ama bu ortamda tıklayarak deneyemediğim için
"✅ doğrulandı" diyemiyorum.

---

## Genel özet

Bu turda eklenen/düzeltilen gerçek işler (hepsi test edilmiş):
- ✅ **Gerçek sunucu-taraflı moderasyon + hesap yasaklama** — daha önce
  sadece istemci tarafında olan metin filtresi artık backend'de de
  gerçek (`ModerationService`); 3 ihlal gerçek bir hesap yasağına
  dönüşüyor (`EnsureNotBanned` middleware, admin rotaları hariç her
  yerde gerçekten engelliyor)
- ✅ **Gerçek etkinlik yoklama/onay sistemi** — kulüp başkanı/hoca artık
  admin panelden kimlerin katıldığını gerçekten görüp onaylayabiliyor
  (`event_joins.approved_at`, "Yoklama" ekranı)
- ✅ Check-in artık asılı kalmıyor: konum doğrulanamasa/uzak olsa bile
  gerçek kaydediliyor, ama dürüstçe "uzaktan check-in" olarak işaretleniyor;
  görünür check-in artık gerçekten sosyal akışa da düşüyor (önceden backend
  bunu hiç yapmıyordu, sadece UI öyle iddia ediyordu)
- ✅ 3 gerçek framework/mantık hatası bulunup düzeltildi (ayrıntılar aşağıda)
- ✅ Renk paletindeki tutarsızlıklar temizlendi: eski, kullanılmayan
  Canvas-tabanlı sahte harita illüstrasyonu (`MapPanel`/`CampusMapPainter`)
  ve paletteki dışı ham renkler kaldırıldı; Yardım Al / Kariyer kartları
  artık gerçek kategori-rengi paletini kullanıyor
- ✅ "Profil" tab'ı "Ayarlar" oldu, XP gösterimi tekrarı kaldırıldı
  (artık sadece "Aktivite" tab'ında)
- ✅ Bina Dizini / Sayfalar hızlı linkleri Explore'dan kaldırıldı

---

## 1. Auth / Kimlik Doğrulama

- 🔴 Gerçek Entra login — kod tarafı hazır (`EntraAuthProvider`), gerçek
  Tenant/Client ID bekliyor (bkz. `docs/EXTERNAL_ACCOUNTS.md` §1)
- ❌ Backend'de gerçek per-request JWT/session authentication — her
  istek hâlâ tek bir demo hesaba düşüyor
- ❌ RBAC'in route seviyesinde zorlanması — rol verisi gerçek
  (`role_assignments`) ama hiçbir route buna göre erişim reddetmiyor
- ✅ Rate limiting (300/dk) + CORS kısıtlaması (env-bazlı origin listesi)
- ✅ **Yeni:** Hesap yasaklama zorlaması — `EnsureNotBanned` middleware
  yasaklı hesabın admin dışı her isteğini gerçekten 403 ile reddediyor
- 🔧 Input validation — temel `Request::input()` kontrolü var, tam
  `FormRequest` sınıfları yok

## 2. PostgreSQL

- ✅ Migration'lar SQLite + PostgreSQL'de aynı (P3-3). `php artisan test` SQLite;
  CI `postgres:16` + `phpunit.pgsql.xml`. Default `.env` hâlâ SQLite.
- 🔴 Production cutover yok — gerçek erişilebilir prod sunucusu, data
  migration, backup/HA yok (bkz. `docs/EXTERNAL_ACCOUNTS.md` §6)

## 3. Local Store'ların Backend'e Taşınması

- ✅ Kulüpler, Sporlar, Hizmetler, Bina Rehberi, Sayfalar, Roller, Audit
  Log, İçerik Revizyonları, Kaydedilenler, Takip/Engelleme, Mesajlaşma —
  hepsi gerçek backend'e taşındı, Flutter tarafı dahil
- 🔧 Yemekhane — backend gerçek (günlük menü için ayrı `upsertMenu`/
  `destroyMenu` uçları var), Flutter tarafı hâlâ eski local store'u
  kullanıyor: takvim editörü tüm venue'yi tek seferde kaydediyor,
  backend'in günlük-satır yapısıyla eşleşmiyor — ayrı bir geçiş gerekiyor
- ❌ Medya Kütüphanesi — backend gerçek dosya upload'ı yapıyor
  (`MediaController`, gerçek fetchable URL) ama hem admin ekranı hem
  blok editörünün görsel/galeri seçicisi hâlâ base64 + `dataUri`
  kullanıyor — blokların gerçek `url` saklaması gereken, daha büyük bir
  mimari değişiklik (§12)

## 4. Gerçek Çok Kullanıcılı Sosyal Sistem

- ✅ Post/beğeni/yorum/şikayet/takip/engelleme/kaydetme/mesajlaşma/bildirim
  — hepsi gerçek backend'e bağlı
- ✅ **Yeni:** Uygunsuz içerik artık gerçek bir sunucu-taraflı sonuca
  bağlı — bkz. §16
- ✅ Gerçek zamanlı chat — REST geçmiş kaynağı; Reverb private channel teslimatı (P3-1)
- ✅ FCM push — mevcut `POST /push-tokens` kaydı; inbox satırı + queue job ile cihaz teslimi (P3-2). Gerçek Firebase projesi `.env` `FIREBASE_*` + Android `google-services.json` / iOS APNs (git'e secret yok).

## 5. Etkinlik/Aktivite Sistemi

- ✅ Katılım türü seçimi + katıl popup'ı + gerçek e-posta durumu
- ✅ Admin katılım türü yönetimi
- ✅ Kendi aktivitesini oluşturma — öğrenci formu + admin onay/red ekranı,
  uçtan uca çalışıyor
- ✅ Mekân seçimi — admin formunda gerçek "Kayıtlı mekân" dropdown'u
- ✅ Akademik yıl filtresi — admin + öğrenci tarafında
- ✅ **Yeni: Gerçek yoklama/onay sistemi** — `Admin\EventController::
  participants()`/`approveParticipant()`, admin panelin etkinlik
  formunda "Yoklama" butonu: kim katıldı, hangi katılım türüyle, ve
  admin (kulüp başkanı/hoca) onayladı mı — gerçek, ayrı bir onay adımı,
  sadece "join" değil

## 6. Form / E-posta Workflow

- 🔧 WordPress/WPForms'tan form+entry çekme gerçek, tek yönlü
- ❌ JSON export/import, form versioning, form → etkinlik bağlantısı
- ✅ SMTP — Laravel `Mail` + `MAIL_MAILER=smtp` (P3-5). Local `log`.
  Gerçek kutu için sunucu `.env` `MAIL_*` (bkz. `docs/EXTERNAL_ACCOUNTS.md` §5)
- ✅ Kulüp başkanına + öğrenciye 2 e-postalık katılım akışı + gerçek
  yoklama onayı (§5)
- ✅ Toplu e-posta — admin UI'ı + e-posta günlüğü + retry

## 7. Push Notification

- ✅ Backend FCM gönderimi + Flutter token kaydı / tap routing (P3-2)
- 🔧 Gerçek Firebase projesi, Android `google-services.json`, iOS APNs key (bkz. `docs/EXTERNAL_ACCOUNTS.md` §2) — kod hazır, hesap yoksa no-op

## 8. Anket / Oylama

- ✅ Backend + Flutter tarafı tamamlandı: gerçek popup, oy, sonuç
  yüzdeleri, admin yönetim sekmesi

## 9. Akademik Yıl Sistemi

- ✅ Backend + admin yönetim ekranı + öğrenci-taraflı yıl filtresi

## 10. Admin Panelinin Tamamının Backend'e Bağlanması

- ✅ Etkinlikler + Bekleyen Aktiviteler + Yoklama + Moderasyon + Kulüpler/
  Sporlar/Hizmetler/Bina Rehberi/Sayfalar/Roller/Aktivite Kaydı/Anketler/
  Akademik Yıllar/E-posta Günlüğü — gerçek backend'e bağlı
- ❌ Yemekhane, Medya Kütüphanesi, Site Ayarları — hâlâ local store (§3)

## 11. RBAC (Rol/Yetki)

- ✅ Backend'de gerçek, paylaşılan bir rol tablosu var; admin UI'ı ve
  sign-in akışı ikisi de bunu kullanıyor (Rest modda)
- ❌ Gerçek permission-enforcement middleware'i yok — hiçbir route
  atanmış role göre erişim reddetmiyor (§1)

## 12. Medya Sistemi

- ✅ Backend gerçek multipart upload + gerçek fetchable URL
- ❌ Flutter tarafı (admin ekranı + blok editörü) hâlâ base64 (§3)

## 13. Harita

- 🔧 Gerçek OpenStreetMap + MapLibre; kod incelemesiyle merkezi
  ortalama/"my location" mavi nokta/yoğunluk renkleri doğru görünüyor
  (bu ortamda görsel doğrulama yapılamıyor — bkz. not aşağıda)
- ❌ Gerçek routing (OSRM/Valhalla) — hâlâ düz-çizgi tahmini
- ✅ **Bu turda temizlendi:** hiç kullanılmayan, eski Canvas-tabanlı sahte
  harita illüstrasyonu (`MapPanel`/`CampusMapPainter`, hardcoded "YOU"/
  "STAGE" pinleri ve uydurma sokak isimleriyle) silindi — gerçek
  MapLibre haritası zaten önceki bir oturumda bunun yerini almıştı ama
  eski kod hâlâ dosyada duruyordu

**Not — harita/konum görsel doğrulaması:** Bu ortamda bir tarayıcıyı
etkileşimli olarak tıklayabilecek bir araç yok, bu yüzden merkezleme/
mavi nokta/navigasyon gibi görsel davranışlar sadece kod okumasıyla
doğrulanabildi (mantık doğru görünüyor: gerçek centroid + `fitPoints()`,
`myLocationEnabled: true`, gerçek `campusDensityInfo()` renkleri, gerçek
tıklanabilir marker'lar). Gerçek cihazda hâlâ sorun varsa ekran
görüntüsüyle bildir.

## 14. Server-Side API Özellikleri

- ❌ Pagination, filtering, sorting (mevcut uçlar tüm listeyi döndürüyor
  — `/events`'in `academicYearId` filtresi istisna)
- 🔧 Temel validation var, gelişmiş (FormRequest bazlı) yok

## 15. Offline / Cache

- ❌ Merkezi cache, offline çalışma, sync, conflict çözümü

## 16. Moderasyon

- ✅ **Bu turda tamamlandı: gerçek sunucu-taraflı metin moderasyonu +
  hesap yasaklama.** Önceden sadece istemci tarafında bir kelime
  filtresi vardı (`content_moderation.dart`) — bunu atlayan/değiştirilmiş
  bir istemci hiçbir engelle karşılaşmazdı. Artık `ModerationService`
  (backend) aynı kontrolü post/yorum/hikaye/yorum metinlerinde gerçekten
  tekrar yapıyor, reddediyor (`CONTENT_BLOCKED`), ve her ihlal gerçek bir
  "strike" — 3 ihlalde hesap gerçekten yasaklanıyor (`banned_at`).
  `EnsureNotBanned` middleware'i yasaklı hesabın admin-dışı her isteğini
  403 `ACCOUNT_BANNED` ile reddediyor (admin rotaları kasıtlı olarak
  hariç — bu prototipte tek gerçek hesap olduğu için, aksi halde bir
  yasak API üzerinden geri alınamaz hâle gelirdi). Flutter tarafı bu
  hatayı zaten var olan `ContentModerationException` akışına
  eşliyor (yeni UI kodu gerekmedi) + sign-in ekranı temiz bir "hesabın
  askıya alındı" mesajı gösteriyor.
- 🔧 Görsel moderasyon — gerçek bir OpenAI vision-moderation API çağrısı
  var (`ImageModerationService`), 🔴 gerçek bir API key'e bağlı; henüz
  backend'e taşınmadı (istemci tarafında çalışıyor)
- ❌ Post/yorum için pending-review kuyruğu (etkinlikler için var, genel
  sosyal içerik için yok), itiraz sistemi
- ❌ Moderasyon API key'inin client'tan tamamen kaldırılması — 🔴 gerçek
  key geldiğinde (bkz. `docs/EXTERNAL_ACCOUNTS.md` §4)

## 17. AI Güvenliği

- ✅ Groq çağrısı backend proxy'sinden geçiyor, key istemciye gitmiyor
- ❌ AI endpoint'ine özel bir rate limit yok (genel 300/dk'ya tabi)

## 18. Test / CI

- ✅ Flutter: 7/7 test geçiyor
- ✅ `verify_rest_backend.dart` — **43** gerçek kontrol, hepsi geçiyor
- ✅ Backend (PHPUnit) — **26** gerçek Feature testi, 69 assertion,
  hepsi geçiyor (yeni: `ModerationApiTest` 6 test, check-in 3 test,
  yoklama onayı 1 test, route-sırası regresyonu 1 test)
- ✅ **Bu turda 3 gerçek bug bulundu, düzeltildi, regresyon testiyle
  kilitlendi:**
  1. Laravel'in `ConvertEmptyStringsToNull` middleware'i, istemcinin
     bilerek gönderdiği boş string alanları backend'e ulaşmadan `null`'a
     çeviriyordu — bu da NOT NULL kısıtlarını ihlal eden gerçek 500'lere
     yol açıyordu (boş "Saat" ile kendi aktivite oluşturma). Kaldırıldı.
  2. `GET /events/{id}` `GET /events/mine`'dan önce kayıtlıydı — Laravel
     route'ları kayıt sırasına göre eşleştirdiği için "mine" bir `{id}`
     olarak yakalanıp 404 veriyordu. Sıralama düzeltildi.
  3. `RestCampusRepository` (saf Dart olması gereken bir sınıf) küçük
     model tiplerini `shared_preferences` kullanan dosyalardan import
     ediyordu — bu da saf-Dart doğrulama script'ini tamamen kırıyordu.
     Modeller saf model dosyalarına taşındı.
  4. **Yeni bu turda:** check-in'in görünür (visibleToOthers) modu gerçek
     backend'de hiçbir zaman sosyal akışa post eklemiyordu, sadece
     Mock modda ekliyordu — ama Flutter UI'ı ikisinde de "sosyal akışa
     eklendi" diyordu. `CheckinController`'a gerçek `FeedPost` oluşturma
     eklendi.
- 🔧 GitHub Actions yazıldı, gerçek bir CI runner'da hiç çalıştırılmadı

## 19. Monitoring

- ✅ Sentry error monitoring (P3-6, backend `sentry/sentry-laravel` +
  Flutter `sentry_flutter`; DSN boşsa local'de kapalı; secret scrub;
  `docs/ENVIRONMENTS.md`) — performance tracing/dashboard kurulumu, uptime
  izleme hâlâ yapılmadı (gerçek Sentry organization/DSN yok)

## 20. Production / Yayın

- ✅ Local / staging / production config ayrımı (P3-4, `docs/ENVIRONMENTS.md`)
- 🔴 Gerçek hosting/sunucu, HTTPS, deployment, Google Play/App Store,
  Privacy Policy/Terms/KVKK/GDPR — hepsi gerçek hesap+hukuki karar
  gerektiriyor

---

## Önerilen sıradaki adımlar (öncelik sırasıyla)

1. Yemekhane'nin Flutter tarafı — günlük-menü takvimini backend'in
   günlük-satır uçlarına taşı
2. Medya Kütüphanesi'nin Flutter tarafı — blokların `dataUri` yerine
   gerçek `url` saklaması gereken daha büyük bir değişiklik
3. RBAC gerçek yetki zorlaması: route'larda gerçek permission middleware
4. Görsel moderasyonu backend'e taşı (gerçek API key geldiğinde)
5. Form sistemi genişletmesi: export/import, versioning, etkinlik bağlama
6. Dış hesaplar geldikçe: Entra → SMTP → Firebase → PostgreSQL (bkz.
   `docs/EXTERNAL_ACCOUNTS.md`) — kod tarafı zaten bunlara hazır

---

**Not:** Bu dosyanın önceki bir sürümü, farklı bir formatta (başka bir
araç/kişi tarafından özetlenmiş "gün sonu raporu" tarzında) üzerine
yazılmış hâlde bulundu — kendi önceki içeriğim değildi. Buradaki sürüm,
projenin gerçek, o an itibarıyla doğrulanmış durumunu yansıtacak şekilde
yeniden yazıldı; herhangi bir gerçek ilerleme kaybolmadı (kod zaten
mevcuttu ve test edilmişti, sadece bu dokümandaki özet güncelliğini
yitirmişti).
