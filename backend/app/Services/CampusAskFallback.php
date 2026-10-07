<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\Sport;
use App\Services\Knowledge\KnowledgeBase;
use App\Support\QueryLanguage;
use App\Support\SchemaColumnCache;
use App\Support\SupportIntent;
use App\Support\TextFold;

/**
 * Grounded campus answers when Groq is not configured or upstream fails.
 * Uses live catalog rows — not canned marketing copy.
 */
class CampusAskFallback
{
    public function __construct(private readonly KnowledgeBase $knowledge) {}

    /** The model could not be reached (or no provider may serve this question). */
    public const REASON_UNAVAILABLE = 'unavailable';

    /**
     * The model answered, but the answer failed verification against the
     * sources (AnswerGrounding) even after a corrective retry.
     */
    public const REASON_UNVERIFIED = 'unverified';

    public function answer(string $prompt, string $reason = self::REASON_UNAVAILABLE): string
    {
        $q = mb_strtolower(trim($prompt));
        if ($q === '') {
            return 'Bir yer, etkinlik, servis veya birim adı yaz, kampüs kayıtlarından bakayım.';
        }

        // Someone describing how they feel must never be answered with a
        // catalogue lookup. This runs first, and deliberately lives here in
        // the deterministic path: it has to work when the AI is unavailable,
        // which is exactly when a student was handed a list of buildings.
        $support = app(SupportIntent::class);
        if ($support->matches($prompt)) {
            $message = $support->message($prompt);
            if ($message !== null) {
                return $message;
            }
        }

        foreach (Place::query()->get() as $place) {
            if ($place->name !== '' && mb_stripos($q, mb_strtolower($place->name)) !== false) {
                $bits = array_filter([$place->category, $place->street, $place->distance]);

                return $place->name.' kampüste. '.
                    ($place->description ? $place->description.' ' : '').
                    implode(' · ', $bits);
            }
        }

        // Real fix: Schema::hasColumn() used to re-query the database's own
        // schema metadata on every fallback call. SchemaColumnCache answers
        // it once per worker process instead.
        $events = Event::query();
        if (SchemaColumnCache::hasColumn('events', 'draft')) {
            $events->where('draft', false);
        }
        if (SchemaColumnCache::hasColumn('events', 'workflow_status')) {
            $events->where('workflow_status', 'published');
        }
        $catalog = $events->limit(40)->get();
        foreach ($catalog as $event) {
            if ($event->title !== '' && mb_stripos($q, mb_strtolower($event->title)) !== false) {
                return $event->title.' · '.$event->place_name.', saat '.$event->time.
                    ($event->attendees ? ' · '.$event->attendees.' katılımcı' : '').'.';
            }
        }
        if (str_contains($q, 'etkinlik') && $catalog->isNotEmpty()) {
            $listed = $catalog->take(3)->map(fn ($e) => $e->title.' · '.$e->place_name)->implode('; ');

            return 'Yakın etkinlikler: '.$listed.'.';
        }

        foreach (ServiceItem::all() as $service) {
            if (mb_stripos($q, mb_strtolower($service->title)) !== false
                || ($service->category && mb_stripos($q, mb_strtolower($service->category)) !== false)) {
                $where = collect([$service->building, $service->floor, $service->room])->filter()->implode(', ');

                return $service->title.': '.$service->description.
                    ($where !== '' ? ' Konum: '.$where.'.' : '').
                    ($service->contact ? ' İletişim: '.$service->contact : '');
            }
        }

        foreach (Club::all() as $club) {
            if (mb_stripos($q, mb_strtolower($club->name)) !== false) {
                return $club->name.' ('.$club->category.'): '.$club->description;
            }
        }

        foreach (Sport::all() as $sport) {
            if (mb_stripos($q, mb_strtolower($sport->name)) !== false) {
                return $sport->name.': '.$sport->facility.' kullanılıyor. Keşfet içinden ön başvuru yapabilirsin.';
            }
        }

        if (str_contains($q, 'yemek') || str_contains($q, 'menü') || str_contains($q, 'menu') || str_contains($q, 'garden')) {
            $venue = FoodVenue::with('dailyMenus')->first();
            if ($venue) {
                $menu = $venue->dailyMenus->first();
                $items = $menu && is_array($menu->items) ? implode(', ', $menu->items) : 'bugünün menüsü henüz girilmedi';

                return $venue->name.': '.$items;
            }
        }

        if (str_contains($q, 'servis') || str_contains($q, 'otobüs') || str_contains($q, 'shuttle')) {
            return 'Kampüs servis saatleri Keşfet ve haritadaki Servis bölümünde. Lefkoşa, Alsancak, Çatalköy ve atölye hatları var.';
        }

        if (str_contains($q, 'merhaba') || str_contains($q, 'selam') || str_contains($q, 'hello')) {
            return 'Merhaba, ben AICAD. Yer, etkinlik, kulüp, spor veya birim sorabilirsin.';
        }

        // Knowledge-only mode used to ignore the crawled website entirely.
        // That produced the particularly confusing "I could not find it"
        // response while the same answer included a link to a page containing
        // the requested dates. Return the relevant official excerpt and URL
        // when the LLM is unavailable; it is deterministic and fully sourced.
        $web = $this->knowledge->relevant($prompt, 1);
        if ($web !== [] && $this->addresses($prompt, $web[0])) {
            $hit = $web[0];
            $language = QueryLanguage::detect($prompt);
            $lead = match ($language) {
                'en' => 'I found this information on the official ARUCAD page:',
                'ru' => 'Я нашёл эту информацию на официальной странице ARUCAD:',
                default => 'Bu bilgiyi resmi ARUCAD sayfasında buldum:',
            };
            $source = match ($language) {
                'en' => 'Source',
                'ru' => 'Источник',
                default => 'Kaynak',
            };

            return $lead."\n".trim((string) $hit['snippet'])."\n{$source}: ".$hit['url'];
        }

        // Deliberately does NOT list building names any more. Answering an
        // unmatched question with "try: The Garden, Age of Bronze…" assumed
        // every question is a location lookup, which read as absurd when the
        // student was not asking about a place at all.
        return $this->noMatch(QueryLanguage::detect($prompt), $reason);
    }

    /**
     * The last resort, worded for WHY there is no model answer.
     *
     * It always said "I cannot reach the AI", including when the AI had
     * answered and the answer was withheld because it stated a date or a
     * name no source contained. That told the student the service was down
     * when it was in fact protecting them from a wrong answer.
     */
    private function noMatch(?string $language, string $reason): string
    {
        if ($reason === self::REASON_UNVERIFIED) {
            return match ($language) {
                'en' => "I could not confirm an answer to this in ARUCAD's verified sources, so I am not "
                    .'going to guess. The relevant office (Student Affairs, or the unit concerned) can give you a definite answer.',
                'ru' => 'Я не смог подтвердить ответ на этот вопрос по проверенным источникам ARUCAD, поэтому '
                    .'не буду гадать. Точный ответ даст соответствующее подразделение (например, отдел по работе со студентами).',
                default => "Bu soruya ARUCAD'ın doğrulanmış kaynaklarında teyit edebildiğim bir cevap bulamadım; "
                    .'tahmin yürütmek istemiyorum. Kesin bilgi için öğrenci işleri ya da ilgili birim sana yardımcı olur.',
            };
        }

        return match ($language) {
            'en' => 'I cannot reach the AI right now, so I can only answer from ARUCAD records, and I could not '
                .'match this question there. Ask about a campus unit, service, club, event or place; Student Affairs '
                .'and the counselling units can also help you directly.',
            'ru' => 'Сейчас ИИ недоступен, поэтому я отвечаю только по записям ARUCAD и не нашёл там ответа на этот '
                .'вопрос. Спросите о подразделении, услуге, клубе, мероприятии или месте кампуса; также помогут отдел '
                .'по работе со студентами и консультационные службы.',
            default => 'Şu an yapay zekaya ulaşamadığım için yalnızca ARUCAD kayıtlarından '
                .'cevap verebiliyorum ve bu soruyu orada eşleştiremedim. Kampüsteki bir '
                .'birim, hizmet, kulüp, etkinlik veya yer sorarsan bakabilirim; '
                .'öğrenci işleri ve danışmanlık birimleri de sana doğrudan yardımcı olur.',
        };
    }

    /**
     * Does this page actually address the question, or merely rank first?
     *
     * Retrieval always returns something. Quoting the top result regardless
     * produced answers that were confidently beside the point: "tatil ne
     * zaman" was answered with a news item about a children's event, "ielts
     * gerekli mi" with the ethics regulation, and "kkts nedir" with a
     * fragment of the library regulation — each introduced by "Bu bilgiyi
     * resmi ARUCAD sayfasında buldum", which presents noise as a finding.
     *
     * This is the degraded path, taken when the model is unavailable, so
     * there is nothing downstream to catch it. Requiring that the page at
     * least contains a word the student used is a low bar, and it is the
     * difference between an unhelpful answer and a misleading one.
     */
    private function addresses(string $prompt, array $hit): bool
    {
        $haystack = TextFold::fold(((string) ($hit['title'] ?? '')).' '.((string) ($hit['snippet'] ?? '')));
        if ($haystack === '') {
            return false;
        }

        /*
         * Function words are not subjects.
         *
         * "için" is exactly four characters, so it passed the length floor
         * and matched almost any page: an ARUCAD sports page was accepted as
         * an answer about Erasmus because both contained "için".
         */
        $noise = [
            'icin', 'ile', 'veya', 'ama', 'fakat', 'yani', 'gibi', 'daha', 'cok',
            'olan', 'olarak', 'nasil', 'nedir', 'hangi', 'kadar', 'sonra', 'once',
            'bana', 'benim', 'sizin', 'bunu', 'buna', 'sunu', 'orada', 'burada',
            'the', 'and', 'for', 'with', 'from', 'that', 'this', 'what', 'how',
            'does', 'have', 'about', 'there', 'here', 'your', 'can', 'will',
        ];

        $matched = false;
        foreach (preg_split('/[^\p{L}\p{N}]+/u', TextFold::fold($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // Short words are grammar, and matching on them would let any
            // page through.
            if (mb_strlen($word) < 4 || in_array($word, $noise, true)) {
                continue;
            }
            /*
             * The rough stem, so Turkish suffixes do not hide a real hit.
             *
             * Three characters, not two: "dersler" has to reach "ders" for
             * "dersler ne zaman başlıyor" to match a page headed "Ders
             * Başlangıcı", and trimming only two left "dersl", which matches
             * nothing. Turkish agglutinates freely, so the stem has to be
             * shorter than feels natural.
             */
            $stem = mb_substr($word, 0, max(4, mb_strlen($word) - 3));
            if (str_contains($haystack, $stem)) {
                $matched = true;
                break;
            }
        }

        return $matched;
    }
}
