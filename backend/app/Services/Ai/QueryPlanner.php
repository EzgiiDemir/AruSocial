<?php

namespace App\Services\Ai;

use App\Support\PhraseMatcher;
use App\Support\TextFold;

/**
 * Decides which campus domains a question is about, and therefore which
 * live database tools AruverseAgent runs for it.
 *
 * This replaces a loop that scored each tool by `str_contains` in BOTH
 * directions between raw lowercase words and unfolded keywords. Measured on
 * real questions, that loop:
 *   - routed "öğrenci işlerine nasıl giderim" to CAREER, because the career
 *     keyword `iş` is a substring of "işlerine";
 *   - routed "kaç kişi" to FOOD (`aç` ⊂ "kaç") and "katılırım" to PLACES
 *     (`kat`);
 *   - never matched a question typed without Turkish letters, because the
 *     keywords kept their diacritics ("ogrenci" ≠ "öğrenci");
 *   - and, when nothing matched, silently ran places + events + services,
 *     so a miss looked like an answer.
 *
 * Now both sides are folded, a keyword must start at a word boundary
 * (PhraseMatcher), entities named in the question vote for their own domain,
 * and a fallback is reported as a fallback in the trace.
 *
 * Deterministic and cheap on purpose: it runs on every question, before
 * the model, and it must not cost a model call.
 */
final class QueryPlanner
{
    /**
     * Words that signal each domain, FOLDED (see TextFold) and in all three
     * app languages. A keyword may carry a short ending in the question
     * ("kulupler", "sporu", "kariyerle"); write the dictionary form.
     *
     * A leading `=` means whole word only, no ending: for short words that
     * are also something else with a Turkish suffix on ("ring" → "ringa
     * balığı", "term" → "termos").
     *
     * Turkish softens a final p/ç/t/k before a vowel ("kulüp" → "kulübü"),
     * so the softened stem is listed too.
     *
     * Adding a word here changes which database rows reach the model, so a
     * new word should come with a routing test.
     *
     * @var array<string, list<string>>
     */
    public const LEXICON = [
        'places' => [
            'yer', 'yerler', 'yerleri', 'bina', 'binasi', 'nerede', 'nerde', 'neresi', 'konum',
            'adres', 'kampus', 'place', 'places', 'where', 'building', 'location', 'address',
            'где', 'здание', 'адрес', 'корпус', 'находится',
        ],
        'navigation' => [
            'nasil giderim', 'nasil gidilir', 'nasil gidebilirim', 'nasil ulasirim', 'yol tarifi',
            'gotur', 'goturur musun', 'beni gotur', 'oraya', 'rota', 'directions', 'navigate',
            'route', 'how do i get', 'how can i get', 'take me', 'get to',
            'как пройти', 'как добраться', 'маршрут', 'проведи',
        ],
        'events' => [
            'etkinlik', 'etkinlikler', 'event', 'events', 'aktivite', 'activity', 'bugun', 'today',
            'yarin', 'tomorrow', 'bu hafta', 'this week', 'bu aksam', 'aksam', 'tonight', 'bu gece',
            'hafta sonu', 'weekend', 'ne var', 'neler var', 'konser', 'concert', 'seminer', 'seminar',
            'workshop', 'atolye', 'soylesi', 'happening',
            'мероприятие', 'мероприятия', 'событие', 'сегодня', 'завтра', 'вечером', 'концерт',
        ],
        'clubs' => [
            'kulup', 'kulub', 'kulupler', 'club', 'clubs', 'topluluk', 'society',
            'клуб', 'кружок', 'сообщество',
        ],
        'sports' => [
            'spor', 'sport', 'sports', 'basketbol', 'basket', 'futbol', 'voleybol', 'tenis', 'yuzme',
            'fitness', 'gym', 'spor salonu', 'antrenman', 'workout', 'basketball', 'football', 'soccer',
            'volleyball', 'swimming', 'спорт', 'тренировка', 'спортзал', 'баскетбол', 'футбол',
        ],
        'food' => [
            'yemek', 'yemekhane', 'food', 'kafeterya', 'kafe', 'cafe', 'cafeteria', 'kantin', 'menu',
            'kahve', 'coffee', 'ogle yemegi', 'lunch', 'dinner', 'breakfast', 'kahvalti', '=eat',
            'yiyebilirim', 'yenir', 'restoran', 'restaurant',
            'еда', 'столовая', 'кафе', 'меню', 'обед', 'поесть', 'ужин', 'завтрак',
        ],
        'services' => [
            'hizmet', 'ofis', 'office', 'ogrenci isleri', 'student affairs', 'kutuphane', 'library',
            'saglik', 'health', 'revir', 'registrar', 'bilgi islem', 'uluslararasi ofis',
            'international office', 'yurt', 'dorm', 'dormitory', 'kayip esya', 'lost and found',
            'услуга', 'офис', 'служба', 'библиотека', 'общежитие',
        ],
        'shuttle' => [
            'servis', 'shuttle', '=ring', 'ring servisi', 'otobus', '=bus', 'ulasim', 'transport', 'kalkis', 'sefer',
            'departure', 'автобус', 'расписание', 'транспорт', 'шаттл',
        ],
        'directory' => [
            'oda', 'odasi', 'ofisi', 'kimin', 'hangi bina', 'hangi oda', 'nerede oturuyor', 'rehber',
            'directory', 'room', 'whose office', 'кабинет', 'справочник',
        ],
        'staff' => [
            'hoca', 'hocam', 'hocanin', 'hocalar', 'akademisyen', 'ogretim uyesi', 'ogretmen', 'staff',
            'faculty member', 'professor', 'lecturer', 'bolum baskani', 'dekan', 'dean', 'e posta',
            'eposta', 'email', 'mail adresi', 'iletisim', 'contact',
            'преподаватель', 'профессор', 'декан', 'почта',
        ],
        'calendar' => [
            'akademik takvim', 'akademik yil', 'donem', 'yariyil', 'semester', '=term', 'takvim',
            'sinav', '=final', '=finals', 'vize', 'ders kaydi', 'tatil', 'holiday', 'academic calendar', 'exam',
            // The same phrasings config/knowledge.php maps to the academic
            // calendar page; routed here so the live term dates are read too.
            'dersler ne zaman', 'ders ne zaman', 'ders baslangici', 'okul ne zaman', 'classes start',
            'semester start', 'начало занятий',
            'семестр', 'экзамен', 'каникулы', 'календарь',
        ],
        'consultation' => [
            'danisman', 'danismanlik', 'rehberlik', 'consultation', 'advising', 'advisor', 'gorusme',
            'randevu', 'appointment', 'консультация', 'запись',
        ],
        'support' => [
            'psikolojik', 'psikolog', 'depresyon', 'kaygi', 'anksiyete', 'stres', 'yalniz', 'mutsuz',
            'terapi', 'psychological', 'counselling', 'counseling', 'therapy', 'anxiety', 'depressed',
            'stressed', 'lonely', 'wellbeing', 'engelli', 'erisilebilir', 'accessibility',
            'психолог', 'стресс', 'депрессия',
        ],
        'career' => [
            'kariyer', 'career', 'is ilani', 'is ilanlari', 'is firsati', 'staj', 'stajyer', 'job',
            'jobs', 'internship', 'mezun', 'graduate', 'opportunity', 'ilan', 'ilanlar', 'cv',
            // Not bare "работа": it is work in general ("часы работы" is
            // opening hours), not a job opening.
            'ozgecmis', 'карьера', 'найти работу', 'работа для студентов', 'стажировка', 'вакансия',
            'выпускник',
        ],
        'social' => [
            'instagram', 'sosyal medya', 'social media', 'facebook', 'twitter', 'linkedin', 'tiktok',
            'youtube', 'hesabi', 'инстаграм', 'соцсети',
        ],
        'account' => [
            'basvurum', 'basvurularim', 'randevum', 'randevularim', 'my application', 'my appointment',
            'profilim', 'моя заявка', 'моя запись',
        ],
        'knowledge' => [
            'bolum', 'bolumu', 'fakulte', 'program', 'burs', 'ucret', 'taban puan', 'kayit', 'admission',
            'department', 'faculty', 'scholarship', 'tuition', 'yonetmelik', 'regulation',
            'egitim dili', 'ogretim dili', 'language of instruction', 'язык обучения',
            'факультет', 'стипендия', 'поступление',
        ],
    ];

    /**
     * Domain → AruverseAgent tools it needs. Domains with no tool still
     * appear in the plan: `account` is served by PersonalContext and
     * `knowledge` by the knowledge base, which always runs.
     *
     * @var array<string, list<string>>
     */
    public const TOOLS_FOR_DOMAIN = [
        'places' => ['places'],
        'navigation' => ['places'],
        'events' => ['events'],
        'clubs' => ['clubs'],
        'sports' => ['sports'],
        'food' => ['food'],
        'services' => ['services'],
        'shuttle' => ['shuttle'],
        'directory' => ['directory'],
        'staff' => ['staff'],
        'calendar' => ['calendar'],
        'consultation' => ['consultation'],
        'support' => ['support'],
        'career' => ['career'],
        // Club rows carry the description an operator wrote, which is where
        // a club's contact and social handles are stated today.
        'social' => ['clubs'],
        // Programme facts (language of instruction, duration) extracted with
        // provenance from the admissions sites — see KnowledgeFactExtractor.
        'programs' => ['programs'],
        'account' => [],
        'knowledge' => [],
    ];

    /** Weight of a keyword hit, a typo-tolerant hit, and a named entity. */
    private const WEIGHT_KEYWORD = 1.0;

    private const WEIGHT_PHRASE = 1.5;

    private const WEIGHT_FUZZY = 0.5;

    private const WEIGHT_ENTITY = 2.0;

    /**
     * Typo tolerance only for keywords this long, against question words of
     * at least FUZZY_MIN_WORD letters. At six letters one edit is already
     * another word ("servis" / "servet"); at seven it rarely is.
     */
    private const FUZZY_MIN_KEYWORD = 7;

    private const FUZZY_MIN_WORD = 6;

    public function __construct(
        private readonly EntityResolver $entities,
        private readonly QueryConcepts $concepts,
    ) {}

    /**
     * @return array{
     *     normalized: string,
     *     domains: array<string, array{score: float, reasons: list<string>}>,
     *     entities: list<array<string, mixed>>,
     *     concepts: list<array{concept: string, phrase: string}>,
     *     tools: list<string>,
     *     fallback: bool,
     *     timings: array{keywords_ms: float, entities_ms: float},
     * }
     */
    public function plan(string $query): array
    {
        $started = microtime(true);
        $text = TextFold::fold($query);
        $words = PhraseMatcher::words($text);
        $domains = [];

        foreach (self::LEXICON as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                $exact = str_starts_with($keyword, '=');
                $keyword = ltrim($keyword, '=');
                if (PhraseMatcher::position($text, $keyword, $exact ? PHP_INT_MAX : 4) !== null) {
                    $weight = str_contains($keyword, ' ') ? self::WEIGHT_PHRASE : self::WEIGHT_KEYWORD;
                    $this->vote($domains, $domain, $weight, 'keyword:'.$keyword);
                } elseif (! $exact && ! str_contains($keyword, ' ') && mb_strlen($keyword) >= self::FUZZY_MIN_KEYWORD
                    && $this->fuzzyWord($words, $keyword)) {
                    $this->vote($domains, $domain, self::WEIGHT_FUZZY, 'typo:'.$keyword);
                }
            }
        }

        // Query concepts (admin → AICAD → Query concepts): a wording that
        // names a concept votes for the concept's domains.
        $concepts = $this->concepts->match($query);
        foreach ($concepts as $concept) {
            foreach ($concept['domains'] as $domain) {
                $this->vote($domains, $domain, self::WEIGHT_ENTITY, 'concept:'.$concept['concept']);
            }
        }

        $keywordsMs = (microtime(true) - $started) * 1000;
        $entitiesStarted = microtime(true);
        $entities = $this->entities->resolve($query);
        $entitiesMs = (microtime(true) - $entitiesStarted) * 1000;
        foreach ($entities as $entity) {
            $domain = EntityResolver::DOMAIN_FOR_TYPE[$entity['type']] ?? null;
            if ($domain !== null) {
                $this->vote($domains, $domain, self::WEIGHT_ENTITY * $entity['score'],
                    'entity:'.$entity['type'].':'.$entity['id']);
            }
        }

        uasort($domains, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        $tools = [];
        foreach (array_keys($domains) as $domain) {
            foreach (self::TOOLS_FOR_DOMAIN[$domain] ?? [] as $tool) {
                $tools[$tool] = true;
            }
        }
        $tools = array_slice(array_keys($tools), 0, max(1, (int) config('ai.routing.max_tools', 4)));

        // Fallback only when nothing at all was recognised. A question about
        // the student's own records (`account`) or general knowledge has a
        // domain and simply needs no campus table.
        $fallback = $domains === [];
        if ($fallback) {
            $tools = array_values((array) config('ai.routing.fallback_tools', ['places', 'events', 'services']));
        }

        return [
            'normalized' => $text,
            'domains' => $domains,
            'entities' => $entities,
            'concepts' => array_map(fn (array $c) => ['concept' => $c['concept'], 'phrase' => $c['phrase']], $concepts),
            'tools' => $tools,
            'fallback' => $fallback,
            'timings' => ['keywords_ms' => round($keywordsMs, 1), 'entities_ms' => round($entitiesMs, 1)],
        ];
    }

    /** @param array<string, array{score: float, reasons: list<string>}> $domains */
    private function vote(array &$domains, string $domain, float $weight, string $reason): void
    {
        $domains[$domain] ??= ['score' => 0.0, 'reasons' => []];
        $domains[$domain]['score'] += $weight;
        $domains[$domain]['reasons'][] = $reason;
    }

    /** @param list<string> $words */
    private function fuzzyWord(array $words, string $keyword): bool
    {
        foreach ($words as $word) {
            if (mb_strlen($word) >= self::FUZZY_MIN_WORD && PhraseMatcher::fuzzy($word, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
