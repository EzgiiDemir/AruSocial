<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\EventParticipationType;
use App\Models\FeedPost;
use App\Models\FoodDailyMenu;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\Quest;
use App\Models\RoleAssignment;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Models\Story;
use App\Models\Survey;
use App\Models\SurveyOption;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $me = User::create([
            'name' => 'Ege Aydın',
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => bcrypt('demo-password'),
            'role' => 'student',
            'level' => 4,
            'xp' => 1280,
            'places' => 6,
            'events' => 3,
            'memories' => 9,
            'interests' => ['Tasarım', 'Fotoğrafçılık', 'Basketbol'],
        ]);

        // Real route-level RBAC (docs/EKSIKLER.md "RBAC permission
        // enforcement") means someone has to already be superAdmin before
        // anyone can grant roles at all via the API — a real deployment
        // bootstraps its first admin the same way, outside the API
        // (a seeder/artisan command), not by having the API grant itself
        // permission.
        RoleAssignment::create([
            'email' => $me->email, 'role' => 'superAdmin',
            'assigned_by' => 'seeder', 'assigned_at' => now(),
        ]);

        Quest::create([
            'id' => 'quest-'.Str::uuid(),
            'user_id' => $me->id,
            'title' => 'Kampüs Kâşifi',
            'subtitle' => '5 farklı mekânda check-in yap',
            'progress' => 3,
            'target' => 5,
            'reward' => 150,
        ]);

        $places = [
            ['place-atelier', 'Atelier', 'Studio', 35.3396, 33.3155, 'Ana atölye binası — tasarım ve sanat stüdyoları.', '120m', 'moderate', 'Namık Kemal Cd.', true, 34, 4.6],
            ['place-library', 'Kütüphane', 'Study', 35.3401, 33.3149, 'Sessiz çalışma alanları ve baskı merkezi.', '260m', 'quiet', 'Karaca Sk.', true, 12, 4.8],
            ['place-cafeteria', 'Kafeterya', 'Food', 35.3390, 33.3161, 'Ana kampüs yemekhanesi.', '80m', 'busy', 'Cemal Gürsel Cd.', true, 51, 4.1],
            ['place-garden', 'Garden', 'Outdoor', 35.3405, 33.3168, 'Açık hava etkinlik alanı.', '300m', 'quiet', 'Şehit Neriman Sk.', false, 22, 4.4],
        ];
        foreach ($places as [$id, $name, $category, $lat, $lng, $desc, $distance, $density, $street, $accessible, $photos, $rating]) {
            Place::create([
                'id' => $id, 'name' => $name, 'category' => $category, 'lat' => $lat, 'lng' => $lng,
                'description' => $desc, 'distance' => $distance, 'density' => $density, 'street' => $street,
                'accessible' => $accessible, 'photos' => $photos, 'rating' => $rating,
            ]);
        }

        // Real ARUCAD POI set (docs/EKSIKLER.md harita/POI) — the Rodin
        // campus's actual named buildings/spaces, not demo placeholders.
        // `confidence` is honest about provenance: most of these are
        // precise 6-decimal coordinates, but Arkin Rodin Collection
        // Gallery (real address vs. Google Places mismatch) and the Age of
        // Bronze / Art Rooms / Iris workshop-building cluster (identical
        // shared coordinates — a single site, not independently surveyed
        // per building) genuinely need on-site verification.
        $poi = [
            ['poi-rodin', 'Rodin', 'Yönetim', 35.337305, 33.321303, 'verified',
                'ARUCAD adını taşıyan Fransız heykeltıraş Auguste Rodin\'e ithaf edilmiş bina. Rektörlük ve Sanat/Tasarım fakülte dekanlıkları burada.'],
            ['poi-falling-man', 'Falling Man', 'Akademik', 35.337305, 33.321027, 'verified',
                'Görsel İletişim, Endüstriyel Tasarım, Seramik ve Yeni Medya bölüm başkanlıkları burada.'],
            ['poi-titan', 'Titan', 'İdari + Akademik', 35.337170, 33.321633, 'verified',
                'Öğrenci İşleri, Bilgi İşlem, Mimarlık, Dijital Oyun Tasarımı ve Arkeoloji bölüm başkanlıkları — kampüsün en yoğun idari binası.'],
            ['poi-eve', 'Eve', 'Eğitim', 35.337529, 33.321303, 'verified',
                '"Eve", Rodin\'in en tanınan kadın nü heykellerinden biri. İngilizce Hazırlık Okulu burada.'],
            ['poi-daniele', 'Daniele', 'Stüdyo', 35.337772, 33.321688, 'verified',
                'İsim Rodin\'in "Danaïde" figürüne bir gönderme. Film, fotoğraf, iç mimarlık stüdyoları ve MAC Lab burada.'],
            ['poi-eternal-spring', 'Eternal Spring', 'Stüdyo', 35.337844, 33.321468, 'verified',
                'Rodin\'in "Ebedi Bahar" heykelinden adını alır. Dijital baskı atölyesi burada.'],
            ['poi-meditation', 'Meditation', 'Kütüphane', 35.337754, 33.321358, 'verified',
                'Rodin\'in içe dönük "İç Ses" figürüne selam veren isim — kütüphane, dijital kütüphane ve konferans salonu burada.'],
            ['poi-minotaur', 'Minotaur', 'Destek', 35.337844, 33.321270, 'verified',
                'Güvenlik, Psikolojik Danışmanlık ve Rehberlik Merkezi, Kampüs Koordinatörlüğü burada.'],
            ['poi-eternal-idol', 'Eternal Idol', 'Pazarlama', 35.337889, 33.321193, 'verified',
                '"Ebedi Put" — Rodin\'in en duygusal eserlerinden biri. Uluslararası pazarlama ve kurumsal iletişim ofisleri burada.'],
            ['poi-the-kiss', 'The Kiss', 'Sanat', 35.337799, 33.321082, 'verified',
                'Rodin\'in dünyaca en ünlü heykeli "Öpücük". Performans stüdyosu, ARUCAD Galerisi ve Sağlık Merkezi burada.'],
            ['poi-the-garden', 'The Garden', 'Sosyal Alan', 35.337125, 33.320972, 'verified',
                'Kampüsün açık sosyal alanı.'],
            ['poi-carpentry-studio', 'Carpentry Studio', 'Atölye', 35.337502, 33.321226, 'verified',
                'Marangozluk atölyesi.'],
            ['poi-arkin-rodin-gallery', 'Arkin Rodin Collection Gallery', 'Galeri', 35.337925, 33.320036, 'needs_verification',
                'ARUCAD\'ın kendi Rodin heykel koleksiyonunun sergilendiği, halka açık galeri. Resmi haritadaki adres ile Google Places kaydı arasında konum farklılığı olduğundan koordinat saha doğrulamasına açık.'],
            ['poi-dormitory', 'ARUCAD Dormitory', 'Konaklama', 35.331328, 33.318916, 'verified',
                'Öğrenci yurdu.'],
            ['poi-bandabuliya-campus', 'Nicosia Bandabuliya Campus', 'Kampüs', 35.175513, 33.365029, 'verified',
                'Lefkoşa\'daki ikinci kampüs — Müzik ve Sahne Sanatları Fakültesi, Blackbox Sahne, Bandabuliya Cafe.'],
            ['poi-art-space', 'ARUCAD Art Space', 'Galeri', 35.177726, 33.360248, 'verified',
                'Lefkoşa\'da sanat galerisi mekânı.'],
            ['poi-age-of-bronze', 'Age of Bronze', 'Atölye', 35.333593, 33.330680, 'needs_verification',
                'Heykel stüdyosu ve disiplinlerarası atölyeler. Koordinat ARUCAD WORKSHOPS kaydından türetilmiş, saha doğrulamasına açık.'],
            ['poi-art-rooms', 'Art Rooms', 'Atölye/Galeri', 35.333593, 33.330680, 'needs_verification',
                'Age of Bronze ile aynı adres bilgisine sahip — ayrı konumu ayrıca doğrulanmalı.'],
            ['poi-iris', 'Iris (Atelier Building)', 'Atölye', 35.333593, 33.330680, 'needs_verification',
                'Seramik, cam üfleme ve döküm atölyeleri. Workshop Buildings grubunda yer aldığı için mevcut veri aynı koordinatı kullanıyor; saha doğrulaması gerekli.'],
        ];
        foreach ($poi as [$id, $name, $category, $lat, $lng, $confidence, $desc]) {
            Place::create([
                'id' => $id, 'name' => $name, 'category' => $category, 'lat' => $lat, 'lng' => $lng,
                'coordinate_confidence' => $confidence, 'description' => $desc, 'distance' => '',
                'density' => 'quiet', 'street' => '', 'accessible' => true, 'photos' => 0, 'rating' => 0,
            ]);
        }

        $this->call(AcademicStaffSeeder::class);

        AcademicYear::create([
            'id' => '2025-2026', 'label' => '2025-2026', 'starts_on' => '2025-09-01',
            'ends_on' => '2026-08-31', 'is_active' => true,
        ]);

        $event1 = Event::create([
            'id' => 'event-bahar-senligi', 'title' => 'Bahar Şenliği', 'time' => '14:00',
            'place_name' => 'Garden', 'place_id' => 'place-garden', 'category' => 'Etkinlik',
            'attendees' => 128, 'xp' => 50, 'audience' => 'Tümü', 'organizer' => 'Öğrenci Konseyi',
            'organizer_email' => 'ogrenci.konseyi@arucad.edu.tr', 'academic_year_id' => '2025-2026',
            'description' => 'Yıllık bahar şenliği — canlı müzik, stantlar ve yarışmalar.',
        ]);
        Event::create([
            'id' => 'event-atelier-acik-kapi', 'title' => 'Atelier Açık Kapı Günü', 'time' => '11:00',
            'place_name' => 'Atelier', 'place_id' => 'place-atelier', 'category' => 'Akademik',
            'attendees' => 41, 'xp' => 30, 'audience' => 'Tümü', 'organizer' => 'Tasarım Fakültesi',
            'organizer_email' => 'tasarim.fakultesi@arucad.edu.tr', 'academic_year_id' => '2025-2026',
            'description' => 'Atölyeler ziyarete açık, öğrenci projeleri sergileniyor.',
        ]);
        foreach (['Katılımcı', 'Gönüllü', 'Organizasyon'] as $i => $label) {
            EventParticipationType::create([
                'id' => 'ptype-'.Str::uuid(), 'event_id' => $event1->id, 'label' => $label, 'sort_order' => $i,
            ]);
        }

        FeedPost::create([
            'id' => 'post-'.Str::uuid(), 'author_id' => (string) $me->id, 'name' => 'ARUCAD',
            'text' => 'Bahar Şenliği başvuruları açıldı! 🎉', 'meta' => '2 saat önce', 'likes' => 14,
            'post_type' => 'etkinlik', 'official' => true, 'created_at' => now(),
        ]);
        FeedPost::create([
            'id' => 'post-'.Str::uuid(), 'author_id' => (string) $me->id, 'name' => 'Ege Aydın',
            'text' => "Atelier'de yeni sömestr projelerine başladık.", 'meta' => '5 saat önce', 'likes' => 6,
            'created_at' => now(),
        ]);

        Story::create([
            'id' => 'story-'.Str::uuid(), 'author_id' => (string) $me->id, 'author_name' => 'Ege Aydın',
            'text' => 'Bugün stüdyoda!', 'background_color_value' => 0xFF5B4DFF, 'created_at' => now(),
        ]);

        Club::create([
            'id' => 'club-photography', 'name' => 'Fotoğrafçılık Kulübü', 'category' => 'Sanat',
            'description' => 'Kampüste ve şehirde birlikte fotoğraf çekimleri düzenleyen öğrenci kulübü.',
        ]);
        Sport::create([
            'id' => 'sport-basketball', 'name' => 'Basketbol', 'facility' => 'Kapalı Spor Salonu',
            'contact' => 'spor@arucad.edu.tr',
        ]);
        ServiceItem::create([
            'id' => 'service-student-affairs', 'title' => 'Öğrenci İşleri', 'category' => 'İdari',
            'description' => 'Kayıt, transkript ve genel öğrenci işlemleri.',
            'contact' => 'ogrenciisleri@arucad.edu.tr', 'building' => 'A Blok', 'floor' => '1',
            'topics' => ['Ders Kaydı', 'Transkript', 'Öğrenci Belgesi'],
            'hours' => 'Hafta içi 09:00–17:00',
        ]);
        DirectoryEntry::create([
            'id' => 'dir-1', 'building' => 'A Blok', 'floor' => '1', 'room' => '104',
            'occupant_name' => 'Öğrenci İşleri Ofisi', 'occupant_role' => 'İdari Birim',
            'related_service_id' => 'service-student-affairs',
        ]);
        $venue = FoodVenue::create([
            'id' => 'food-the-garden', 'name' => 'The Garden', 'hours' => '08:00–20:00',
        ]);
        FoodDailyMenu::create([
            'id' => 'menu-'.Str::uuid(), 'food_venue_id' => $venue->id, 'menu_date' => now()->toDateString(),
            'items' => ['Mercimek Çorbası', 'Izgara Tavuk', 'Pilav'], 'price' => '85₺',
        ]);

        $survey = Survey::create([
            'id' => 'survey-bahar-tarihi', 'question' => 'Bahar Şenliği hangi tarihte yapılsın?',
            'description' => 'Öğrenci Konseyi kararınızı bekliyor.',
            'target_audience' => 'Tümü', 'multiple_choice' => false, 'anonymous' => true,
            'show_results' => true, 'active' => true, 'created_by' => 'Öğrenci Konseyi', 'created_at' => now(),
        ]);
        foreach (['15 Mayıs', '22 Mayıs', '29 Mayıs'] as $i => $label) {
            SurveyOption::create(['id' => 'opt-'.Str::uuid(), 'survey_id' => $survey->id, 'label' => $label, 'sort_order' => $i]);
        }
    }
}
