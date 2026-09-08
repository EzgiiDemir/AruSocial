<?php

namespace Database\Seeders;

use App\Models\Club;
use App\Models\Event;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\ShuttleRoute;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Support\CampusTaxonomy;
use Illuminate\Database\Seeder;

/**
 * Full campus POI set — coordinates from docs/sql 0002_real_campus_data
 * (Google Places verified + campus-map calculated ±15–20 m).
 * Legacy place-* ids kept for events/check-in FKs but remapped to real lat/lng.
 * Emails stay null unless known — never invented.
 */
class CampusCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // [id, name, category, lat, lng, description]
        $pois = [
            // Extra verified entrance (not in 0002 list; used as nav origin)
            ['ana-kampus-girisi', 'Ana kampüs girişi', 'Entrance', 35.337395, 33.321358,
                'Şair Nedim Sokak No:11, Girne — ana kampüs girişi.'],

            // 0002_real_campus_data — main campus [HESAPLANMIŞ, merkez DOĞRULANMIŞ]
            ['rodin', 'Rodin', 'Administration', 35.337305, 33.321303,
                'Bina, ARUCAD adını taşıyan Fransız heykeltıraş Auguste Rodin\'e ithaf edilmiş. Rektörlük ve Sanat/Tasarım fakülte dekanlıkları burada.'],
            ['falling-man', 'Falling Man', 'Academic', 35.337305, 33.321027,
                'Görsel İletişim, Endüstriyel Tasarım, Seramik ve Yeni Medya bölüm başkanlıkları burada.'],
            ['titan', 'Titan', 'Admin+Academic', 35.337170, 33.321633,
                'Öğrenci İşleri, Bilgi İşlem, Mimarlık, Dijital Oyun Tasarımı ve Arkeoloji bölüm başkanlıkları — kampüsün en yoğun idari binası.'],
            ['eve', 'Eve', 'Education', 35.337529, 33.321303,
                '"Eve", Rodin\'in en tanınan kadın nü heykellerinden biri. İngilizce Hazırlık Okulu burada.'],
            ['daniele', 'Daniele', 'Studio', 35.337772, 33.321688,
                'İsim Rodin\'in "Danaïde" figürüne bir gönderme. Film, fotoğraf, iç mimarlık stüdyoları ve MAC Lab burada.'],
            ['eternal-spring', 'Eternal Spring', 'Studio', 35.337844, 33.321468,
                'Rodin\'in "Ebedi Bahar" heykelinden adını alır. Dijital baskı atölyesi burada.'],
            ['meditation', 'Meditation', 'Library', 35.337754, 33.321358,
                'Rodin\'in içe dönük "İç Ses" figürüne selam veren isim — kütüphane, dijital kütüphane ve konferans salonu burada.'],
            ['minotaur', 'Minotaur', 'Support', 35.337844, 33.321270,
                'Güvenlik, Psikolojik Danışmanlık ve Rehberlik Merkezi, Kampüs Koordinatörlüğü burada.'],
            ['eternal-idol', 'Eternal Idol', 'Marketing', 35.337889, 33.321193,
                '"Ebedi Put" — Rodin\'in en duygusal eserlerinden biri. Uluslararası pazarlama ve kurumsal iletişim ofisleri burada.'],
            ['the-kiss', 'The Kiss', 'Art', 35.337799, 33.321082,
                'Rodin\'in dünyaca en ünlü heykeli "Öpücük". Performans stüdyosu, ARUCAD Galerisi ve Sağlık Merkezi burada.'],
            ['the-garden', 'The Garden', 'Social', 35.337125, 33.320972,
                'Kampüsün açık sosyal alanı.'],
            ['carpentry-studio', 'Carpentry Studio', 'Workshop', 35.337502, 33.321226,
                'Marangozluk atölyesi.'],

            // Ayrı adresler [DOĞRULANMIŞ]
            ['arkin-rodin-gallery', 'Arkin Rodin Collection Gallery', 'Gallery', 35.337925, 33.320036,
                'ARUCAD\'ın kendi Rodin heykel koleksiyonunun sergilendiği, halka açık galeri.'],
            ['arucad-dormitory', 'ARUCAD Dormitory', 'Accommodation', 35.331328, 33.318916,
                'Öğrenci yurdu.'],
            ['nicosia-bandabuliya', 'Nicosia Bandabuliya Campus', 'Campus', 35.175513, 33.365029,
                'Lefkoşa\'daki ikinci kampüs — Müzik ve Sahne Sanatları Fakültesi, Blackbox Sahne, Bandabuliya Cafe.'],
            ['arucad-art-space', 'ARUCAD Art Space', 'Gallery', 35.177726, 33.360248,
                'Lefkoşa\'da sanat galerisi mekânı.'],

            // Workshop cluster [orta güven]
            ['age-of-bronze', 'Age of Bronze', 'Workshop', 35.333593, 33.330680,
                'Heykel stüdyosu ve disiplinlerarası atölyeler. Koordinat "ARUCAD WORKSHOPS" (Google Places).'],
            ['art-rooms', 'Art Rooms', 'Workshop/Gallery', 35.333593, 33.330680,
                'Haritada Age of Bronze ile aynı adres — aynı koordinat kullanıldı.'],
            ['iris-atelier', 'Iris (Atelier Building)', 'Workshop', 35.333593, 33.330680,
                'Seramik, cam üfleme, döküm atölyeleri. Workshop cluster ile aynı koordinat — sahada teyit şart.'],
            ['arucad-workshops', 'ARUCAD Workshops', 'Workshops', 35.333593, 33.330680,
                'İskenderun Caddesi workshop kümesi (Age of Bronze / Art Rooms / Iris).'],

            // Legacy ids kept for old event/check-in FKs — names match
            // canonical rows above; student lists hide `place-*` aliases.
            ['place-atelier', 'Carpentry Studio', 'Workshop', 35.337502, 33.321226,
                'Marangozluk atölyesi.'],
            ['place-library', 'Meditation', 'Library', 35.337754, 33.321358,
                'Kütüphane, dijital kütüphane ve konferans salonu.'],
            ['place-bandabuliya', 'Nicosia Bandabuliya Campus', 'Campus', 35.175513, 33.365029,
                'Lefkoşa Bandabuliya kampüsü.'],
        ];

        foreach ($pois as [$id, $name, $category, $lat, $lng, $description]) {
            // Demo pulse: Art Rooms=sakin, The Kiss=orta, The Garden=yoğun.
            $density = match ($id) {
                'art-rooms' => 'quiet',
                'the-kiss' => 'moderate',
                'the-garden' => 'busy',
                default => 'quiet',
            };
            Place::updateOrCreate(['id' => $id], [
                'name' => $name,
                'category' => $category,
                'lat' => $lat,
                'lng' => $lng,
                'description' => $description,
                'distance' => '',
                'density' => $density,
                'street' => '',
                'accessible' => true,
                'photos' => 0,
                'rating' => 0,
                'tour_url' => self::tourUrlFor((float) $lat, (float) $lng),
            ]);
        }

        foreach (Place::all() as $place) {
            $place->update(['tour_url' => self::tourUrlFor((float) $place->lat, (float) $place->lng)]);
        }

        // Drop obsolete seed pin that was not in 0002_real_campus_data
        Place::where('id', 'atelier-arkin')->delete();

        // Collapse duplicate The Garden / alias rows onto the canonical id.
        Event::whereIn('place_id', ['place-garden', 'place-cafeteria'])->update([
            'place_id' => 'the-garden',
            'place_name' => 'The Garden',
        ]);
        Place::whereIn('id', ['place-garden', 'place-cafeteria'])->delete();

        // Faculty/department values below are ARUCAD's real structure (see
        // App\Support\CampusTaxonomy — sourced from arucad.edu.tr, not
        // invented). A handful of ids that predate this correction had no
        // real department equivalent (Public Relations/Journalism/Radio-TV
        // aren't real Faculty of Communication departments; "Music
        // Performance"/"Composition" aren't real Faculty of Music and
        // Performing Arts departments) — those are relabeled to their
        // nearest real department and marked `active=false` rather than
        // deleted, since a real submitted event's `responsible_staff_id`
        // may still point at one. `staff-sculpture-head` is the one
        // exception kept `active=true` despite having no standalone real
        // "Sculpture" department, because a real event already references
        // it — it's relabeled to Fine Arts (the department that actually
        // covers sculpture practice) rather than deactivated.
        // [id, name, faculty, department, title, isDepartmentHead, active]
        $staff = [
            ['staff-rector', 'Rektörlük Ofisi', null, 'Rectorate', 'Rector Office', false, true],
            ['staff-vrector', 'Rektör Yardımcılığı', null, 'Rectorate', 'Vice Rector', false, true],
            ['staff-registrar', 'Öğrenci İşleri', null, 'Student Affairs', 'Registrar', false, true],
            ['staff-intl', 'Uluslararası Ofis', null, 'International', 'International Office', false, true],
            ['staff-career', 'Kariyer Danışmanı', null, 'Career', 'Career Staff', false, true],
            ['staff-clubs', 'Kulüp Koordinatörü', null, 'Student Affairs', 'Club Responsible', false, true],
            ['staff-sports', 'Spor Koordinatörü', null, 'Sports', 'Sport Responsible', false, true],
            ['staff-pdr', 'PDR Birimi', null, 'Counseling', 'Counselor', false, true],
            ['staff-library', 'Kütüphane Müdürlüğü', null, 'Library', 'Library Head', false, true],
            ['staff-it', 'BT Destek', null, 'IT', 'IT Support', false, true],
            // Faculty of Arts — department heads
            ['staff-plastic-head', 'Plastik Sanatlar Bölüm Başkanlığı', 'Faculty of Arts', 'Fine Arts', 'Department Head', true, true],
            ['staff-fashion-head', 'Moda Tasarımı Bölüm Başkanlığı', 'Faculty of Arts', 'Textile and Fashion Design', 'Department Head', true, true],
            ['staff-cinema-head', 'Sinema Bölüm Başkanlığı', 'Faculty of Arts', 'Film Design and Management', 'Department Head', true, true],
            ['staff-photo-head', 'Fotoğraf Bölüm Başkanlığı', 'Faculty of Arts', 'Photography', 'Department Head', true, true],
            ['staff-ceramics-head', 'Seramik Bölüm Başkanlığı', 'Faculty of Arts', 'Ceramics', 'Department Head', true, true],
            ['staff-sculpture-head', 'Heykel Bölüm Başkanlığı', 'Faculty of Arts', 'Fine Arts', 'Department Head', true, true],
            ['staff-archaeology-head', 'Arkeoloji Bölüm Başkanlığı', 'Faculty of Arts', 'Archaeology', 'Department Head', true, true],
            // Faculty of Design — department heads
            ['staff-arch-head', 'Mimarlık Bölüm Başkanlığı', 'Faculty of Design', 'Architecture', 'Department Head', true, true],
            ['staff-intarch-head', 'İç Mimarlık Bölüm Başkanlığı', 'Faculty of Design', 'Interior Architecture and Environmental Design', 'Department Head', true, true],
            ['staff-industrial-head', 'Endüstriyel Tasarım Bölüm Başkanlığı', 'Faculty of Design', 'Industrial Design', 'Department Head', true, true],
            ['staff-urbandesign-head', 'Kentsel Tasarım Bölüm Başkanlığı', 'Faculty of Design', 'Urban Design and Landscape Architecture', 'Department Head', true, true],
            // Faculty of Communication
            ['staff-comm-head', 'İletişim Fakültesi Dekanlığı', 'Faculty of Communication', null, 'Dean Office', false, true],
            ['staff-graphic-head', 'Grafik Tasarım Bölüm Başkanlığı', 'Faculty of Communication', 'Visual Communication Design', 'Department Head', true, true],
            ['staff-newmedia-head', 'Yeni Medya Bölüm Başkanlığı', 'Faculty of Communication', 'New Media and Communication', 'Department Head', true, true],
            ['staff-digital-head', 'Dijital Oyun Tasarımı Bölüm Başkanlığı', 'Faculty of Communication', 'Digital Game Design', 'Department Head', true, true],
            ['staff-pr-head', 'Halkla İlişkiler Bölüm Başkanlığı', 'Faculty of Communication', 'New Media and Communication', 'Department Head', true, false],
            ['staff-journalism-head', 'Gazetecilik Bölüm Başkanlığı', 'Faculty of Communication', 'New Media and Communication', 'Department Head', true, false],
            ['staff-radio-head', 'Radyo-TV Bölüm Başkanlığı', 'Faculty of Communication', 'New Media and Communication', 'Department Head', true, false],
            // Faculty of Music and Performing Arts
            ['staff-music-dean', 'Müzik ve Sahne Sanatları Fakültesi Dekanlığı', 'Faculty of Music and Performing Arts', null, 'Dean Office', false, true],
            ['staff-dance-head', 'Modern Dans Bölüm Başkanlığı', 'Faculty of Music and Performing Arts', 'Modern Dance', 'Department Head', true, true],
            ['staff-acting-head', 'Oyunculuk Bölüm Başkanlığı', 'Faculty of Music and Performing Arts', 'Acting', 'Department Head', true, true],
            ['staff-sound-head', 'Ses Sanatları Tasarımı Bölüm Başkanlığı', 'Faculty of Music and Performing Arts', 'Sound Design', 'Department Head', true, true],
            ['staff-music-head', 'Müzik Bölüm Başkanlığı', 'Faculty of Music and Performing Arts', 'Sound Design', 'Department Head', true, false],
            ['staff-comp-head', 'Kompozisyon Bölüm Başkanlığı', 'Faculty of Music and Performing Arts', 'Sound Design', 'Department Head', true, false],
            // Advisors / lecturers (not heads)
            ['staff-arch-adv', 'Mimarlık Danışmanı', 'Faculty of Design', 'Architecture', 'Advisor', false, true],
            ['staff-plastic-adv', 'Plastik Sanatlar Danışmanı', 'Faculty of Arts', 'Fine Arts', 'Advisor', false, true],
            ['staff-graphic-adv', 'Grafik Tasarım Danışmanı', 'Faculty of Communication', 'Visual Communication Design', 'Advisor', false, true],
            ['staff-fashion-adv', 'Moda Tasarımı Danışmanı', 'Faculty of Arts', 'Textile and Fashion Design', 'Advisor', false, true],
            ['staff-cinema-adv', 'Sinema Danışmanı', 'Faculty of Arts', 'Film Design and Management', 'Advisor', false, true],
            ['staff-photo-adv', 'Fotoğraf Danışmanı', 'Faculty of Arts', 'Photography', 'Advisor', false, true],
            ['staff-digital-adv', 'Dijital Oyun Tasarımı Danışmanı', 'Faculty of Communication', 'Digital Game Design', 'Advisor', false, true],
            ['staff-pr-adv', 'Halkla İlişkiler Danışmanı', 'Faculty of Communication', 'New Media and Communication', 'Advisor', false, false],
            ['staff-journalism-adv', 'Gazetecilik Danışmanı', 'Faculty of Communication', 'New Media and Communication', 'Advisor', false, false],
            ['staff-radio-adv', 'Radyo-TV Danışmanı', 'Faculty of Communication', 'New Media and Communication', 'Advisor', false, false],
            ['staff-music-adv', 'Müzik Danışmanı', 'Faculty of Music and Performing Arts', 'Sound Design', 'Advisor', false, false],
            ['staff-workshop-coord', 'Atölye Koordinatörü', 'Faculty of Arts', 'Workshops', 'Coordinator', false, true],
            ['staff-gallery-coord', 'Galeri Koordinatörü', 'Faculty of Arts', 'Gallery', 'Coordinator', false, true],
            ['staff-dorm-coord', 'Yurt Koordinatörü', null, 'Accommodation', 'Coordinator', false, true],
            ['staff-shuttle', 'Servis Koordinatörü', null, 'Transport', 'Coordinator', false, true],
            ['staff-events', 'Etkinlik Ofisi', null, 'Student Affairs', 'Events Office', false, true],
            ['staff-alumni', 'Mezunlar Ofisi', null, 'Career', 'Alumni Office', false, true],
            ['staff-erasmus', 'Erasmus Ofisi', null, 'International', 'Erasmus', false, true],
            ['staff-quality', 'Kalite Ofisi', null, 'Administration', 'Quality', false, true],
            ['staff-hr', 'İnsan Kaynakları', null, 'Administration', 'HR', false, true],
            ['staff-finance', 'Mali İşler', null, 'Administration', 'Finance', false, true],
            ['staff-security', 'Güvenlik', null, 'Security', 'Security', false, true],
            ['staff-health', 'Sağlık Merkezi', null, 'Health', 'Health Center', false, true],
            ['staff-disability', 'Engelsiz Kampüs', null, 'Student Affairs', 'Accessibility', false, true],
        ];

        // Drift guard: every academic department used above must be one of
        // ARUCAD's real departments (Workshops/Gallery are real facilities,
        // not formal departments, so they're allowed alongside them) —
        // catches a future edit that reintroduces an invented department
        // name before it ever reaches the database.
        $allowedNonAcademic = [...CampusTaxonomy::NON_ACADEMIC_DEPARTMENTS, 'Workshops', 'Gallery'];
        foreach ($staff as [$id, , $faculty, $department]) {
            if ($faculty === null || $department === null) {
                continue;
            }
            $isRealDepartment = in_array($department, CampusTaxonomy::departments(), true)
                || in_array($department, $allowedNonAcademic, true);
            if (! $isRealDepartment) {
                throw new \RuntimeException("CampusCatalogSeeder: '{$department}' ({$id}) is not a real ARUCAD department — check CampusTaxonomy.");
            }
        }

        foreach ($staff as [$id, $name, $faculty, $department, $title, $isDeptHead, $active]) {
            StaffProfile::updateOrCreate(['id' => $id], [
                'name' => $name,
                'email' => null,
                'faculty' => $faculty,
                'department' => $department,
                'title' => $title,
                'is_department_head' => $isDeptHead,
                'active' => $active,
            ]);
        }

        // Public unit inboxes already shown on Discover service cards — used
        // so application emails actually have a recipient.
        foreach ([
            'staff-registrar' => 'ogrenciisleri@arucad.edu.tr',
            'staff-intl' => 'international@arucad.edu.tr',
            'staff-career' => 'destek@arucad.edu.tr',
            'staff-clubs' => 'destek@arucad.edu.tr',
            'staff-sports' => 'spor@arucad.edu.tr',
            'staff-pdr' => 'destek@arucad.edu.tr',
            'staff-library' => 'kutuphane@arucad.edu.tr',
            'staff-it' => 'it@arucad.edu.tr',
            'staff-dorm-coord' => 'destek@arucad.edu.tr',
            'staff-disability' => 'destek@arucad.edu.tr',
            'staff-security' => 'destek@arucad.edu.tr',
            'staff-events' => 'destek@arucad.edu.tr',
            // Local real-mail smoke: Architecture head applications → Gmail.
            'staff-arch-head' => 'ezgidemir825@gmail.com',
        ] as $id => $email) {
            StaffProfile::where('id', $id)->update(['email' => $email]);
        }

        $services = [
            [
                'id' => 'student-affairs',
                'title' => 'Öğrenci İşleri (Student Affairs)',
                'category' => 'İdari',
                'description' => 'Kayıt, akademik süreçler, öğrenci kimlik kartı, ders kaydı ve mezuniyete kadar tüm resmi süreçlerde danışmanlık sağlar.',
                'contact' => 'ogrenciisleri@arucad.edu.tr',
                'staff' => 'staff-registrar',
                'topics' => ['Kayıt', 'Öğrenci Kimlik Kartı', 'Ders Kaydı', 'Resmi Belgeler', 'Mezuniyet'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Titan',
                'floor' => '1',
                'contact_person' => 'Öğrenci İşleri',
            ],
            [
                'id' => 'academic-advising',
                'title' => 'Akademik Danışmanlık',
                'category' => 'Akademik',
                'description' => 'Her öğrencinin bölümünden bir akademik danışmanı vardır; ders seçimi ve akademik plan için başvurulur.',
                'contact' => 'ogrenciisleri@arucad.edu.tr',
                'staff' => 'staff-registrar',
                'topics' => ['Ders Seçimi', 'Akademik Plan', 'Danışman Atama'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Titan',
                'floor' => '1',
                'contact_person' => 'Öğrenci İşleri',
            ],
            [
                'id' => 'pdr',
                'title' => 'Psikolojik Danışmanlık Merkezi (PDR)',
                'category' => 'Wellbeing',
                'description' => 'Bireysel ve grup danışmanlığı; akademik, sosyal, duygusal ve kariyer konularında gizlilik esaslı, gönüllü destek.',
                'contact' => 'destek@arucad.edu.tr',
                'staff' => 'staff-pdr',
                'topics' => ['Bireysel Danışmanlık', 'Grup Danışmanlığı', 'Gizlilik'],
                'hours' => 'Randevu ile',
                'building' => 'Minotaur',
                'contact_person' => 'PDR Birimi',
            ],
            [
                'id' => 'career',
                'title' => 'Kariyer ve Mezun Ofisi',
                'category' => 'Kariyer',
                'description' => 'İş/staj fırsatları, kariyer etkinlikleri, portfolyo değerlendirmesi ve mezun ağı bağlantıları.',
                'contact' => 'destek@arucad.edu.tr',
                'staff' => 'staff-career',
                'topics' => ['İş/Staj Fırsatları', 'Kariyer Etkinlikleri', 'Portfolyo Değerlendirmesi', 'Mezun Ağı'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Titan',
                'contact_person' => 'Kariyer Danışmanı',
            ],
            [
                'id' => 'library',
                'title' => 'Kütüphane',
                'category' => 'Akademik',
                'description' => 'Sanat, tasarım ve iletişim odaklı kaynaklar, sessiz çalışma alanları ve dijital çalışma istasyonları.',
                'contact' => 'kutuphane@arucad.edu.tr',
                'staff' => 'staff-library',
                'topics' => ['Kaynak Ödünç Alma', 'Sessiz Çalışma Alanları'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Meditation',
                'contact_person' => 'Kütüphane Müdürlüğü',
            ],
            [
                'id' => 'international',
                'title' => 'Uluslararası Öğrenci Ofisi',
                'category' => 'International',
                'description' => 'Vize/ikamet süreçleri, kayıt, ulaşım ve yeni gelen uluslararası öğrencilerin kampüse uyumu.',
                'contact' => 'international@arucad.edu.tr',
                'staff' => 'staff-intl',
                'topics' => ['Vize/İkamet', 'Kayıt', 'Ulaşım', 'Kampüse Uyum'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Eternal Idol',
                'contact_person' => 'Uluslararası Ofis',
            ],
            [
                'id' => 'dormitory',
                'title' => 'Yurt (Dormitory)',
                'category' => 'Konaklama',
                'description' => 'Oda/bina bilgisi, ortak yaşam düzeni, bakım talepleri ve yurt duyuruları.',
                'contact' => 'destek@arucad.edu.tr',
                'staff' => 'staff-dorm-coord',
                'topics' => ['Oda/Bina Bilgisi', 'Bakım Talepleri', 'Yurt Duyuruları'],
                'building' => 'ARUCAD Dormitory',
                'contact_person' => 'Yurt Koordinatörü',
            ],
            [
                'id' => 'it',
                'title' => 'Bilgi İşlem (IT)',
                'category' => 'Teknik',
                'description' => 'Kampüs ağı, e-posta/sistem erişimi ve teknik destek talepleri.',
                'contact' => 'it@arucad.edu.tr',
                'staff' => 'staff-it',
                'topics' => ['Kampüs Ağı', 'E-posta/Sistem Erişimi', 'Teknik Destek'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Titan',
                'contact_person' => 'BT Destek',
            ],
            [
                'id' => 'accessibility',
                'title' => 'Engelli Öğrenci Destek Birimi',
                'category' => 'Erişilebilirlik',
                'description' => 'Erişilebilir rota, sınıf/etkinlik düzenlemeleri ve bireysel destek ihtiyaçları için başvuru noktası.',
                'contact' => 'destek@arucad.edu.tr',
                'staff' => 'staff-disability',
                'topics' => ['Erişilebilir Rota', 'Sınıf/Etkinlik Düzenlemeleri', 'Bireysel Destek'],
                'building' => 'Titan',
                'contact_person' => 'Engelsiz Kampüs',
            ],
            [
                'id' => 'lost-found',
                'title' => 'Kayıp & Bulunan',
                'category' => 'Yardım',
                'description' => 'Kampüste kaybolan veya bulunan eşyalar için başvuru.',
                'contact' => 'ogrenciisleri@arucad.edu.tr',
                'staff' => 'staff-registrar',
                'topics' => ['Kayıp Eşya Bildirimi', 'Bulunan Eşya Teslimi'],
                'hours' => 'Hafta içi 09:00–17:00',
                'building' => 'Titan',
                'floor' => '1',
                'contact_person' => 'Öğrenci İşleri',
            ],
        ];
        foreach ($services as $row) {
            ServiceItem::updateOrCreate(['id' => $row['id']], [
                'title' => $row['title'],
                'category' => $row['category'],
                'description' => $row['description'],
                'contact' => $row['contact'],
                'topics' => $row['topics'],
                'hours' => $row['hours'] ?? null,
                'building' => $row['building'] ?? null,
                'floor' => $row['floor'] ?? null,
                'contact_person' => $row['contact_person'] ?? null,
                'responsible_staff_id' => $row['staff'],
            ]);
        }

        $legacyStudentAffairs = ServiceItem::find('service-student-affairs');
        if ($legacyStudentAffairs && ServiceItem::find('student-affairs')) {
            \App\Models\DirectoryEntry::where('related_service_id', 'service-student-affairs')
                ->update(['related_service_id' => 'student-affairs']);
            $legacyStudentAffairs->delete();
        }

        $directory = [
            ['dir-student-affairs', 'Titan', '1', null, 'Öğrenci İşleri Ofisi', 'İdari Birim', 'student-affairs'],
            ['dir-academic-advising', 'Titan', '1', null, 'Akademik Danışmanlık', 'Akademik', 'academic-advising'],
            ['dir-pdr', 'Minotaur', null, null, 'PDR Birimi', 'Danışmanlık', 'pdr'],
            ['dir-career', 'Titan', null, null, 'Kariyer ve Mezun Ofisi', 'Kariyer', 'career'],
            ['dir-library', 'Meditation', null, null, 'Kütüphane', 'Akademik', 'library'],
            ['dir-international', 'Eternal Idol', null, null, 'Uluslararası Öğrenci Ofisi', 'International', 'international'],
            ['dir-dormitory', 'ARUCAD Dormitory', null, null, 'Yurt Koordinatörlüğü', 'Konaklama', 'dormitory'],
            ['dir-it', 'Titan', null, null, 'Bilgi İşlem', 'Teknik', 'it'],
            ['dir-accessibility', 'Titan', null, null, 'Engelsiz Kampüs', 'Erişilebilirlik', 'accessibility'],
            ['dir-lost-found', 'Titan', '1', null, 'Kayıp & Bulunan', 'Yardım', 'lost-found'],
        ];
        foreach ($directory as [$id, $building, $floor, $room, $name, $role, $serviceId]) {
            \App\Models\DirectoryEntry::updateOrCreate(['id' => $id], [
                'building' => $building,
                'floor' => $floor,
                'room' => $room,
                'occupant_name' => $name,
                'occupant_role' => $role,
                'related_service_id' => $serviceId,
            ]);
        }

        // ARUCAD's five published shuttle lines, exactly as posted on the
        // campus noticeboards. This is a fixed timetable, not a live GPS
        // feed, so "next departure" is computed from the clock.
        $shuttles = [
            ['shuttle-nicosia', 'ARUCAD – Lefkoşa – ARUCAD', 'primary', 1, [
                'ARUCAD Kyrenia Kampüsü', 'Boğaz', 'Gönyeli Kavşağı', 'Lefkoşa Fuarı', 'Honda',
                'Jet Gaz', 'Macro', 'Terminal', 'Girne Kapısı', 'Merit Hotel', 'Hastane',
                'Gönyeli Kavşağı', 'Boğaz', 'ARUCAD Kyrenia Kampüsü',
            ], ['07:00', '11:00', '13:00', '16:00', '18:00', '20:00'], null],
            ['shuttle-alsancak', 'ARUCAD – Alsancak – ARUCAD', 'danger', 2, [
                'ARUCAD Kyrenia Kampüsü', 'British Cemetery', 'Fountain Roundabout', 'Nusmar Market',
                'Bakır Apart', 'Uzun Petrol', 'Sharaf', 'Starling', 'Merit Hotel Işıkları',
                'Dima Supermarket', 'Kervansaray', 'China Bazaar', 'Edremit (Karaoğlanoğlu)',
                'Macro (Karaoğlanoğlu)', 'Kaşgar', 'Barış Park', 'Girne Belediyesi',
                'ARUCAD Kyrenia Kampüsü',
            ], ['08:00', '10:00', '12:00', '14:00', '16:00', '19:00'], null],
            ['shuttle-catalkoy', 'ARUCAD – Çatalköy – ARUCAD', 'campusGreen', 3, [
                'ARUCAD Kyrenia Kampüsü', 'Mahkemeler', 'Atölyeler Binası', 'Kibet', 'Giralı Fırın',
                'Metropol Market', 'Hankor Motor', 'Barkot Market', 'Çin Pazarı', 'Dima Supermarket',
                'Çoban Trading', 'Şah Market', 'Supreme Supermarket', 'Tempo Super Market',
            ], ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00'], null],
            ['shuttle-bandabuliya', 'ARUCAD – Bandabuliya – ARUCAD', 'warning', 4, [
                'ARUCAD Kyrenia Kampüsü', 'Bandabuliya',
            ], ['07:30', '10:00', '15:00'], ['09:00', '13:00', '18:00']],
            ['shuttle-iris', 'ARUCAD – Atölye Binası (Iris) – ARUCAD', 'yellow', 5, [
                'ARUCAD Kyrenia Kampüsü', 'Mahkemeler', 'Akçiçek Hastanesi',
                'ARUCAD Atölye Binası (Iris)',
            ], ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00'], null],
        ];
        foreach ($shuttles as [$id, $name, $colorKey, $order, $stops, $departures, $returns]) {
            ShuttleRoute::updateOrCreate(['id' => $id], [
                'name' => $name,
                'color_key' => $colorKey,
                'stops' => $stops,
                'departures' => $departures,
                'returns' => $returns,
                'sort_order' => $order,
            ]);
        }

        // ARUCAD's published sports teams. Kyrenia Municipality's tennis
        // courts are available to students on request, so tennis stays in
        // the list as a facility even though it has no standing team.
        $sports = [
            ['sport-basketball', 'ARUCAD Basketbol Takımı (Erkek)', 'Spor Salonu'],
            ['sport-3x3', 'ARUCAD 3×3 Basketbol Takımı (Erkek ve Kadın)', 'Açık Saha'],
            ['sport-table-tennis', 'ARUCAD Masa Tenisi (Erkek ve Kadın)', 'Spor Salonu'],
            ['sport-futsal', 'ARUCAD Futsal Takımı (Erkek)', 'Spor Salonu'],
            ['sport-football', 'ARUCAD Futbol Takımı (Erkek)', 'Açık Saha'],
            ['sport-anka-table-tennis', 'ARUCAD ANKA Masa Tenisi Kulübü', 'Spor Salonu'],
            ['sport-bowling', 'ARUCAD Bowling', 'Kampüs Dışı Etkinlik'],
            ['sport-dart', 'ARUCAD Dart', 'Sosyal Alan'],
            ['sport-billiards', 'ARUCAD Bilardo', 'Sosyal Alan'],
            ['sport-tennis', 'Tenis (Girne Belediyesi Kortları)', 'Tenis Kortu'],
        ];
        foreach ($sports as [$id, $name, $facility]) {
            Sport::updateOrCreate(['id' => $id], [
                'name' => $name,
                'facility' => $facility,
                'contact' => 'sports@arucad.edu.tr / 1006',
                'responsible_staff_id' => 'staff-sports',
            ]);
        }

        // ARUCAD's 19 active student clubs, under their official Turkish
        // names as published by the university. Ids stay stable so existing
        // memberships and event links survive the rename.
        $clubs = [
            ['club-blender', 'Blender Modelleme ve Tasarım Kulübü', 'Dijital', '3D modelleme ve dijital tasarım pratiği.'],
            ['club-comic', 'Çizgi Roman ve Anime Kulübü', 'Kültür', 'Çizgi roman ve anime kültürü etkinlikleri.'],
            ['club-dance', 'Dans Kulübü', 'Sahne', 'Farklı dans stillerinde açık provalar.'],
            ['club-nature-sports', 'Doğa Sporları Kulübü', 'Spor', 'Doğa yürüyüşü ve outdoor aktiviteler.'],
            ['club-martial-arts', 'Dövüş Sanatları Kulübü', 'Spor', 'Dövüş sanatları eğitimi ve pratik seansları.'],
            ['club-esports', 'E-Spor ve Oyun Tasarımı Kulübü', 'Dijital', 'Turnuvalar ve oyun tasarımı üzerine buluşmalar.'],
            ['club-persian', 'Fars Kulübü', 'Kültür', 'Fars dili ve kültürü üzerine paylaşım buluşmaları.'],
            ['club-photography', 'Fotoğraf Kulübü', 'Sanat', 'Fotoğraf yürüyüşleri ve teknik atölyeler.'],
            ['club-graffiti', 'Grafiti Kulübü', 'Sanat', 'Sokak sanatı teknikleri ve ortak duvar projeleri.'],
            ['club-hiphop', 'Hip-Hop Kulübü', 'Sahne', 'Hip-hop dans ve müzik kültürü.'],
            ['club-quality', 'Kalite Kulübü', 'Topluluk', 'Kampüs kalite ve erişilebilirlik projelerine gönüllü katkı.'],
            ['club-architecture', 'Mimarlık Kulübü', 'Tasarım', 'Mimari geziler, atölyeler ve öğrenci proje eleştirileri.'],
            ['club-fashion', 'Moda Kulübü', 'Tasarım', 'Moda tasarımı ve stil projeleri.'],
            ['club-music', 'Müzik Kulübü', 'Sahne', 'Prova, jam session ve küçük performanslar.'],
            ['club-art', 'Sanat Kulübü', 'Sanat', 'Karma teknik atölyeler ve öğrenci sergileri.'],
            ['club-cinema', 'Sinema Kulübü', 'Film', 'Film gösterimleri ve tartışma geceleri.'],
            ['club-charity', 'Sosyal Yardımlaşma Kulübü', 'Topluluk', 'Sosyal sorumluluk ve bağış projeleri.'],
            ['club-bouldering', 'Tırmanış Kulübü', 'Spor', 'Kaya tırmanışı meraklıları için düzenli çıkışlar.'],
            ['club-drama', 'Tiyatro Kulübü', 'Sahne', 'Oyunculuk atölyeleri ve sahne prodüksiyonları.'],
        ];
        foreach ($clubs as [$id, $name, $category, $description]) {
            Club::updateOrCreate(['id' => $id], [
                'name' => $name,
                'category' => $category,
                'description' => $description,
                'responsible_staff_id' => 'staff-clubs',
            ]);
        }

        Club::query()->whereNull('responsible_staff_id')->update(['responsible_staff_id' => 'staff-clubs']);
        Event::query()->whereNull('responsible_staff_id')->update(['responsible_staff_id' => 'staff-clubs']);

        $opportunities = [
            ['career-internship-studio', 'Stüdyo stajı', 'internship', 'ARUCAD Kariyer ve Mezun Ofisi',
                'Kampüs stüdyolarında dönemlik staj — ön başvuru Kariyer Ofisi tarafından incelenir.'],
            ['career-job-alumni', 'Mezun ağı iş ilanı', 'job', 'ARUCAD Kariyer ve Mezun Ofisi',
                'Mezun ağından gelen açık pozisyonlar — CV ve portfolyo ile başvurun.'],
            ['career-portfolio-review', 'Portfolyo değerlendirme günü', 'event', 'ARUCAD Kariyer ve Mezun Ofisi',
                'Portfolyonuzu kariyer danışmanıyla birlikte gözden geçirme seansı.'],
        ];
        foreach ($opportunities as [$id, $title, $kind, $org, $description]) {
            \App\Models\CareerOpportunity::updateOrCreate(['id' => $id], [
                'title' => $title,
                'kind' => $kind,
                'organization' => $org,
                'description' => $description,
                'purpose' => $description,
                'published' => true,
                'created_at' => now(),
            ]);
        }

        $consultations = [
            ['consult-cv-review', 'CV Review', 'CV ve portfolyonun kariyer danışmanı ile birlikte gözden geçirilmesi.',
                'Staj veya iş arayan öğrenciler.', 'Bire bir dosya incelemesi ve geribildirim.',
                'Daha güçlü bir CV ve net sonraki adımlar.', '45 dk', 'Yüz yüze / çevrim içi',
                'Güncel CV taslağı getirin.'],
            ['consult-mock-interview', 'Mock Interview', 'Gerçek bir iş görüşmesine hazırlık simülasyonu.',
                'Mülakat sürecindeki öğrenciler.', 'Soru-cevap, duruş ve portfolyo anlatımı.',
                'Görüşme özgüveni ve somut geribildirim.', '60 dk', 'Yüz yüze',
                'Başvurduğunuz ilanın özeti.'],
            ['consult-portfolio', 'Portfolio Review', 'Görsel portfolyonuzun sektör bakışıyla değerlendirilmesi.',
                'Tasarım ve sanat bölümü öğrencileri.', 'Seçilmiş işlerin birlikte okunması.',
                'Portfolyo anlatısı ve eksiklerin listesi.', '60 dk', 'Stüdyo',
                'Dijital veya basılı portfolyo.'],
            ['consult-alumni', 'Alumni Network', 'Mezun ağı üzerinden bağlantı ve yönlendirme.',
                'Mezuniyet aşamasındaki öğrenciler.', 'Hedef sektör ve mezun eşleştirmesi.',
                'İletişim kurulacak mezun önerileri.', '30 dk', 'Çevrim içi',
                'Kısa kariyer hedefi notu.'],
        ];
        foreach ($consultations as [$id, $title, $purpose, $audience, $content, $outcomes, $duration, $format, $requirements]) {
            \App\Models\Consultation::updateOrCreate(['id' => $id], [
                'title' => $title,
                'purpose' => $purpose,
                'audience' => $audience,
                'content' => $content,
                'outcomes' => $outcomes,
                'duration' => $duration,
                'format' => $format,
                'requirements' => $requirements,
                'counselor_name' => 'Kariyer ve Mezun Ofisi',
                'published' => true,
            ]);
        }
    }

    /** Published 3DVista scenes on 360.arucad.edu.tr — not /tour?campusId=. */
    private static function tourUrlFor(float $lat, float $lng): string
    {
        $sites = [
            [35.337395, 33.321358, 'https://360.arucad.edu.tr/vista_export/Main/index.htm'],
            [35.175513, 33.365029, 'https://360.arucad.edu.tr/vista_export/Bandabuliya/index.htm'],
            [35.333593, 33.330680, 'https://360.arucad.edu.tr/vista_export/Atelier/index.htm'],
        ];
        $best = $sites[0];
        $bestD = PHP_FLOAT_MAX;
        foreach ($sites as $site) {
            $d = ($lat - $site[0]) ** 2 + ($lng - $site[1]) ** 2;
            if ($d < $bestD) {
                $bestD = $d;
                $best = $site;
            }
        }

        return $best[2];
    }
}
