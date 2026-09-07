-- ============================================================
-- 0002_real_campus_data.sql
-- 19 gerçek ARUCAD noktası — koordinatların kaynağı:
--   [DOĞRULANMIŞ] = Google Places API, doğrudan sorgulanıp teyit edildi
--   [HESAPLANMIŞ]  = resmi kampüs haritasındaki göreli yerleşimden,
--                     doğrulanmış merkez koordinata bağlanarak türetildi
--                     (±15-20m sapma olabilir, saha ölçümüyle düzeltilmeli)
--
-- Canonical runtime seed:
--   php artisan db:seed --class=Database\\Seeders\\CampusCatalogSeeder
-- Frontend mirror: frontend/lib/core/config/poi_config.dart
-- ============================================================

insert into poi (name, category, lat, lng, art_fact, is_active) values

-- Ana kampüs — Şair Nedim Sokak No:11, Girne [HESAPLANMIŞ, merkez DOĞRULANMIŞ]
('Rodin', 'Yönetim', 35.337305, 33.321303,
 'Bina, ARUCAD adını taşıyan Fransız heykeltıraş Auguste Rodin''e ithaf edilmiş. Rektörlük ve Sanat/Tasarım fakülte dekanlıkları burada.', true),

('Falling Man', 'Akademik', 35.337305, 33.321027,
 'Görsel İletişim, Endüstriyel Tasarım, Seramik ve Yeni Medya bölüm başkanlıkları burada.', true),

('Titan', 'İdari + Akademik', 35.337170, 33.321633,
 'Öğrenci İşleri, Bilgi İşlem, Mimarlık, Dijital Oyun Tasarımı ve Arkeoloji bölüm başkanlıkları — kampüsün en yoğun idari binası.', true),

('Eve', 'Eğitim', 35.337529, 33.321303,
 '"Eve", Rodin''in en tanınan kadın nü heykellerinden biri. İngilizce Hazırlık Okulu burada.', true),

('Daniele', 'Stüdyo', 35.337772, 33.321688,
 'İsim Rodin''in "Danaïde" figürüne bir gönderme. Film, fotoğraf, iç mimarlık stüdyoları ve MAC Lab burada.', true),

('Eternal Spring', 'Stüdyo', 35.337844, 33.321468,
 'Rodin''in "Ebedi Bahar" heykelinden adını alır. Dijital baskı atölyesi burada.', true),

('Meditation', 'Kütüphane', 35.337754, 33.321358,
 'Rodin''in içe dönük "İç Ses" figürüne selam veren isim — kütüphane, dijital kütüphane ve konferans salonu burada.', true),

('Minotaur', 'Destek', 35.337844, 33.321270,
 'Güvenlik, Psikolojik Danışmanlık ve Rehberlik Merkezi, Kampüs Koordinatörlüğü burada.', true),

('Eternal Idol', 'Pazarlama', 35.337889, 33.321193,
 '"Ebedi Put" — Rodin''in en duygusal eserlerinden biri. Uluslararası pazarlama ve kurumsal iletişim ofisleri burada.', true),

('The Kiss', 'Sanat', 35.337799, 33.321082,
 'Rodin''in dünyaca en ünlü heykeli "Öpücük". Performans stüdyosu, ARUCAD Galerisi ve Sağlık Merkezi burada.', true),

('The Garden', 'Sosyal Alan', 35.337125, 33.320972,
 'Kampüsün açık sosyal alanı.', true),

('Carpentry Studio', 'Atölye', 35.337502, 33.321226,
 'Marangozluk atölyesi.', true),

-- Ayrı adresler [DOĞRULANMIŞ — Google Places]
('Arkin Rodin Collection Gallery', 'Galeri', 35.337925, 33.320036,
 'ARUCAD''ın kendi Rodin heykel koleksiyonunun sergilendiği, halka açık galeri.', true),

('ARUCAD Dormitory', 'Konaklama', 35.331328, 33.318916,
 'Öğrenci yurdu.', true),

('Nicosia Bandabuliya Campus', 'Kampüs', 35.175513, 33.365029,
 'Lefkoşa''daki ikinci kampüs — Müzik ve Sahne Sanatları Fakültesi, Blackbox Sahne, Bandabuliya Cafe.', true),

('ARUCAD Art Space', 'Galeri', 35.177726, 33.360248,
 'Lefkoşa''da sanat galerisi mekânı.', true),

-- Workshop binaları kümesi — orta güvenli
('Age of Bronze', 'Atölye', 35.333593, 33.330680,
 'Heykel stüdyosu ve disiplinlerarası atölyeler. Koordinat "ARUCAD WORKSHOPS" (Google Places).', true),

('Art Rooms', 'Atölye/Galeri', 35.333593, 33.330680,
 'Haritada Age of Bronze ile aynı adres — aynı koordinat kullanıldı.', true),

('Iris (Atelier Building)', 'Atölye', 35.333593, 33.330680,
 'Seramik, cam üfleme, döküm atölyeleri. Workshop cluster ile aynı koordinat — sahada teyit şart.', true);
