<?php

namespace App\Support;

/**
 * Which language a user wrote their question in.
 *
 * Used for three things: ranking knowledge documents of the same language
 * higher, telling the model explicitly which language to answer in, and
 * choosing the wording of a deterministic answer. A prompt rule alone is not
 * reliable: the AICAD system prompt is written in Turkish, and for
 * conversational questions (where no retrieved source anchors the language)
 * the model drifts into Turkish even for an English question. Stating the
 * detected language as a per-request instruction fixes that.
 *
 * WHY THIS IS NOT JUST A FUNCTION-WORD LIST
 *
 * It was, and it failed on exactly the queries students type most. A short
 * noun phrase contains no function words at all:
 *
 *     "library opening hours"   scored 0 English, 0 Turkish → null
 *     "academic calendar"       scored 0 English, 0 Turkish → null
 *     "architecture programme"  scored 0 English, 0 Turkish → null
 *
 * and null meant AskPromptBuilder added no language directive, so a Turkish
 * system prompt answered an English question in Turkish. Measured: three of
 * the four language failures in `ask:eval` were this, not model drift.
 *
 * So detection now combines four independent signals, strongest first. They
 * are summed rather than short-circuited, because any one of them is wrong
 * on some input and the sum is wrong on far less:
 *
 *   1. SCRIPT      Cyrillic is decisive — no other supported language uses it.
 *   2. ORTHOGRAPHY Turkish-only letters (çğıöşü); and w/x/q, which are not in
 *                  the Turkish alphabet and so only appear in loanwords.
 *   3. LEXICON     Per-language word lists, matched folded so "kutuphane"
 *                  counts as "kütüphane". Deliberately heavy on the campus
 *                  nouns a university is actually asked about, because that
 *                  is what short queries are made of.
 *   4. MORPHOLOGY  Turkish agglutinates. A word ending in -ler/-lar, -leri,
 *                  -nın, -de/-da, -dir is Turkish with near-certainty and
 *                  needs no dictionary entry, which is what makes this
 *                  degrade gracefully on vocabulary nobody listed.
 *
 * Adding vocabulary is the normal way to improve this. Add to the language
 * whose list is missing the word, never to both.
 */
class QueryLanguage
{
    /**
     * Common Turkish words, question particles and campus nouns.
     *
     * Stored unfolded for readability; folded once at match time, so an entry
     * may be written either way and "öğrenci" also matches "ogrenci".
     */
    private const TR_WORDS = [
        // Function words and question particles
        've', 'için', 'ile', 'bir', 'bu', 'şu', 'çok', 'daha', 'var', 'yok',
        'ne', 'nedir', 'nasıl', 'nerede', 'nereye', 'neden', 'kaç', 'kaçta',
        'hangi', 'kim', 'kimdir', 'ne zaman', 'mı', 'mi', 'mu', 'mü',
        'ben', 'bana', 'benim', 'sen', 'siz', 'bizim', 'onlar',
        'istiyorum', 'olur', 'lazım', 'gerek', 'merhaba', 'selam', 'teşekkür',
        'lütfen', 'acaba', 'peki', 'ama', 'veya', 'göre', 'kadar', 'sonra',
        'önce', 'şimdi', 'bugün', 'yarın', 'dün', 'hakkında', 'içinde',

        // Campus nouns — what a short Turkish query is actually made of
        'okul', 'üniversite', 'kampüs', 'fakülte', 'bölüm', 'bölümü',
        'ders', 'dersler', 'dersim', 'derslerim', 'sınav', 'sınavlar',
        'öğrenci', 'öğrenciler', 'hoca', 'akademisyen', 'öğretim',
        'kütüphane', 'yurt', 'yemekhane', 'kantin', 'kafeterya',
        'laboratuvar', 'atölye', 'otopark', 'revir',
        'ücret', 'ücretler', 'harç', 'burs', 'burslar', 'indirim', 'ödeme',
        'taksit', 'başvuru', 'kayıt', 'kontenjan', 'puan', 'taban',
        'transkript', 'denklik', 'yatay', 'geçiş',
        'takvim', 'saat', 'saatleri', 'mezuniyet', 'staj', 'diploma',
        'akademik', 'hazırlık', 'ingilizce', 'dönem', 'yıl', 'not', 'notlar',
        'danışman', 'danışmanım', 'danışmanlık', 'psikolojik', 'sağlık',
        'kariyer', 'kulüp', 'kulüpler', 'spor', 'etkinlik', 'etkinlikler',
        'ulaşım', 'servis', 'otobüs', 'adres', 'iletişim', 'telefon',
        'randevu', 'randevum', 'duyuru', 'yönetmelik', 'yönerge', 'belge',
        'program', 'programlar', 'mimarlık', 'tasarım', 'sanat',
        'uluslararası', 'vize', 'konaklama', 'giriş', 'çıkış', 'açık', 'kapalı',
    ];

    /**
     * Common English words, including pronouns, everyday verbs and the
     * university nouns that make up a short query.
     */
    private const EN_WORDS = [
        // Function words and everyday verbs
        'the', 'and', 'for', 'is', 'are', 'was', 'were', 'what', 'how',
        'where', 'why', 'which', 'who', 'when', 'i', 'you', 'me', 'my',
        'your', 'we', 'it', 'a', 'an', 'to', 'of', 'in', 'on', 'at', 'do',
        'does', 'did', 'can', 'could', 'should', 'would', 'about', 'want',
        'need', 'feel', 'any', 'advice', 'please', 'thanks', 'thank', 'hello',
        'hi', 'there', 'have', 'has', 'get', 'give', 'tell', 'show', 'find',
        'with', 'from', 'this', 'that', 'some', 'many', 'much', 'more',

        // Campus nouns — the English half of the same short queries
        'university', 'student', 'students', 'course', 'courses', 'campus',
        'exam', 'exams', 'program', 'programme', 'programmes', 'programs',
        'department', 'departments', 'faculty', 'faculties', 'school',
        'library', 'dormitory', 'dorm', 'accommodation', 'housing',
        'canteen', 'cafeteria', 'dining', 'laboratory', 'lab', 'workshop',
        'studio', 'parking', 'clinic',
        'tuition', 'fee', 'fees', 'cost', 'price', 'payment', 'instalment',
        'scholarship', 'scholarships', 'discount', 'funding',
        'application', 'admission', 'apply', 'enrolment', 'enrollment',
        'registration', 'register', 'quota', 'score', 'transcript',
        'calendar', 'schedule', 'timetable', 'semester', 'term', 'deadline',
        'graduation', 'internship', 'diploma', 'degree', 'academic',
        'preparatory', 'english', 'grade', 'grades', 'credit',
        'advisor', 'adviser', 'counselling', 'counseling', 'psychological',
        'health', 'medical', 'career', 'club', 'clubs', 'society', 'sport',
        'sports', 'gym', 'event', 'events', 'lecturer', 'instructor',
        'professor', 'teacher', 'staff', 'office', 'contact', 'address',
        'phone', 'email', 'transport', 'transportation', 'shuttle', 'bus',
        'international', 'visa', 'exchange', 'opening', 'hours', 'closing',
        'open', 'closed', 'map', 'architecture', 'design', 'art',
        'requirement', 'requirements', 'regulation', 'announcement',
        'appointment', 'today', 'tomorrow', 'week', 'information',
    ];

    /**
     * Turkish inflectional endings.
     *
     * The point of these is the vocabulary nobody listed: "fotoğrafçılık
     * bölümünde" is unmistakably Turkish to a reader and invisible to a
     * dictionary. Each is checked against a whole word and only on words long
     * enough that the ending is an ending rather than the word itself.
     */
    private const TR_SUFFIXES = [
        'ler', 'lar', 'leri', 'ları', 'lerin', 'ların', 'lerde', 'larda',
        'lerden', 'lardan', 'lere', 'lara',
        'nın', 'nin', 'nun', 'nün', 'ın', 'in', 'un', 'ün',
        'da', 'de', 'ta', 'te', 'dan', 'den', 'tan', 'ten',
        'dır', 'dir', 'dur', 'dür', 'tır', 'tir',
        'mız', 'miz', 'muz', 'müz', 'ımız', 'imiz',
        'sı', 'si', 'su', 'sü', 'ısı', 'isi',
        'lık', 'lik', 'luk', 'lük', 'cı', 'ci', 'cu', 'cü',
        'mak', 'mek', 'yor', 'acak', 'ecek', 'miş', 'mış',
    ];

    /** The shortest word an ending may be stripped from and still mean anything. */
    private const MIN_SUFFIX_WORD = 5;

    /** Rough guess: 'tr', 'en', 'ru', or null when genuinely unsure. */
    public static function detect(string $query): ?string
    {
        // 1. SCRIPT. Cyrillic is decisive — no other supported language uses it.
        if (preg_match('/\p{Cyrillic}/u', $query)) {
            return 'ru';
        }

        $words = self::words($query);
        if ($words === []) {
            return null;
        }

        $tr = 0;
        $en = 0;

        // 2. LEXICON.
        $trLexicon = self::lexicon(self::TR_WORDS);
        $enLexicon = self::lexicon(self::EN_WORDS);
        foreach ($words as $word) {
            if (isset($trLexicon[$word])) {
                $tr++;
            }
            if (isset($enLexicon[$word])) {
                $en++;
            }
        }

        // Multi-word entries ("ne zaman") cannot be matched token by token.
        $padded = ' '.implode(' ', $words).' ';
        foreach (self::TR_WORDS as $entry) {
            if (str_contains($entry, ' ') && str_contains($padded, ' '.TextFold::fold($entry).' ')) {
                $tr++;
            }
        }
        foreach (self::EN_WORDS as $entry) {
            if (str_contains($entry, ' ') && str_contains($padded, ' '.TextFold::fold($entry).' ')) {
                $en++;
            }
        }

        // 3. ORTHOGRAPHY. Turkish-only letters are a strong signal on their
        // own; w/x/q are absent from the Turkish alphabet, so a word carrying
        // one is a loanword at best and English far more often.
        if (preg_match('/[çğıöşü]/u', $query)) {
            $tr += 2;
        }
        if (preg_match('/[wxq]/u', mb_strtolower($query, 'UTF-8'))) {
            $en += 1;
        }

        // 4. MORPHOLOGY. Capped, so one long agglutinated word cannot outvote
        // a sentence of English.
        $tr += min(2, self::turkishSuffixHits($words));

        if ($tr === $en) {
            return null;
        }

        return $tr > $en ? 'tr' : 'en';
    }

    /**
     * Folded word tokens. Punctuation would otherwise weld a word to its
     * neighbour ("advice?" never matching "advice").
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $folded = TextFold::fold($text);

        return preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * A folded lookup set, built once per word list.
     *
     * @param  list<string>  $entries
     * @return array<string, true>
     */
    private static function lexicon(array $entries): array
    {
        /** @var array<string, array<string, true>> $cache */
        static $cache = [];
        $key = md5(serialize($entries));
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $set = [];
        foreach ($entries as $entry) {
            if (! str_contains($entry, ' ')) {
                $set[TextFold::fold($entry)] = true;
            }
        }

        return $cache[$key] = $set;
    }

    /**
     * How many words carry a Turkish inflectional ending.
     *
     * @param  list<string>  $words
     */
    private static function turkishSuffixHits(array $words): int
    {
        /** @var list<string>|null $folded */
        static $folded = null;
        if ($folded === null) {
            $folded = array_map(
                static fn (string $s): string => TextFold::fold($s),
                self::TR_SUFFIXES,
            );
            // Longest first, so "lerin" is not counted merely as "in".
            usort($folded, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        }

        $hits = 0;
        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_SUFFIX_WORD) {
                continue;
            }
            foreach ($folded as $suffix) {
                if (str_ends_with($word, $suffix)) {
                    $hits++;
                    break;   // one ending per word
                }
            }
        }

        return $hits;
    }

    /** The language's own name, for use inside a prompt instruction. */
    public static function label(?string $code): ?string
    {
        return match ($code) {
            'tr' => 'Türkçe',
            'en' => 'English',
            'ru' => 'Русский',
            default => null,
        };
    }
}
