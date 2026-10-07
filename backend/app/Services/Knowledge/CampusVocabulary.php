<?php

namespace App\Services\Knowledge;

use App\Support\TextFold;

/**
 * The campus words a student might ask in, in each of the three
 * languages the app supports.
 *
 * This exists because of a measurement, not a hunch. The sentence model
 * that ranks pages by meaning does not bridge English or Russian topic
 * vocabulary to Turkish page text on this corpus: "What scholarships are
 * available?" scored **0.115** against the title "Burslar ve Ücretler",
 * below three pages that have nothing to do with scholarships. It works
 * well *within* a language and it handles paraphrase; it does not
 * reliably translate. That is a property of the model, and no threshold
 * fixes it.
 *
 * Translating the question with the LLM would fix it and would cost a
 * model call per question — the opposite of the goal, with thousands of
 * students on a shared free quota. So the cross-language step is a fixed
 * list instead: the fifty-odd things students actually ask a university
 * about. It is deterministic, free, adds no latency, and is wrong only
 * in the way a missing entry is wrong — the semantic and keyword signals
 * still run underneath it.
 *
 * Every term is stored folded (see TextFold), so "ucret" matches
 * "ücret".
 *
 * Adding a topic: put every wording a student might use in one row,
 * across all three languages. Rows are matched, not translated — the
 * point is only that the words lead to the same pages.
 */
final class CampusVocabulary
{
    /**
     * Equivalence groups. Any term in a row expands the query to the
     * whole row.
     *
     * @var list<list<string>>
     */
    private const GROUPS = [
        // Money
        ['burs', 'burslar', 'scholarship', 'scholarships', 'стипендия', 'стипендии'],
        ['ucret', 'ucretler', 'harc', 'tuition', 'fee', 'fees', 'оплата', 'стоимость'],
        ['indirim', 'discount', 'скидка'],
        ['odeme', 'taksit', 'payment', 'instalment', 'installment', 'платеж'],

        // Admission
        ['basvuru', 'kayit', 'application', 'apply', 'admission', 'enrolment',
            'enrollment', 'register', 'registration', 'поступление', 'заявление', 'регистрация'],
        ['kontenjan', 'quota', 'capacity', 'квота'],
        ['taban', 'puan', 'score', 'ranking', 'балл', 'баллы'],
        ['transkript', 'transcript', 'транскрипт'],
        ['denklik', 'equivalence', 'эквивалентность'],
        ['yatay', 'gecis', 'transfer', 'перевод'],

        // Places
        ['kutuphane', 'library', 'библиотека'],
        ['yurt', 'konaklama', 'dormitory', 'dorm', 'accommodation', 'housing',
            'residence', 'общежитие', 'жилье'],
        ['yemekhane', 'kafeterya', 'kantin', 'canteen', 'cafeteria', 'dining',
            'столовая', 'кафетерий'],
        ['laboratuvar', 'atolye', 'lab', 'laboratory', 'workshop', 'studio',
            'лаборатория', 'мастерская'],
        ['kampus', 'campus', 'кампус'],
        ['ulasim', 'servis', 'shuttle', 'transport', 'transportation', 'bus',
            'транспорт', 'автобус'],
        ['otopark', 'parking', 'парковка'],

        /*
         * Club and activity subjects.
         *
         * The club list is authored in Turkish and the question may not be:
         * "is there a dance club" has to reach "Dans Kulübü". Without these
         * the existence check answered "no club by that name" for a club
         * that exists, which is a worse failure than the invention it
         * replaced.
         */
        ['dans', 'dance', 'dancing', 'танец', 'танцы'],
        ['tiyatro', 'theatre', 'theater', 'drama', 'театр'],
        ['fotograf', 'photography', 'photo', 'фотография'],
        ['sinema', 'cinema', 'film', 'movie', 'кино'],
        ['muzik', 'music', 'музыка'],
        ['moda', 'fashion', 'мода'],
        ['tirmanis', 'climbing', 'bouldering', 'скалолазание'],
        ['satranc', 'chess', 'шахматы'],
        ['oyun', 'game', 'gaming', 'espor', 'esports', 'игра'],
        ['grafiti', 'graffiti', 'граффити'],
        ['dovus', 'martial', 'дзюдо', 'единоборства'],
        ['doga', 'nature', 'outdoor', 'природа'],
        ['mimarlik', 'architecture', 'архитектура'],
        ['seramik', 'ceramics', 'керамика'],
        ['tekstil', 'textile', 'текстиль'],
        ['arkeoloji', 'archaeology', 'археология'],
        ['oyunculuk', 'acting', 'актерское'],

        // Academic life
        ['bolum', 'program', 'programlar', 'department', 'departments',
            'programme', 'major', 'факультет', 'программа', 'специальность'],
        ['fakulte', 'faculty', 'faculties', 'факультет'],
        ['ders', 'dersler', 'course', 'courses', 'class', 'lecture', 'курс', 'занятия'],
        ['sinav', 'exam', 'exams', 'midterm', 'final', 'экзамен', 'экзамены'],
        ['takvim', 'calendar', 'календарь'],
        ['mezuniyet', 'graduation', 'выпуск'],
        ['staj', 'internship', 'практика', 'стажировка'],
        ['diploma', 'degree', 'диплом'],
        ['akademik', 'academic', 'академический'],
        ['hazirlik', 'preparatory', 'preparation', 'подготовка'],
        ['ingilizce', 'english', 'английский'],

        // Services and people
        ['danismanlik', 'psikolojik', 'counselling', 'counseling', 'psychological',
            'консультация', 'психолог'],
        ['saglik', 'revir', 'health', 'medical', 'здоровье', 'медицинский'],
        ['kariyer', 'career', 'карьера'],
        ['kulup', 'kulupler', 'club', 'clubs', 'society', 'клуб', 'клубы'],
        ['spor', 'sport', 'sports', 'gym', 'fitness', 'спорт'],
        ['etkinlik', 'etkinlikler', 'event', 'events', 'activity', 'мероприятие', 'мероприятия'],
        ['akademisyen', 'hoca', 'ogretim', 'lecturer', 'instructor', 'professor',
            'teacher', 'staff', 'преподаватель'],
        ['ogrenci', 'student', 'students', 'студент', 'студенты'],
        ['iletisim', 'contact', 'telefon', 'phone', 'email', 'eposta', 'контакт', 'связь'],
        ['adres', 'address', 'адрес'],
        ['uluslararasi', 'international', 'международный'],
        ['vize', 'visa', 'виза'],
        ['erasmus', 'exchange', 'обмен'],
        ['wifi', 'internet', 'интернет'],
        ['sertifika', 'certificate', 'сертификат'],
        ['burs basvurusu', 'scholarship application'],

        /*
         * Programme and department names.
         *
         * Every ARUCAD programme page is titled in Turkish, so an English or
         * Russian question about one shares no literal term with the page
         * that answers it, and the sentence model does not bridge the topic
         * vocabulary either (see the note at the top of this file). Measured:
         * "architecture programme" returned an acting-department news item
         * and a generic programme-development regulation, and never the
         * Mimarlık page.
         *
         * These are the faculty and programme names as ARUCAD publishes them.
         * Adding a programme means adding its row here, in all three
         * languages, at the same time as the page is crawled.
         */
        ['mimarlik', 'architecture', 'архитектура', 'архитектуры'],
        ['ic mimarlik', 'interior architecture', 'interior design',
            'интерьер', 'дизайн интерьера'],
        ['peyzaj', 'kentsel tasarim', 'landscape', 'landscape architecture',
            'urban design', 'ландшафт', 'городской дизайн'],
        ['grafik', 'grafik tasarim', 'graphic', 'graphic design',
            'график', 'графический дизайн'],
        ['endustriyel tasarim', 'industrial design', 'промышленный дизайн'],
        ['moda', 'moda tasarimi', 'fashion', 'fashion design', 'мода', 'дизайн одежды'],
        ['plastik sanatlar', 'guzel sanatlar', 'fine arts', 'plastic arts',
            'изобразительное искусство'],
        ['resim', 'painting', 'живопись'],
        ['heykel', 'sculpture', 'скульптура'],
        ['fotografcilik', 'fotograf', 'photography', 'фотография'],
        ['sinema', 'film', 'cinema', 'кино', 'кинематограф'],
        ['animasyon', 'animation', 'анимация'],
        ['oyunculuk', 'tiyatro', 'acting', 'theatre', 'theater', 'актерское', 'театр'],
        ['muzik', 'music', 'музыка'],
        ['gastronomi', 'mutfak sanatlari', 'gastronomy', 'culinary', 'гастрономия'],
        ['ic mekan', 'interior', 'интерьер'],
        ['iletisim', 'communication', 'коммуникация'],
        ['gorsel iletisim', 'visual communication', 'визуальная коммуникация'],
        ['yeni medya', 'new media', 'новые медиа'],
        ['dijital oyun', 'oyun tasarimi', 'game design', 'digital games',
            'дизайн игр', 'геймдизайн'],
        ['tasarim fakultesi', 'faculty of design', 'факультет дизайна'],
        ['sanat fakultesi', 'faculty of art', 'faculty of arts', 'факультет искусств'],
        ['iletisim fakultesi', 'faculty of communication', 'факультет коммуникаций'],
        ['lisansustu', 'yuksek lisans', 'postgraduate', 'graduate', 'masters',
            'master', 'магистратура'],
        ['doktora', 'phd', 'doctorate', 'докторантура', 'аспирантура'],
        ['lisans', 'undergraduate', 'bachelor', 'бакалавриат'],
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $index = null;

    /**
     * Expand a set of query terms with their equivalents in the other
     * languages.
     *
     * The originals are always kept and come first: expansion is an
     * addition to the query, never a replacement for it.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    public static function expand(array $terms): array
    {
        $index = self::index();
        $out = [];

        foreach ($terms as $term) {
            $folded = TextFold::fold($term);
            $out[$folded] = true;

            foreach ($index[$folded] ?? self::byStem($folded, $index) as $equivalent) {
                $out[$equivalent] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Equivalents for an inflected form the list does not spell out.
     *
     * Russian declines and Turkish agglutinates, so "стипендию" (accusative)
     * missed the row holding "стипендия" and an English-or-Russian question
     * lost its bridge to the Turkish page. Matched on the stem with trailing
     * vowels removed, five letters at least, so short words cannot collide.
     *
     * @param  array<string, list<string>>  $index
     * @return list<string>
     */
    private static function byStem(string $folded, array $index): array
    {
        $stem = rtrim($folded, 'aeiouıöüяаиеоуыэюё');
        if (mb_strlen($stem) < 5) {
            return [];
        }
        $out = [];
        foreach ($index as $key => $equivalents) {
            if (mb_strlen($key) >= 5 && rtrim($key, 'aeiouıöüяаиеоуыэюё') === $stem) {
                $out = array_merge($out, $equivalents);
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, list<string>> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (self::GROUPS as $group) {
            $folded = array_map(TextFold::fold(...), $group);
            foreach ($folded as $term) {
                // A term can sit in more than one group ("burs" is in both
                // the money row and the application row); merge rather
                // than let the later row win.
                $index[$term] = array_values(array_unique(
                    array_merge($index[$term] ?? [], $folded),
                ));
            }
        }

        return self::$index = $index;
    }
}
