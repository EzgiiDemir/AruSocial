<?php

namespace Database\Seeders;

use App\Models\CrawlSource;
use Illuminate\Database\Seeder;

/**
 * The ARUCAD web sites the AICAD crawler reads, as provided by ARUCAD.
 * Idempotent — safe to re-run; existing rows (and any operator edits to
 * `access`/`enabled`) are preserved except for the label.
 */
class CrawlSourceSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Starting keys per source, in all three languages.
         *
         * These are a first pass by whoever wrote the seeder, not a finished
         * taxonomy — the point of putting them in the admin panel is that the
         * people who answer student questions can correct them without a
         * deploy. Keep them to words a student would actually type; a key that
         * matches everything ranks nothing.
         */
        $sources = [
            ['arucad.edu.tr', 'ARUCAD Ana Site', CrawlSource::ACCESS_GLOBAL,
                'kampus, campus, кампус, kutuphane, library, библиотека, etkinlik, event, '
                .'мероприятие, haber, news, новости, fakulte, faculty, факультет, akademik, '
                .'takvim, calendar, календарь, ulasim, transport, транспорт, yurt, dormitory, '
                .'общежитие, kulup, club, клуб, spor, sport, спорт, yonetmelik, regulation, '
                .'iletisim, contact, контакт, rektor, rector, ректор, misyon, vizyon, hakkimizda, '
                // The supplied site inventory showed /rt-notice/ carries exam
                // schedules and /teams/ the staff pages — both things students
                // ask for constantly and neither obvious from the old key list.
                .'sinav, exam, экзамен, final, vize, midterm, duyuru, announcement, объявление, '
                .'bolum, program, programme, программа, hoca, akademisyen, staff, преподаватель, '
                .'mezuniyet, graduation, выпуск, atolye, workshop, мастерская, sergi, exhibition'],
            ['prospective.arucad.edu.tr', 'Prospective', CrawlSource::ACCESS_GLOBAL,
                'admission, apply, application, tuition, fee, scholarship, international, '
                .'requirements, undergraduate, postgraduate'],
            ['aday.arucad.edu.tr', 'Aday', CrawlSource::ACCESS_GLOBAL,
                'burs, ucret, harc, basvuru, kayit, kontenjan, taban, puan, aday, program, '
                .'bolum, yatay, dikey, gecis, denklik, стипендия, оплата, поступление'],
            ['kibrisaday.arucad.edu.tr', 'Kıbrıs Aday', CrawlSource::ACCESS_GLOBAL,
                'kibris, kktc, yerel, ogrenci, basvuru, burs, kayit'],
            ['quality.arucad.edu.tr', 'Quality', CrawlSource::ACCESS_GLOBAL,
                'kalite, quality, akreditasyon, accreditation, yodak, yokak, komisyon, '
                .'surec, policy, аккредитация'],
            ['360.arucad.edu.tr', '360 Kampüs', CrawlSource::ACCESS_GLOBAL,
                'sanal, tur, 360, virtual, tour, gezinti, виртуальный'],
            ['broadcast.arucad.edu.tr', 'Broadcast', CrawlSource::ACCESS_GLOBAL,
                'yayin, broadcast, radyo, radio, televizyon, tv, podcast, stüdyo, studio'],
            ['qualityhub.arucad.edu.tr', 'Quality Hub', CrawlSource::ACCESS_LOCAL,
                'kalite, dokuman, belge, yonerge, document, procedure'],

            /*
             * These two were in config('knowledge.allowed_domains') but never
             * in this table, and the table WINS once it has any row — so both
             * quietly stopped being crawlable the day the first source was
             * seeded. Between them they already hold eleven indexed pages,
             * which were frozen: never refreshed, and no new page on either
             * host could ever be found.
             */
            ['apply.arucad.edu.tr', 'Apply', CrawlSource::ACCESS_GLOBAL,
                'basvuru, apply, application, online, form, kayit, поступление'],
            ['academics.arucad.edu.tr', 'Academics', CrawlSource::ACCESS_GLOBAL,
                'akademik, academic, ders, course, mufredat, curriculum, syllabus'],

            // From the supplied site inventory. `sis.arucad.edu.tr` appeared
            // there too and is deliberately NOT added: it is a login portal
            // with no public content, config/knowledge.php excludes it by
            // name for that reason, and PersonalDataCapabilities already
            // tells students plainly that SIS is not connected.
            ['library.arucad.edu.tr', 'Kütüphane Katalog', CrawlSource::ACCESS_GLOBAL,
                'kutuphane, library, библиотека, katalog, catalogue, kitap, book, книга, odunc'],
        ];

        foreach ($sources as [$domain, $label, $access, $keys]) {
            // Keys are seeded only on FIRST create, alongside the rest of the
            // row. An operator who has tuned them in the admin panel must not
            // have that overwritten the next time the seeder runs.
            CrawlSource::firstOrCreate(
                ['domain' => $domain],
                ['label' => $label, 'access' => $access, 'enabled' => true, 'keys' => $keys],
            );
        }

        // Existing installs predate the keys column, so their rows were
        // created without one. Fill those in once; anything an operator has
        // already written is left exactly as it is.
        foreach ($sources as [$domain, , , $keys]) {
            CrawlSource::query()
                ->where('domain', $domain)
                ->where(fn ($q) => $q->whereNull('keys')->orWhere('keys', ''))
                ->update(['keys' => $keys]);
        }
    }
}
