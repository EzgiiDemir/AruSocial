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
