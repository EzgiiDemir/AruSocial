# İşlev ve bağlantı denetimi — 10 Eylül 2026

Bu rapor öğrenci uygulaması, sosyal akış, Laravel API, kalıcı SQL verisi,
gerçek zamanlı kanal ve cihaz simülatörünün kod/test denetimidir. “Kod var” ile
“çalışması dış servise bağlı” durumları özellikle ayrılmıştır.

## Bu turda tamamlananlar

- Ana sayfadaki **Kampüste Şimdi** satırları artık ayrı kartlardır; birleşik
  buton görünümü yoktur.
- **Senin İçin Önerilenler** her zaman üç eşit satır gösterir: bugünkü
  etkinlik, en popüler yer ve henüz gidilmeyen yer. İkonlar yuvarlak zeminli,
  metrikler ortak ve her satır sağ okla biter.
- **En Popüler Yerler** üstte ortalanmış arama, kategori filtresi ve gerçek
  numaralı pagination kullanır. Sıralama ARUCAD kırmızı/mavi/sarı/yeşil
  döngüsünü kullanır.
- Sosyal filtredeki **Tümü** kontrolünün hover/splash zemini kapalıdır.
- Gönderi detay başlığındaki içerik türü emojisi kaldırılmıştır.
- Aktivite geçmişi, Keşfet Etkinlikler ve Keşfet Takvim sonundaki
  “Tüm … listelendi >” yazıları kaldırılmıştır.
- OpenFreeMap sembolleri açıkça `Noto Sans Regular` font yığınına sabitlidir;
  404 veren MapLibre varsayılan `Open Sans Regular,Arial Unicode MS Regular`
  yığını kullanılmaz.
- Demo/test içerikleri gerçek kampüs kataloğundan ayrı, kararlı `demo-*`
  kimliklerle varsayılan local/testing seed zincirine bağlanmıştır.
- ARUCAD 360 API senkronu gerçek ortamda çalıştırıldı: 3 kampüs, 15 bina,
  131 oda ve 122 oda turu okundu. Yerel sonuç: 24/24 yer koordinatlı,
  24/24 yer tur bağlantılı, 141 dizin kaydı ve 131 dizin tur bağlantısı.
- Simülatör cihaz görünümü yanında API/konsol hatasını sebep ve kullanıcı
  etkisiyle gösterir; çevrimdışı API modu ve görünür DOM için responsive
  taşma denetimi vardır.

## Sosyal alan bağlantı denetimi

| Alan | Frontend | API/SQL | Gerçek zaman | Sonuç |
|---|---|---|---|---|
| Home / akış | `SocialScreen` | feed CRUD, like, comment, report, save | kampüs değişim olayıyla yenileme | Bağlı |
| Mesajlar | `ChatThreadsScreen` / `ChatScreen` | konuşma ve mesaj tabloları | Reverb private channel + reconnect sonrası REST uzlaştırma | Bağlı; Reverb süreci gerekli |
| Arama | `PeopleScreen` | sosyal kullanıcı/follow/block uçları | yazma sonrası API yenileme | Bağlı |
| Profil | `SocialProfileScreen` | `/me`, profil, gönderiler, medya | API kaynaklı | Bağlı |
| Hikâyeler | feed/story ekranları | oluştur, görüntüle, izleyici, sil | API kaynaklı | Bağlı |
| Bildirimler | `NotificationsScreen` | listele, oku, tümünü oku | FCM + kalıcı inbox | Bağlı; cihaz teslimi Firebase ister |

Backend doğrulaması doğrudan PHPUnit ile **714 test / 713 başarılı / 1
atlandı, 3421 assertion** sonucunu verdi. Atlanan test dış servis koşuluna
bağlıdır.

## Açık kalan veya dış sisteme bağlı maddeler

1. **360 koordinat sözleşmesi:** 360 API bu çalıştırmada `coordinates` /
   `location` alanlarında sıfır koordinat döndürdü. Bu nedenle API tur ve
   bina/oda hiyerarşisinin kaynağı; harita koordinatlarının kaynağı ise
   doğrulanmış 24 kayıtlık kalıcı kampüs kataloğudur. API koordinat vermeye
   başlarsa senkron servisi mevcut koduyla bunları öncelikli uygular.
2. **Gerçek zamanlı mesaj teslimi:** REST ve SQL tamdır; anlık başka-cihaz
   teslimi için üretimde Reverb sürecinin ve `REVERB_*` ayarlarının açık
   olması gerekir. Reverb yoksa ekran geçmişi/yenileme ile çalışır ama push
   kadar anlık değildir.
3. **Push bildirimleri:** inbox SQL kaydı çalışır; fiziksel cihaz bildirimi
   Firebase projesi, FCM anahtarı ve imzalı mobil build gerektirir.
4. **Navigasyon:** yer koordinatları ve hedef açılımları bağlıdır. Gerçek
   dönüş-dönüş rota için OSRM/Valhalla sunucusu gerekir; yoksa uygulama
   açıkça düz çizgi/mesafe fallback'i kullanır.
5. **Simülatör sınırı:** web aracı viewport, orientation, ağ kesintisi,
   runtime/API ve responsive taşmayı test eder. GPS radyo davranışı, düşük
   bellek sonlandırması, kamera, termal kısma ve gerçek iOS/Android WebView
   yalnızca Android Emulator/iOS Simulator veya fiziksel cihazla birebir
   doğrulanabilir.
6. **Dış hesaplar:** Entra SSO, gerçek SMTP, Firebase/APNs, production
   PostgreSQL/HTTPS ve mağaza imzaları ilgili ARUCAD hesapları olmadan
   production seviyesinde doğrulanamaz.
7. **Admin dashboard özet kartları:** Check-in, bildirim, gönderi, yorum,
   beğeni ve değerlendirme kartları sayaçtır; `onTap: null` olduğu için
   drill-down sayfası açmaz. Bunlar öğrenci uygulamasındaki çalışmayan
   butonlar değildir ama admin analitiği için ileride filtreli detay ekranı
   bağlanabilir.
8. **Test komutu:** bu çalışma alanında `php artisan test` isteğe bağlı
   Multiplex katmanıyla yerel SQLite dosyasına çakışmalı paralel süreçler
   açabildi. Güvenilir izole doğrulama `php vendor/bin/phpunit` ile yapılır;
   CI komutu da bu yolu kullanmalıdır.
9. **Mock giriş modu:** `USE_REST_API=false` yalnız yerel tasarım demosudur
   ve bilinçli olarak gevşek test hesabı kabul eder. Güvenlik veya gerçek
   kullanıcı testi değildir; gerçek doğrulama varsayılan REST/Sanctum
   akışında yapılmalıdır.

## Veri ayrımı

- `CampusCatalogSeeder`: doğrulanmış/hesaplanmış gerçek ARUCAD yerleri ve
  koordinatları; `demo-*` değildir.
- `CampusDirectory360Sync`: ARUCAD tarafından işletilen canlı 360 API’den
  bina, oda ve tur hedeflerini kalıcı SQL’e upsert eder; saatlik schedule
  vardır.
- `DemoCampusLifeSeeder`: yalnız local/testing için sosyal gönderi, etkinlik,
  yorum, beğeni ve check-in üretir; gerçek katalog veya 360 kayıtlarını
  silmez/değiştirmez.
