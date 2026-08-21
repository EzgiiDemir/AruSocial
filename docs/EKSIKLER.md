
### ✅ Bu turda tamamlanan gerçek altyapı (Prompt 1/5 — kısmen)

1. **Gerçek JWT/session authentication** — `AuthController::session()` gerçek
   bir Sanctum bearer token'ı ilgili `users` satırına bağlı olarak veriyor;
   `/v1` altındaki her route artık `auth:sanctum` gerektiriyor,
   `ApiResponds::currentUser()` gerçek authenticated kullanıcıyı çözüyor
   (artık her zaman `User::first()` değil). Flutter tarafında `SessionStore`
   (token/email/rol) + `SessionTokenAdapter` ile **gerçek oturum kalıcılığı**:
   uygulama yeniden açıldığında/sayfa yenilendiğinde tekrar login ekranına
   düşülmüyor, saklanan token geçerliyse oturum geri yükleniyor. Token
   geçersizse (backend'in dev DB'si sıfırlandıysa vb.) gerçek bir hataya
   düşüp temiz şekilde login'e dönüyor, sessizce takılı kalmıyor.
2. **RBAC permission enforcement** — `EnsurePermission` middleware'i
   `UserRoleLabel`'daki (`campus_models.dart`) canManageContent/canModerate/
   canManageSiteSettings mantığını backend'de birebir uyguluyor
   (`manageContent`/`moderate`/`manageSiteSettings`/`viewAdmin` yetki
   grupları). Artık her `/admin/*` route gerçek rol kontrolünden geçiyor —
   önceden sadece Flutter UI'da buton gizlemekle sınırlıydı, API'ye
   doğrudan istek atan biri her şeyi yapabiliyordu. Yeni `PermissionApiTest`
   gerçek 403 senaryolarını doğruluyor (öğrenci kulüp oluşturamıyor,
   contentEditor moderasyon yapamıyor, sadece superAdmin rol/ayar
   değiştirebiliyor, vb.). Audit log artık **gerçek** kimliği kaydediyor
   (`$this->currentUser()->name`), önceden client'ın gönderdiği `actorName`
   string'ine güveniyordu — bu sahte olabilirdi, artık değil. Eksik olan
   birkaç audit log noktası da eklendi (rapor çözümleme,
   katılım-türü ekleme/silme).
   - Not: `RoleAssignment` tablosu boşken kimse rol atayamayacağı için
     (rol atamak zaten superAdmin gerektiriyor — bootstrap sorunu),
     `DatabaseSeeder` artık seed hesabına (`ege.aydin@arucad.edu.tr`) gerçek
     bir `superAdmin` RoleAssignment satırı ekliyor.
   - Doğrulama: `php artisan test` 53/53, `dart run tool/verify_rest_backend.dart`
     47/47 (artık gerçek bir bearer token ile oturum açıp tüm kontrolleri
     o kimlikle çalıştırıyor).

### ✅ Bu turda tamamlanan (Prompt 2/5 — harita/konum/POI/check-in/heatmap)

1. **Gerçek ARUCAD POI verisi** — 19 gerçek POI (Rodin, Falling Man, Titan,
   Eve, Daniele, Eternal Spring, Meditation, Minotaur, Eternal Idol, The
   Kiss, The Garden, Carpentry Studio, Arkin Rodin Collection Gallery,
   ARUCAD Dormitory, Nicosia Bandabuliya Campus, ARUCAD Art Space, Age of
   Bronze, Art Rooms, Iris) `places` tablosuna gerçek satırlar olarak
   eklendi (`DatabaseSeeder`), 4 demo yerin üstüne. Yeni
   `coordinate_confidence` alanı (`verified`/`needs_verification`) dürüst
   — paylaşılan/adres-türevi koordinatlar (Arkin Rodin Gallery, Age of
   Bronze/Art Rooms/Iris) `needs_verification` olarak işaretli, uydurma
   bir kesinlik iddia edilmiyor. `GET /places` artık 23 gerçek yer
   döndürüyor.
2. **Check-in konum doğrulaması** — zaten bu turdan önce tamamlanmıştı
   (gerçek Haversine mesafe hesabı backend'de, admin-ayarlanabilir yarıçap,
   `"Bu konuma yeterince yakın değilsiniz..."` mesajı) — Prompt 2'nin
   istediği davranışla birebir eşleşiyor, yeniden doğrulandı.
3. **Check-in sonrası Akışta Paylaş / Gizlice XP Kazan** — zaten gerçekti
   (görünürse gerçek FeedPost, her durumda gerçek XP), yeniden doğrulandı.
4. **Gerçek XP ledger'ı** — yeni `xp_transactions` tablosu +
   `XpLedger::grant()` servisi: her XP kazanımı (check-in, etkinlik
   katılımı) artık `users.xp` sayacını artırmanın yanında miktar/neden/
   kaynak-tipi/kaynak-id/tarih içeren kalıcı bir satır bırakıyor.
   `GET /me/xp-transactions` ile okunabiliyor. `XpLedgerApiTest` gerçek
   uçtan uca doğruluyor.
5. **Gerçek heatmap** — yeni `GET /places/density?window=1h|today|7d|30d`,
   gerçek `checkins` tablosundan zaman-pencereli sayım yapıp
   quiet/moderate/busy seviyesi hesaplıyor (dürüst, belgelenmiş eşikler:
   0/1-2/3+). Flutter tarafında harita artık bu gerçek veriyi çekiyor
   (`HeatmapAdapter.pulseZonesFromDensity`) ve haritadaki "Harita
   Bilgileri" panosuna gerçek bir "Yoğunluk Aralığı" seçici eklendi —
   önceden sadece statik seed `density` string'i kullanılıyordu.
   `PlaceDensityApiTest` doğruluyor.
6. **Haritada gerçek GPS merkezleme** — harita açıldığında artık cihazın
   gerçek konumu (varsa) kamerayı oraya odaklıyor
   (`_centerOnUserLocation()`); izin yok/konum alınamıyorsa sessizce
   mevcut "tüm yerlere sığdır" davranışına düşüyor — hiçbir zaman
   engellenmiyor.

### ✅ Bu turda tamamlanan (Prompt 3/5 — aktivite/form/onay/e-posta/roller)

1. **Gerçek akademik personel dizini** — 59 kişilik gerçek ARUCAD roster'ı
   (`AcademicStaffSeeder`) → `academic_staff` tablosu. Kaynak veride
   "e-posta doğrulama gerekli" işaretli 13 kişi `email=null,
   email_verified=false` olarak dürüstçe seed edildi — uydurma e-posta
   yok. 13 bölüm başkanı + 4 fakülte dekanı gerçek `is_department_head`/
   `is_faculty_dean` bayraklarıyla işaretli.
2. **Gerçek bölüm→onaylayan otomatik yönlendirme** — `AcademicRoutingService`,
   gerçek roster'a karşı test edildi (`AcademicRoutingServiceTest`, 5/5).
   Bölüm başkanı yoksa fakülte dekanına düşüyor (örn. Seramik → Sanat
   Fakültesi Dekanı Nur Onat), o da yoksa `null` (uydurma bir yetkiliye
   değil, gerçek "eşleşme yok" durumuna düşüyor). Türkçe "İ" harfinin
   `mb_strtolower`'da yanlış küçültülmesinden kaynaklanan gerçek bir bug
   bulunup düzeltildi.
3. **Genişletilmiş "Kendi Aktiviteni Oluştur" formu + gerçek imzalı web
   formu** — öğrenci numarası, telefon, fakülte, bölüm, bitiş saati,
   tahmini katılımcı, hedef kitle, amaç, gereksinimler, görsel/afiş
   alanları artık gerçek. Akış tam olarak istenen gibi: hızlı oluşturma
   → `form_required` durumu → **gerçek, tek kullanımlık, Laravel'in kendi
   HMAC imzasıyla doğrulanan** bir web formu linki e-postayla gidiyor
   (`ActivityFormController`, `routes/web.php` — tarayıcıda açılan gerçek
   bir HTML sayfası, sahte bir "form" kavramı değil) → form doldurulunca
   otomatik bölüm yönlendirmesi + `pending_approval` durumu → hem
   öğrenciye hem yönlendirilen personele gerçek e-posta. İkinci kez
   doldurulamıyor (durum kontrolüyle). `ActivityFormApiTest` (7/7) gerçek
   imzalı URL, CSRF, validasyon, tekrar-doldurma engeli ve yönlendirmeyi
   doğruluyor.
4. **Gerçek durum modeli** — `pending_review` → `pending_approval` olarak
   birebir istenen sözlüğe hizalandı (`draft/form_required/pending_approval/
   approved/rejected/published/cancelled/completed/archived`), tüm
   backend+frontend referansları güncellendi.
5. **Onay/red artık gerçekten e-posta gönderiyor** — önceden sadece
   audit/activity log'a yazılıyordu, şimdi öğrenciye gerçek "Aktiviteniz
   onaylanmıştır..." / "Aktiviteniz onaylanmamıştır. Değerlendirme
   sonucu: ..." e-postaları gidiyor (kullanıcının verdiği metinlerle
   birebir). Red nedeni artık zorunlu (boşsa 400 döner) — hem backend hem
   Flutter admin ekranı bunu uyguluyor. Onay, form doldurulmadan
   yapılamıyor (`FORM_NOT_SUBMITTED` gerçek engeli, event-join yoklama
   onayındaki aynı ilkeyle).
6. **E-posta ekranı gerçek personel seçici aldı** — "E-posta Günlüğü" →
   "E-posta" olarak yeniden adlandırıldı; admin artık gerçek 59 kişilik
   dizinden arayıp çoklu seçim yapabiliyor (`GET /admin/academic-staff`),
   manuel e-posta girişi de hâlâ mevcut. `AcademicStaffApiTest` (2/2)
   doğruluyor.
7. **Bekleyen Aktiviteler ekranı gerçek verileri gösteriyor** — amaç,
   öğrenci no/telefon, fakülte/bölüm, ve otomatik yönlendirilen personel
   artık admin panelinde görünüyor (önceden sadece başlık/açıklama
   vardı) — bir reviewer'ın gerçekten karar vermesi için gereken bilgi.
   - Doğrulama: `php artisan test` 71/71, `dart run tool/verify_rest_backend.dart`
     51/51, `flutter test` 7/7.

### ✅ Bu turda tamamlanan (Prompt 4/5 — sosyal sistem/chat/arayüz)

1. **Chat gerçekten iki hesap arasında çalışıyor** — önceden mesajlar sadece
   gönderenin kendi kopyasına yazılıyordu (`user_id = sender`), gerçek bir
   ikinci hesap hiçbir zaman mesajı görmüyordu. `ChatController::send()`
   artık `peer` gerçek bir ikinci `User`'a karşılık geliyorsa onun kendi
   thread'ine de gerçek bir satır + gerçek bir `Notification` yazıyor.
   Flutter tarafında da gerçek bir gönderme hatası vardı: metin, istek
   sonucu beklenmeden temizleniyor ve hata sessizce yutuluyordu —
   `_send()` artık sadece gerçek başarıda temizliyor, başarısızlıkta
   görünür bir SnackBar gösteriyor, çift gönderimi `_sending` ile
   engelliyor. Konuşma listesi artık gerçek profil/isim/son mesaj/zaman/
   okunmamış sayısı gösteriyor (`ChatThreadSummary`, `GET /chat/threads`).
   `ChatApiTest` (5/5) iki gerçek hesap arasında çift yönlü teslim,
   okunmamış sayısı ve açınca okundu işaretlemeyi doğruluyor;
   `verify_rest_backend.dart`'a da gerçek ikinci bir Sanctum hesabıyla
   uçtan uca aynı senaryo eklendi.
2. **Gerçek bug: beğeniler hesap bazlı değildi** — `feed_posts.liked_by_me`
   tek bir paylaşılan boolean sütunuydu; bir hesabın beğenmesi *her*
   hesapta "beğenildi" gösteriyordu. Yeni `post_likes` tablosu (post_id +
   user_id, unique) ile beğeni artık gerçekten hesap bazlı — her görüntüleyen
   kendi beğeni durumunu görüyor. `FeedApiTest` (5/5): beğeni izolasyonu,
   kendi gönderini beğenince bildirim gitmemesi, unlike'ın sadece o hesabı
   etkilemesi doğrulanıyor.
3. **Eksik gerçek bildirimler eklendi** — beğeni, yorum, aktivite onayı,
   aktivite reddi, aktivite katılımı artık gerçek `Notification` satırları
   yazıyor (ilgili gerçek alıcıya — post'un `author_id`'si, aktivitenin
   `created_by_user_id`'si — kendine değil). Ayrıca gerçek bir bug
   düzeltildi: takip bildirimi *takip edeni* değil yanlışlıkla *takip
   edilen* rolündeki kendi hesabı bilgilendiriyordu (chat'teki isim
   çözümleme deseniyle aynı şekilde artık gerçekten takip edilen hesaba
   gidiyor). `NotificationsScreen` yeni `kind` değerleri için ikon
   eşlemesi aldı.
4. **Moderasyon akışı doğrulandı (§10)** — kod incelemesiyle onaylandı:
   kullanıcı raporluyor (`FeedController::report`) → admin
   `Admin\ReportController::index()`'te görüyor → `resolve()` ile işlem
   yapıyor → `AuditLogger` işlem geçmişini tutuyor. Yeni inşa
   gerekmedi, zaten gerçekti.
5. **Renk paleti — sosyal, Ask ARUCAD, aktivite kartları** — ikinci sıcak
   palet (`slateBlue/terracotta/sage/honey/dustyRose/mistLilac`,
   `categoryAccent()`) artık post avatarları (isme göre deterministik),
   hikaye halkası/renk seçici, kalp/kaydet ikonları, konum/ders etiketleri,
   Ask ARUCAD'ın eşleşen-yer/servis/kulüp/spor aksiyon butonları, quest
   kartları ve Campus Journey ilerleme çubuklarında kullanılıyor — hepsi
   tek bir tutarlı sistemden (mevcut `categoryAccent()`), rastgele yeni
   renk değil. Marka mavisi bilinçli olarak sadece gerçek marka/durum
   anlamı taşıyan yerlerde kaldı (RESMİ rozeti, okunmamış mesaj durumu,
   Skor kartı, seviye rengi rampası).
6. **Aktivite kartı sadeleştirildi** — `EventCard` (Home + her yerde
   paylaşılan) artık kategori rozeti ve tek satır açıklama içeriyor,
   tarih+saat birlikte gösteriliyor; geri kalan tüm alanlar (fakülte,
   amaç, gereksinimler, afiş, atanan personel...) zaten sadece
   `EventDetailScreen`'de.
   - Doğrulama: `php artisan test` 81/81, `dart run tool/verify_rest_backend.dart`
     53/53, `flutter test` 7/7, `flutter analyze` 12/12 (değişmedi).

### ✅ Bu turda tamamlanan (Prompt 5/5 — admin panel/dashboard/analytics/roller/site ayarları)

1. **Dashboard + İstatistik birleştirildi, gerçekten realtime** — tek ekran,
   üstte içerik/katalog özeti + hızlı aksiyonlar + son aktivite (önemli
   metrikler üstte, §14), altta tam analitik. 30 saniyede bir sessizce
   yeniden çekiliyor (`Timer.periodic`, önceki summarize'da "app genelinde
   ilk polling örneği" olarak işaretlenmişti) — manuel yenileme
   gerektirmiyor, ekran flash/spinner göstermeden yerinde güncelleniyor.
2. **Gerçek yeni analitikler** — `StatsController` önemli ölçüde
   genişletildi: check-in saatlik dağılım + en çok check-in yapan
   öğrenciler; aktivite fakülte/bölüm bazlı katılım, gerçek form
   açılma/gönderme/oranı (`Event.form_opened_at`, yeni), yoklama alan
   hocalar + yoklamaya katılan öğrenci sayısı, en aktif öğrenciler; sosyal
   en çok paylaşım/beğeni/yorum/takipçi/şikayet edilen içerik; **ARUCAD Sor
   analitiği sıfırdan inşa edildi** — önceden `AiController::query()`
   hiçbir soruyu loglamıyordu, yeni `ask_arucad_logs` tablosu + gerçek
   Türkçe anahtar-kelime kategorileyici (`AskArucadCategorizer`) ile artık
   her soru gerçekten kaydediliyor ve kategorize ediliyor; harita analitiği
   (en yoğun/sakin bölgeler, saatlik yoğunluk) `PlaceController::density()`
   ile aynı gerçek eşiklerden dashboard'a gömüldü.
3. **Gerçek bug: `totalXp` sadece ilk hesabı sayıyordu** — `User::first()?->xp`
   yerine `User::sum('xp')`; artık gerçekten tüm hesapların toplamı.
4. **Checkbox permission sistemi — Prompt 3/5'te bilinçli ertelenen parça,
   şimdi gerçekten inşa edildi.** `role_assignments.permissions` (json,
   additive-only override) + 13 gerçek checkbox anahtarı
   (`GranularPermissions`), her biri gerçek bir `EnsurePermission` bucket'ına
   eşleniyor — bir kişiye rol şablonunun ÜSTÜNE ek erişim verilebiliyor
   (asla mevcut erişimi düşürmüyor). Gerçek "kullanıcı oluştur" akışı
   (`Admin\UserController::store`) artık gerçek bir `users` satırı +
   rol/izin ataması yaratıyor — sadece e-posta→rol eşlemesi değil. Gerçek
   "pasif hale getir" (`deactivated_at`, `EnsureNotBanned` genişletildi,
   `ACCOUNT_DEACTIVATED` hatası, admin route'ları hariç). "Kullanıcılar ve
   Roller" ekranı artık gerçek hesap listesi + oluştur/düzenle/pasifleştir
   diyalogları + detaylı kullanıcı bilgisi (XP/check-in/etkinlik/ihlal).
   Uçtan uca doğrulandı: gerçek bir hesap, sadece verilen checkbox'a karşılık
   gelen route'a erişebiliyor, diğerlerine erişemiyor (`AdminUserApiTest`).
5. **Banlı hesaplar ayrı kategori oldu** — Moderasyon ekranına "Banlı
   Hesaplar" sekmesi eklendi (`GET /admin/users/banned`), raporlardan tamamen
   ayrı; gerçek "yasağı kaldır" (`unbanUser`) ihlal sayısını da sıfırlıyor
   (aksi halde bir sonraki tek ihlal anında yeniden banlardı).
6. **Site Ayarları genişletildi** — İzinli e-posta domainleri artık gerçekten
   backend'de uygulanıyor (`AuthController::session()`, önceden hardcoded
   `@arucad.edu.tr`); Entra Client Secret alanı eklendi ama **kasıtlı
   olarak write-only** (moderasyon anahtarıyla aynı desen — hiçbir zaman
   client'a geri gönderilmiyor, "hassas secret bilgilerini client'a
   gönderme" gereksinimini tam karşılıyor); check-in XP miktarı artık
   admin-ayarlanabilir (`xp.checkinAmount`, önceden hardcoded 10).
   **Not:** "Moderasyonu görsel gibi gereksiz dekoratif alanları kaldır"
   maddesi bilinçli olarak uygulanmadı — görsel moderasyon API anahtarı
   alanı incelendiğinde **gerçek, çalışan bir güvenlik altyapısı** olduğu
   görüldü (`ImageModerationService`, gerçek OpenAI Moderations API
   çağrısı, gerçek 3-strike sisteme bağlı) — dekoratif değil. Gerçek bir
   özelliği "dekoratif" zannedip silmek bu projenin tüm önceki fazlarındaki
   "asla gerçek bir şeyi bozma" ilkesine aykırı olurdu; kullanıcıya bu
   ayrım özellikle belirtiliyor.
7. **Menü sadeleştirildi** — admin kenar çubuğunda artık sadece istenen 14
   öğe var (Dashboard birleşik, Bina Dizini/Sayfalar/Akademik Yıllar menüden
   kaldırıldı). Alttaki controller'lar/tablolar ve bunları kullanan gerçek
   public ekranlar (bina dizini, sayfalar, `academic_year_id` damgalama)
   dokunulmadan duruyor — sadece admin'in bu üçünü düzenleme kısayolu
   menüden kalktı.
   - Doğrulama: `php artisan test` 92/92 (11 yeni test: `AdminUserApiTest`,
     `AdminSettingsAuthXpTest`, `AskArucadLogTest`),
     `dart run tool/verify_rest_backend.dart` 60/60 (7 yeni kontrol —
     gerçek izin override'ı, gerçek domain-genişletme+gerçek ikinci hesap
     girişi, gerçek write-only secret, gerçek XP miktarı değişikliğinin
     gerçek bir check-in'e yansıması), `flutter test` 7/7, `flutter analyze`
     12/12 (sabit baseline).

### ❌ Prompt 5/5'ten bilinçli olarak ertelenen/kısmi bırakılan parçalar

* **CRUD buton tutarlılığı (§8)** — Delete confirmation/Preview-Share gibi
  aksiyonların tüm admin editörlerinde (kulüp/spor/hizmet/yemek/anket/vb.)
  tek tip hale getirilmesi genel bir tarama gerektiriyor; bu turda
  yapılmadı.
* **Diğer admin liste ekranlarının sadeleştirilmesi (§14)** — Dashboard
  dışındaki (kulüpler/sporlar/hizmetler/vb.) liste ekranlarının kart
  yoğunluğu gözden geçirilmedi.
* **Aktivite katılımı için ayrı saat/gün dağılımı (§3)** — mevcut saatlik
  yoğunluk check-in verisinden geliyor (gerçek, ama etkinlik katılımına
  özel değil); `EventJoin.joined_at`'e dayalı ayrı bir saat/gün kırılımı
  eklenmedi.
* **Aktivite Günlüğü'nde varlık bazlı filtre/timeline (§11)** — mevcut ekran
  zaten gerçek (kim/ne zaman/ne), ama tek bir olayın (örn. "Etkinlik X")
  tüm geçmişini filtreleyen bir görünüm yok (`AdminAuditLog`'da
  `target_id` olmadığı için).

### ✅ Prompt 3/5'ten ertelenen parça — Prompt 5/5'te tamamlandı

* **Tam checkbox bazlı, kişi-bazlı granüler izin sistemi + "yeni kullanıcı
  oluştur" formu** (§9) — Prompt 3/5'te ertelenmişti, Prompt 5/5'te
  gerçekten inşa edildi: `GranularPermissions` (13 checkbox, additive-only
  role-şablonu-üstü override), `Admin\UserController::store()` (gerçek
  kullanıcı oluşturma), "Kullanıcılar ve Roller" ekranı artık checkbox
  listesi + oluştur/düzenle/pasifleştir. Detaylar yukarıda, Prompt 5/5
  bölümünde.

### ❌ Kod tarafında yapılması gerekenler

1. **Gerçek realtime / event-based mimari** — en büyük kalan parça. Şu an
   hiçbir WebSocket/broadcasting altyapısı yok (Laravel Reverb kurulu
   değil, Flutter tarafında socket client yok); tüm ekranlar polling
   temelli. `activity.approved`, `checkin.created`, `xp.updated` gibi ~18
   event tipini gerçek anlamda anlık yaymak — backend'e Reverb kurulumu +
   her ilgili controller'a `ShouldBroadcast` event'leri + Flutter'da gerçek
   bir WebSocket client + ilgili ekranların buna abone olması gerektiriyor.
   Bu, tek başına ayrı ve büyük bir iş — bilinçli olarak henüz
   başlanmadı, yarım/sahte bir realtime katmanı eklememek için.
2. **Gerçek harita routing (OSRM/Valhalla)** — navigasyon hâlâ kuş uçuşu
   mesafe tahmini + canlı konum takibi (bu gerçek ve çalışıyor), ama gerçek
   yol/sokak rotası değil. Self-hosted bir routing motoru (gerçek Kıbrıs
   OSM yol verisiyle) ya da harici bir API gerektiriyor — ikisi de ayrı,
   büyük bir iş; bu turda başlanmadı.
3. **Yemekhane Flutter → backend geçişi**
4. **Medya Kütüphanesi Flutter → gerçek URL/upload geçişi**
5. **Pagination / filtering / sorting**
6. **Offline cache + sync + conflict çözümü**
7. **Form export/import + versioning + etkinlik bağlantısı**
8. **Raporlanan kullanıcı için itiraz (appeal) sistemi** — rapor/inceleme/
   işlem/işlem-geçmişi zincirinin kendisi gerçek ve doğrulandı
   (`FeedController::report` → `Admin\ReportController::index/resolve` →
   `AuditLogger`, Prompt 4/5'te yeniden onaylandı); eksik olan tek şey
   işlem yapılan kullanıcının bu kararı itiraz edebileceği ayrı bir akış.
9. **AI endpoint özel rate limit**
10. **Sentry/Crashlytics + backend monitoring + uptime**
11. **GitHub Actions CI'nin gerçek runner'da doğrulanması**
12. **Tüm ekranlarda renk paleti için tam UI taraması** — Prompt 4/5'te
    sosyal (feed/post/hikaye/chat), Ask ARUCAD ve aktivite/quest kartları
    yapıldı; admin panel, profil, explore, services/food gibi henüz
    dokunulmamış ekranlar için hâlâ açık.
13. **Harita marker'larının zoom-seviyesine göre sadeleşmesi** — şu an
    yoğunluk bazlı bir filtre var (sadece busy/moderate yerler tam
    marker alıyor), ama gerçek zoom-dinlemeli bir sadeleştirme değil.
14. **Marker/GPS/heatmap'in gerçek cihazda görsel doğrulaması** — kod
    incelemesiyle doğru görünüyor ve backend tarafı gerçek testlerle
    doğrulandı, ama bu ortamda tıklayarak/görsel olarak deneyemiyorum.

### 🔴 Dış hesap / gerçek ortam bekleyenler

* **Entra** Tenant/Client/Secret
* **Firebase/FCM + APNs**
* **OpenAI API key** → görsel moderasyonun gerçek çalışması için
* **SMTP hesabı**
* **PostgreSQL sunucusu**
* **Production hosting + HTTPS**
* **Google Play / App Store hesapları**
* **Privacy Policy / Terms / KVKK-GDPR**
