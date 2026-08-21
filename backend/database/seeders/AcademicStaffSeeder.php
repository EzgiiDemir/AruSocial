<?php

namespace Database\Seeders;

use App\Models\AcademicStaff;
use Illuminate\Database\Seeder;

// Real ARUCAD personnel roster (docs/EKSIKLER.md aktivite/onay workflow
// §8) — the exact 59-person list supplied, transcribed faithfully. Emails
// marked "doğrulama gerekli" in the source are seeded with email=null,
// email_verified=false rather than guessed — this directory is honest
// about what it doesn't actually know, since a wrong guessed email would
// silently misroute a real approval request.
class AcademicStaffSeeder extends Seeder
{
    public function run(): void
    {
        // [name, email|null, verified, type, faculty|null, department|null, title|null, isDeptHead, isDean]
        $rows = [
            ['Ahmet İyici', 'ahmet.iyici@arucad.edu.tr', true, 'Akademik', 'İletişim Fakültesi', 'Yeni Medya ve İletişim', null, false, false],
            ['Ali Çağan Uzman', null, false, 'Akademik', 'İletişim Fakültesi', 'Yeni Medya ve İletişim / Dijital Oyun', null, false, false],
            ['Aliye Menteş Yardımcı', 'aliye.yardimci@arucad.edu.tr', true, 'Akademik', 'Tasarım Fakültesi', 'Kentsel Tasarım ve Peyzaj Mimarlığı', null, false, false],
            ['Arif Celal Sözer', 'arif.sozer@arucad.edu.tr', true, 'Akademik', 'İngilizce Hazırlık', 'İngilizce', null, false, false],
            ['Asım Vehbi', 'asim.vehbi@arucad.edu.tr', true, 'Yönetim', 'Rektörlük', null, 'Rektör', false, false],
            ['Asu Uzbaşlı', null, false, 'Akademik', null, null, null, false, false],
            ['Ataman Kınış', 'ataman.kinis@arucad.edu.tr', true, 'Akademik', 'Müzik ve Sahne Sanatları', 'Ses Sanatları Tasarımı', null, false, false],
            ['Bekir Şimşek', null, false, 'Akademik', null, null, null, false, false],
            ['Berke Uluşan', null, false, 'Akademik', 'Sanat Fakültesi', 'Tekstil ve Moda Tasarımı', null, false, false],
            ['Burçin Saltık', null, false, 'Akademik/Yönetim', 'Tasarım Fakültesi', 'Endüstriyel Tasarım', 'Bölüm Başkanı', true, false],
            ['Burcu Toker', 'burcu.toker@arucad.edu.tr', true, 'Yönetim/Akademik', 'Tasarım Fakültesi', null, 'Dekan; Rektör Yardımcısı', false, true],
            ['Çağdaş Öğüç', 'cagdas.oguc@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Yeni Medya ve İletişim', 'Bölüm Başkanı', true, false],
            ['Cengiz Bodur', 'cengiz.bodur@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Ehsan Daneshyar', 'ehsan.daneshyar@arucad.edu.tr', true, 'Akademik/Yönetim', 'Tasarım Fakültesi', 'İç Mimarlık ve Çevre Tasarımı', 'Bölüm Başkanı', true, false],
            ['Elçin Şener', 'elcin.sener@arucad.edu.tr', true, 'Akademik', 'Sanat Fakültesi', 'Seramik', null, false, false],
            ['Elham Etemadi', null, false, 'Akademik', null, null, null, false, false],
            ['Elnaz Nasehi', null, false, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Film/Fotograf', 'Bölüm Başkanı', true, false],
            ['Emad Abouata Amlashi', null, false, 'Akademik', 'İletişim Fakültesi', 'Görsel İletişim Tasarımı', null, false, false],
            ['Emrah Öztürk', 'emrah.ozturk@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Film Tasarımı ve Yönetimi', 'Bölüm Başkanı', true, false],
            ['Emre Çelikkol', 'emre.celikkol@arucad.edu.tr', true, 'Akademik', 'Sanat Fakültesi', 'Plastik Sanatlar', null, false, false],
            ['Emre Günal', 'emre.gunal@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Esra Plümer Bardak', 'esra.plumer@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Farshad Asgarikia', null, false, 'Akademik', null, null, null, false, false],
            ['Görkem Esengöl', 'gorkem.esengol@arucad.edu.tr', true, 'Akademik', 'İletişim Fakültesi', null, null, false, false],
            ['Güneş Kozal', 'gunes.kozal@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Gülay Çetinkaya Çiftçioğlu', null, false, 'Akademik/Yönetim', 'Tasarım Fakültesi', 'Kentsel Tasarım ve Peyzaj Mimarlığı', 'Bölüm Başkanı', true, false],
            ['Hakan Karahasan', 'hakan.karahasan@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Görsel İletişim Tasarımı', 'Bölüm Başkanı', true, false],
            ['Handan Ergiydiren', 'handan.ergiydiren@arucad.edu.tr', true, 'Akademik/Yönetim', 'Müzik ve Sahne Sanatları', 'Modern Dans', 'Bölüm Başkanı', true, false],
            ['Huri Yontucu', 'huri.yontucu@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['İbrahim Dalkılıç', 'ibrahim.dalkilic@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Görsel İletişim Tasarımı', 'Rektör Yardımcısı', false, false],
            ['İnal Bilsel', 'inal.bilsel@arucad.edu.tr', true, 'Akademik/Yönetim', 'Müzik ve Sahne Sanatları', 'Ses Sanatları Tasarımı', 'Bölüm Başkanı', true, false],
            ['İnanç Uçaröz', 'inanc.ucaroz@arucad.edu.tr', true, 'Akademik/Yönetim', 'İngilizce Hazırlık', null, 'Hazırlık Okulu Müdürü', false, false],
            ['Juan Castrillon', 'juan.castrillon@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Kerem Karaboğa', 'kerem.karaboga@arucad.edu.tr', true, 'Akademik/Yönetim', 'Müzik ve Sahne Sanatları', 'Oyunculuk', 'Fakülte Dekanı', false, true],
            ['Marjan Alikhanzadeh', 'marjan.alikhanzadeh@arucad.edu.tr', true, 'Akademik', 'Tasarım Fakültesi', 'Kentsel Tasarım ve Peyzaj Mimarlığı', null, false, false],
            ['Marko M. Kiessel', 'marko.kiessel@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Martina Callegaro', 'martina.callegaro@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['M. Cem Yardımcı', 'cem.yardimci@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Mehdi Nourani', 'mehdi.nourani@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Merve Arkan', 'merve.arkan@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Nazgol Hafizi', null, false, 'Akademik/Yönetim', 'Tasarım Fakültesi', 'Mimarlık', 'Bölüm Başkanı', true, false],
            ['Nazia Nawaz', 'nazia.nawaz@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Nezahat Doğan', 'nezahat.dogan@arucad.edu.tr', true, 'Yönetim/Akademik', 'İletişim Fakültesi', null, 'Dekan; Rektör Yardımcısı', false, true],
            ['Nur Onat', 'nur.onat@arucad.edu.tr', true, 'Yönetim/Akademik', 'Sanat Fakültesi', null, 'Dekan', false, true],
            ['Nuran Öze', 'nuran.oze@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Yeni Medya', 'Enstitü Müdürü', false, false],
            ['Olgica Grcheva', 'olgica.grcheva@arucad.edu.tr', true, 'Akademik', 'Tasarım Fakültesi', 'Mimarlık', null, false, false],
            ['Özge Güzeltepe', 'ozge.guzeltepe@arucad.edu.tr', true, 'Akademik', 'İngilizce Hazırlık', 'İngilizce', null, false, false],
            ['Özgün Can Karaburun', 'ozgun.karaburun@arucad.edu.tr', true, 'Akademik/Yönetim', 'Müzik ve Sahne Sanatları', 'Oyunculuk', 'Bölüm Başkanı', true, false],
            ['Panteha Farmanesh', 'panteha.farmnesh@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Görsel İletişim', 'Rektör Danışmanı; Araştırma Merkezi', false, false],
            ['Pervin Kurçeren', 'pervin.kurceren@arucad.edu.tr', true, 'Akademik/Yönetim', 'Sanat Fakültesi', 'Plastik Sanatlar', 'Bölüm Başkanı', true, false],
            ['Raif Dimililer', 'raif.dimililer@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Saloumeh Kahuei', 'saloumeh.kahuei@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Şafak Ersözlü', 'safak.ersozlu@arucad.edu.tr', true, 'Akademik', 'Müzik ve Sahne Sanatları', 'Oyunculuk', null, false, false],
            ['Sinan Arkın', null, false, 'Yönetim', 'Mütevelli Heyeti', null, 'Mütevelli Heyeti Başkanı', false, false],
            ['Soad Abokhamis Mousavi', 'soad.mousavi@arucad.edu.tr', true, 'Akademik', 'Tasarım Fakültesi', 'İç Mimarlık ve Çevre Tasarımı', null, false, false],
            ['Tina Davoodi', 'tina.davoodi@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Turan Aksoy', 'turan.aksoy@arucad.edu.tr', true, 'Akademik', null, null, null, false, false],
            ['Yıldız Sarah Güventürk', null, false, 'Akademik', null, null, null, false, false],
            ['Yunus Luckinger', 'yunus.luckinger@arucad.edu.tr', true, 'Akademik/Yönetim', 'İletişim Fakültesi', 'Dijital Oyun Tasarımı', 'Bölüm Başkanı', true, false],
        ];

        foreach ($rows as $i => [$name, $email, $verified, $type, $faculty, $department, $title, $isDeptHead, $isDean]) {
            AcademicStaff::create([
                'id' => 'staff-'.($i + 1),
                'name' => $name,
                'email' => $email,
                'email_verified' => $verified,
                'type' => $type,
                'faculty' => $faculty,
                'department' => $department,
                'title' => $title,
                'is_department_head' => $isDeptHead,
                'is_faculty_dean' => $isDean,
            ]);
        }
    }
}
