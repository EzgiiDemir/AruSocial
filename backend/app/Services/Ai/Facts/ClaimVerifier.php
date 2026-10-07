<?php

namespace App\Services\Ai\Facts;

use App\Services\Ai\AppNavigation;
use App\Services\Ai\Planning\OpeningHours;
use App\Support\TextFold;

/**
 * Claim-level verification of an answer generated from SupportedFacts.
 * Deterministic: no second model judges the first.
 *
 * Every sentence is classified:
 *
 *  SUPPORTED_BY_FACT    carries a fact's value and nothing unsupported
 *  PRESENTATION_ONLY    offers, transitions, politeness, a reported gap,
 *                       advice to ask an office — no domain information
 *  UNSUPPORTED_FACTUAL  carries checkable content that no fact supports:
 *                       a time, walking figure, language, document, address,
 *                       floor/room, "open/closed now" contradicting the
 *                       is_open_now fact, a speculative generalisation
 *                       ("club rooms are usually in faculty buildings"), an
 *                       absolute negative ("ARUCAD does not offer X"), or one
 *                       of the factual categories that is never "uncertain":
 *                       a calendar date, e-mail or phone number, a titled
 *                       person, a programme's length, an admission score, or
 *                       an app screen that is not in AppNavigation
 *  UNCERTAIN            a declarative sentence about a campus subject with no
 *                       fact and nothing checkable — recorded, not deleted:
 *                       guessing at semantics here would delete good answers
 *
 * Links, e-mails, figures, dates, names, buildings and exam acronyms are
 * verified by AnswerGrounding against the fact-bounded prompt, which this
 * complements.
 */
final class ClaimVerifier
{
    public const SUPPORTED_BY_FACT = 'SUPPORTED_BY_FACT';

    public const PRESENTATION_ONLY = 'PRESENTATION_ONLY';

    public const UNSUPPORTED_FACTUAL = 'UNSUPPORTED_FACTUAL';

    public const UNCERTAIN = 'UNCERTAIN';

    // Not the day and month of a "05.10.2026" date.
    private const TIME = '/\b(\d{1,2})[:.](\d{2})\b(?![.\/]\d)/u';

    /** A calendar date, folded: "5 ekim", "october 5", "5 октября", "2026-10-05", "05.10.2026". */
    private const DATE = '/(?<![\p{L}\p{N}])(?:\d{1,2}\s+(?:ocak|subat|mart|nisan|mayis|haziran|temmuz|agustos|eylul|ekim|kasim|aralik|january|february|march|april|may|june|july|august|september|october|november|december|январ\w*|феврал\w*|марта?|апрел\w*|ма[яй]|июн\w*|июл\w*|августа?|сентябр\w*|октябр\w*|ноябр\w*|декабр\w*)(?![\p{L}])|(?:january|february|march|april|june|july|august|september|october|november|december)\s+\d{1,2}(?![\p{N}])|\d{4}-\d{2}-\d{2}|\d{1,2}[.\/]\d{1,2}[.\/]\d{4})/u';

    private const EMAIL = '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u';

    /** A phone number: international or trunk-prefixed, so hours and years never match. */
    private const PHONE = '/(?:\+\d{1,3}|\b0)[\s(.-]*\d{3}[\s).-]*\d{3}[\s.-]*\d{2}[\s.-]*\d{2}\b/u';

    /** A titled person ("Prof. Dr. Ayşe …", "Dr. Smith"); case-sensitive, on the original sentence. */
    private const PERSON = '/(?<![\p{L}])(?:Prof\.|Doç\.|Dr\.|Öğr\. ?Gör\.|Öğr\. ?Üyesi|Arş\. ?Gör\.|Mr\.|Mrs\.|Ms\.|Professor|Профессор|Доцент)\s*(?:Dr\.\s*)?\p{Lu}\p{Ll}+/u';

    /** A programme's length ("4 yıllık", "four years", "4 года"). */
    private const PROGRAMME_LENGTH = '/(?<![\p{N}])(?:\d|iki|uc|dort|bes|two|three|four|five)\s*(?:yil(?:lik|dir|da)?|years?|year-long|года?|лет)(?![\p{L}])/u';

    private const PROGRAMME_CONTEXT = '/program|bolum|lisans|egitim|okumak|degree|bachelor|study|studies|course|программ|обучени|бакалавр/u';

    /** An admission score or grade threshold: an exam or average named with a number. */
    private const REQUIREMENT_SCORE = '/(?<![\p{L}])(?:ielts|toefl|pte|duolingo|yks|tyt|ayt|sat|gpa|not ortalama\w*|ortalama\w*|puan\w*|score|grade point|балл\w*)(?![\p{L}])/u';

    /** A reference to a part of the app ("Keşfet sekmesi", "the Clubs screen", "вкладка …"). */
    private const WEB_REFERENCE = '/web|site|sayfa|https?:|сайт|страниц/u';

    private const UI_REFERENCE = '/sekme\w*|ekran\w*|uygulama(?:da|daki|dan|nin|nın)?\s|uygulamanin|(?<![\p{L}])tab(?:s)?(?![\p{L}])|screen|in the app|app\'s|вкладк\w*|экран\w*|в приложении|раздел\w*/u';

    private const DURATION = '/\b(\d{1,3})\s*(?:dk|dakika|dakikada|minutes?|mins?|мин\w*)\b/u';

    private const DISTANCE = '/\b(\d{1,5}(?:[.,]\d+)?)\s*(?:m|metre|metrelik|meters?|metres?|km|kilometre|м|км)\b/u';

    private const LANGUAGE = '/ingilizce|english|turkce|turkish|английск|турецк/u';

    private const LANGUAGE_CONTEXT = '/egitim dili|ogretim dili|dilinde|taught|language|instruction|okutul|egitim veril|язык|преподают|обучение/u';

    /** "Open now" / "closed now", in the three supported languages (folded). */
    // Either word order: "сейчас открыта" and "открыта сейчас" (measured: the reversed order escaped).
    private const OPEN_NOW = '/(?:su an(?:da)?|simdi|halen)\s+(?:\S+\s+)?acik|acik\s+(?:\S+\s+)?su an|(?:currently|right now|now)\s+open|open\s+(?:right\s+)?now|is\s+open\s+at\s+the\s+moment|сейчас\s+(?:\S+\s+)?открыт|открыт\w*\s+сейчас/u';

    private const CLOSED_NOW = '/(?:su an(?:da)?|simdi|halen)\s+(?:\S+\s+)?kapali|kapali\s+(?:\S+\s+)?su an|(?:currently|right now|now)\s+closed|closed\s+(?:right\s+)?now|is\s+closed\s+at\s+the\s+moment|сейчас\s+(?:\S+\s+)?закрыт|закрыт\w*\s+сейчас/u';

    /** A day other than "now" in a sentence: weekend, a weekday, or tomorrow (folded). */
    private const WEEKEND = '/(?<![\p{L}])(?:hafta ?sonu|cumartesi|pazar(?:lar|i|lari)?\b|weekends?|saturdays?|sundays?|выходн|суббот|воскресень)/u';

    private const WEEKDAY = '/(?<![\p{L}])(?:hafta ?ici|pazartesi|sali|carsamba|persembe|cuma(?!rtesi)|weekdays?|mondays?|tuesdays?|wednesdays?|thursdays?|fridays?|будн|понедельник|вторник|сред[ау]|четверг|пятниц)/u';

    private const TOMORROW = '/(?<![\p{L}])(?:yarin|tomorrow|завтра)/u';

    /** Open/closed wording; the closed forms (incl. "açık değil", "not open") are checked first. */
    private const CLOSED_WORDS = '/kapali|acik degil|acilmiyor|calismiyor|closed|not open|isn\'t open|закрыт|не работает/u';

    private const OPEN_WORDS = '/(?<![\p{L}])acik|open|открыт|работает/u';

    /** "Açık", "open", "открыто" as a whole word: a status, not "açıklama" or "opening". */
    private const BARE_OPEN = '/(?<![\p{L}])(?:acik(?:tir|dir)?|open|открыт[аоы]?|работает)(?![\p{L}])/u';

    /** Asking or offering to check whether it is open is not a claim that it is. */
    private const OPEN_QUESTION = '/olup olmad|acik mi|acik olup|whether|if it is open|is it open|открыт[аоы]? ли|работает ли/u';

    // Word-start bound: "hekimlik" (medicine) is not "kimlik" (identity card).
    private const DOCUMENT_NOUNS = '/(?<![\p{L}])(?:kimlik|pasaport|fotokopi|fotograf|vesikalik|diploma|transkript|dilekce|ikametgah|saglik raporu|basvuru formu|kayit formu|ogrenci belgesi|passport|photo|transcript|application form|id card|certificate|паспорт|фото|аттестат|справк)/u';

    /**
     * Street addresses. No SupportedFact carries one (places are named, not
     * addressed), so on this path an address can only have been borrowed —
     * measured: the university's postal address, present in the prompt's
     * institution block, given as the library's location.
     */
    private const ADDRESS = '/\b(?:sokak|sokagi|sk\.|cadde|caddesi|cad\.|bulvar|street|st\.|avenue|road|no\s*:\s*\d+|posta kodu|mersin 10|улица|ул\.)\b/u';

    /** Floors and room numbers: no fact carries them either. */
    private const LOCATION_DETAIL = '/\b\d+\s*\.?\s*kat\w*|zemin kat|\bfloor\s*\d+|\b\d+(?:st|nd|rd|th)\s+floor|ground floor|\broom\s*\d+|\boda\s*\d+|\b\d+\s*этаж\w*|этаж\w*\s*\d+/u';

    /**
     * Speculative or generalising language. On this path it carries domain
     * information no fact supports ("usually in faculty buildings").
     */
    private const SPECULATIVE = '/\b(?:genellikle|cogunlukla|cogu zaman|muhtemelen|buyuk ihtimalle|buyuk olasilikla|normalde|tipik olarak|genelde|usually|generally|typically|probably|likely|commonly|normally|обычно|как правило|вероятно|скорее всего|как правило)\b/u';

    /**
     * Absolute negatives about what exists. A missing row is "not found in
     * the current data", never "does not exist" — no source in AICAD today
     * states authoritatively that something is NOT offered.
     */
    private const NEGATIVE_EXISTENCE = '/\b(?:bulunmamaktadir|bulunmuyor|yoktur|mevcut degildir|sunmamaktadir|sunmuyor|acilmamaktadir|verilmemektedir|does not (?:offer|have|exist)|doesn\'t (?:offer|have|exist)|there is no|there are no|is not offered|no such|не предлагает|не существует|отсутствует|нет такого|нет такой)\b/u';

    /** A reported gap, or a statement about the data rather than the world. */
    private const GAP_PHRASES = '/bulunamad|bulamad|bulamiyorum|bilgi yok|bilgi bulunmuyor|kaynaklarda|resmi kaynak|in the (?:official )?sources|в источниках|bilgiye ulasilamad|belirtilmemis|belirtmiyor|net degil|bilinmiyor|kayitli degil|mevcut verilerde|kayitlarimizda|dogrulanmis bilgi|teyit edil|paylasirsan|paylasman|paylasirsaniz|not (?:available|stated|listed|found)|could not|couldn\'t find|no (?:verified )?information|unknown|in (?:our|the current) (?:data|records)|не найден|нет данных|не указан|нет информации/u';

    /** Offers, questions, transitions, politeness and referrals — presentation, not domain claims. */
    private const PRESENTATION = '/\?$|istersen|isterseniz|yardimci olabilirim|yardimci olmamı|ozetle|soyle ozetleyebilirim|tabii|elbette|merhaba|rica ederim|danisabilirsin|danismanizi|danisman|ulasabilirsin|ulasmanizi|iletisime gec|basvurabilirsin|kontrol edebilirim|gosterebilirim|cikarabilirim|bulabilirim|if you (?:like|want|share)|let me|i can|feel free|you may contact|contact the|please (?:ask|contact|check)|sure|of course|in summary|если хотите|могу|обратитесь|свяжитесь|конечно/u';

    /** Campus subjects: a declarative sentence about one with no fact is UNCERTAIN, not presentation. */
    private const CAMPUS_SUBJECT = '/bina|kampus|ofis|oda|kulup|bolum|program|fakulte|kutuphane|yemekhane|ogrenci isleri|building|campus|office|room|club|department|faculty|library|cafeteria|здани|кампус|офис|клуб|факультет|библиотек/u';

    /**
     * @return array{claims: list<array{text: string, fact_ids: list<string>}>, used_fact_ids: list<string>,
     *     unsupported: list<array{text: string, kind: string, detail: string}>, sentences: list<array{text: string, class: string, fact_ids: list<string>, kinds: list<string>}>,
     *     counts: array<string, int>, coverage: array{tasks: int, covered: int, covered_task_ids: list<string>}, plan_outcome: string}
     */
    public function verify(string $answer, FactResult $facts): array
    {
        $all = $facts->facts();
        $documentsSupported = collect($all)->contains(fn (SupportedFact $f) => $f->factType === 'required_documents');
        $languages = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'program_language');
        $openNow = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'is_open_now');
        $times = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'current_opening_hours')
            ->flatMap(fn (SupportedFact $f) => explode(',', $f->normalizedValue))
            ->flatMap(fn (string $range) => explode('-', $range))->map(fn ($t) => $this->time($t))
            // The moment "open now" was evaluated is part of that fact ("as of 15:14 it is open").
            ->merge($openNow->map(fn (SupportedFact $f) => substr((string) ($f->qualifiers['evaluated_at'] ?? ''), 11, 5))->filter())
            ->all();
        $duration = collect($all)->first(fn (SupportedFact $f) => $f->factType === 'route_duration_min');
        $dates = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'academic_date')->flatMap(fn (SupportedFact $f) => $f->anchors);
        // An event fact carries its own day.
        $dates = $dates->merge(collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'current_events' && is_array($f->value))
            ->flatMap(fn (SupportedFact $f) => SupportedFactBuilder::dateAnchors((string) ($f->value['date'] ?? ''), (string) ($f->value['date'] ?? ''))));
        $durations = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'programme_duration');
        $documentsAsked = collect($facts->plan->tasks)->contains(fn (array $t) => $t['task_type'] === 'required_documents');
        $statusAsked = collect($facts->plan->tasks)->contains(fn (array $t) => in_array($t['task_type'], ['opening_hours', 'filter_open_now'], true));
        $emails = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'contact_email')->map(fn (SupportedFact $f) => mb_strtolower((string) $f->value));
        $phones = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'contact_phone')->map(fn (SupportedFact $f) => preg_replace('/\D+/', '', (string) $f->value));
        $distance = collect($all)->first(fn (SupportedFact $f) => $f->factType === 'route_distance_m');

        $claims = $unsupported = $sentences = [];
        $used = [];
        foreach ($this->sentences($answer) as $sentence) {
            $folded = TextFold::fold($sentence);
            $reportsGap = (bool) preg_match(self::GAP_PHRASES, $folded);
            $problems = [];
            $ids = [];

            if (preg_match_all(self::TIME, $sentence, $m, PREG_SET_ORDER)) {
                foreach ($m as $t) {
                    if (! in_array($this->time($t[0]), $times, true)) {
                        $problems[] = ['time', $t[0].' is not in any supported opening hours'];
                    }
                }
            }
            if (preg_match_all(self::DURATION, $folded, $m)) {
                foreach ($m[1] as $n) {
                    if ($duration === null || abs((int) $n - (int) $duration->value) > 1) {
                        $problems[] = ['route_figure', $n.' min is not a supported walking duration'];
                    }
                }
            }
            if (preg_match_all(self::DISTANCE, $folded, $m)) {
                foreach ($m[1] as $n) {
                    $meters = (float) str_replace(',', '.', $n);
                    $meters = str_contains($folded, $n.' km') || str_contains($folded, $n.' kilometre') ? $meters * 1000 : $meters;
                    if ($distance === null || abs($meters - (float) $distance->value) > max(25, 0.1 * (float) $distance->value)) {
                        $problems[] = ['route_figure', $n.' is not a supported walking distance'];
                    }
                }
            }
            // Open or closed on ANOTHER day (weekend, a weekday, tomorrow) is a
            // claim about the schedule's days. Measured: "The library is open on
            // Saturdays" escaped as UNCERTAIN while its hours were weekdays only.
            $nowClaim = preg_match(self::OPEN_NOW, $folded) || preg_match(self::CLOSED_NOW, $folded);
            if (! $nowClaim && ! $reportsGap) {
                [$dayStatus, $dayIds] = $this->dayStatus($folded, $all) + [1 => []];
                if ($dayStatus === true) {
                    array_push($ids, ...$dayIds);
                } elseif ($dayStatus !== null) {
                    $problems[] = ['day_status', $dayStatus];
                }
            }
            // Where open status was asked, an unqualified "X açık / is open" —
            // no time, no day, not a question — says it is open NOW. Measured:
            // "GARDEN MENÜ adlı açık yemek yeri mevcuttur" passed on the venue
            // name while no venue had hours.
            if ($statusAsked && ! $nowClaim && ! $reportsGap && preg_match(self::BARE_OPEN, $folded) && ! preg_match(self::CLOSED_WORDS, $folded)
                && ! preg_match(self::TIME, $sentence) && ! preg_match(self::WEEKEND, $folded) && ! preg_match(self::WEEKDAY, $folded)
                && ! preg_match(self::TOMORROW, $folded) && ! preg_match(self::OPEN_QUESTION, $folded)) {
                $match = $openNow->first(fn (SupportedFact $f) => $f->value === true);
                if ($match !== null) {
                    $ids[] = $match->id;
                } else {
                    $problems[] = ['open_now', 'says it is open (no time or day given, so now), which '
                        .($openNow->isEmpty() ? 'no is_open_now fact supports' : 'contradicts the is_open_now fact')];
                }
            }
            // "Open now" is its own fact: the hours alone never support it.
            foreach ([[self::OPEN_NOW, true], [self::CLOSED_NOW, false]] as [$pattern, $claimed]) {
                if (preg_match($pattern, $folded)) {
                    $match = $openNow->first(fn (SupportedFact $f) => $f->value === $claimed);
                    if ($match !== null) {
                        $ids[] = $match->id;
                    } else {
                        $problems[] = ['open_now', 'says it is '.($claimed ? 'open' : 'closed').' now, which '
                            .($openNow->isEmpty() ? 'no is_open_now fact supports' : 'contradicts the is_open_now fact')];
                    }
                }
            }
            if (preg_match_all(self::LANGUAGE, $folded, $m) && preg_match(self::LANGUAGE_CONTEXT, $folded) && ! $reportsGap) {
                foreach (array_unique($m[0]) as $word) {
                    $code = in_array($word, ['ingilizce', 'english', 'английск'], true) ? 'en' : 'tr';
                    if (! $languages->contains(fn (SupportedFact $f) => $f->normalizedValue === $code)) {
                        $problems[] = ['language', 'language of instruction "'.$word.'" is not a supported fact'];
                    }
                }
            }
            if (preg_match(self::ADDRESS, $folded, $hit)) {
                $problems[] = ['location', 'street address ("'.trim($hit[0]).'") is not a supported fact'];
            }
            if (preg_match(self::LOCATION_DETAIL, $folded, $hit)) {
                $problems[] = ['location', 'floor or room ("'.trim($hit[0]).'") is not a supported fact'];
            }
            // Only where documents were asked: "Fotoğraf Kulübü" is a club, not a photo to bring.
            if ($documentsAsked && preg_match_all(self::DOCUMENT_NOUNS, $folded, $m) && ! $reportsGap) {
                $items = collect($all)->filter(fn (SupportedFact $f) => $f->factType === 'required_documents')->flatMap(fn (SupportedFact $f) => $f->anchors);
                foreach (array_unique($m[0]) as $noun) {
                    if (! $documentsSupported || ! $items->contains(fn (string $item) => str_contains($item, $noun))) {
                        $problems[] = ['document', '"'.$noun.'" is not a supported required document'];
                    }
                }
            }
            if (preg_match_all(self::DATE, $folded, $m) && ! $reportsGap) {
                foreach (array_unique($m[0]) as $date) {
                    if (! $dates->contains(fn (string $a) => $this->sameDate($a, $date))) {
                        $problems[] = ['date', '"'.$date.'" is not a supported calendar date'];
                    }
                }
            }
            if (preg_match_all(self::EMAIL, $sentence, $m)) {
                foreach (array_unique($m[0]) as $email) {
                    if (! $emails->contains(mb_strtolower($email))) {
                        $problems[] = ['contact', 'e-mail '.$email.' is not a supported contact fact'];
                    }
                }
            }
            if (preg_match_all(self::PHONE, $sentence, $m)) {
                foreach (array_unique($m[0]) as $phone) {
                    $digits = preg_replace('/\D+/', '', $phone);
                    if (! $phones->contains(fn (string $p) => str_ends_with($p, (string) substr((string) $digits, -7)))) {
                        $problems[] = ['contact', 'phone '.trim($phone).' is not a supported contact fact'];
                    }
                }
            }
            // No canonical record names a person yet (staff rows are offices),
            // so a titled name is never backed by a fact.
            if (preg_match(self::PERSON, $sentence, $hit)) {
                $problems[] = ['person', 'person ("'.$hit[0].'") is not a supported fact'];
            }
            if (preg_match(self::PROGRAMME_LENGTH, $folded, $hit) && preg_match(self::PROGRAMME_CONTEXT, $folded) && ! $reportsGap) {
                $length = $durations->first(fn (SupportedFact $f) => ProgrammeDuration::sameLength($f->normalizedValue, $hit[0]));
                if ($length !== null) {
                    $ids[] = $length->id;
                } else {
                    $problems[] = ['programme_attribute', 'programme length ("'.$hit[0].'") is not a supported fact'];
                }
            }
            if (preg_match(self::REQUIREMENT_SCORE, $folded, $hit) && preg_match('/\d/', $folded) && ! $reportsGap) {
                $problems[] = ['requirement', 'admission score or threshold ("'.$hit[0].'") is not a supported fact'];
            }
            // A web page is not an app screen: those are links, checked by AnswerGrounding.
            if (preg_match(self::UI_REFERENCE, $folded, $hit) && ! preg_match(self::WEB_REFERENCE, $folded)
                && app(AppNavigation::class)->names($folded) === null) {
                $problems[] = ['ui_destination', 'app destination ("'.trim($hit[0]).'") is not in the trusted navigation'];
            }
            // Answers are Turkish, English or Russian: another script is a generation fault (measured: "摄影作品").
            if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $sentence)) {
                $problems[] = ['script', 'text in a script the answer languages do not use'];
            }
            if (preg_match(self::NEGATIVE_EXISTENCE, $folded, $hit) && ! $reportsGap) {
                $problems[] = ['negative_claim', 'absolute negative ("'.$hit[0].'") — the data can only say it was not found'];
            }

            foreach ($all as $fact) {
                foreach ($fact->anchors as $anchor) {
                    if ($anchor !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($anchor, '/').'/u', $folded)) {
                        $ids[] = $fact->id;
                        break;
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            $presentation = $reportsGap || preg_match(self::PRESENTATION, $folded) === 1;
            // Speculation is a claim only where no fact backs the sentence.
            // A gap clause does not license a generalisation in the same
            // sentence ("…belirtilmemişse, genellikle ortak alanlarda…" escaped).
            if ($ids === [] && preg_match(self::SPECULATIVE, $folded, $hit)) {
                $problems[] = ['speculation', 'speculative statement ("'.$hit[0].'") with no supporting fact'];
            }

            $class = match (true) {
                $problems !== [] => self::UNSUPPORTED_FACTUAL,
                $ids !== [] => self::SUPPORTED_BY_FACT,
                $presentation => self::PRESENTATION_ONLY,
                preg_match(self::CAMPUS_SUBJECT, $folded) === 1 => self::UNCERTAIN,
                default => self::PRESENTATION_ONLY,
            };
            $sentences[] = ['text' => $sentence, 'class' => $class, 'fact_ids' => $ids, 'kinds' => array_values(array_unique(array_column($problems, 0)))];
            if ($class === self::UNSUPPORTED_FACTUAL) {
                foreach ($problems as [$kind, $detail]) {
                    $unsupported[] = ['text' => $sentence, 'kind' => $kind, 'detail' => $detail];
                }
            } elseif ($class === self::SUPPORTED_BY_FACT) {
                $claims[] = ['text' => $sentence, 'fact_ids' => $ids];
                array_push($used, ...$ids);
            }
        }
        $used = array_values(array_unique($used));
        $mentioned = array_values(array_unique(array_merge(...array_column($sentences, 'fact_ids') ?: [[]])));

        return ['claims' => $claims, 'used_fact_ids' => $used, 'unsupported' => $unsupported, 'sentences' => $sentences,
            'counts' => array_count_values(array_column($sentences, 'class')) + array_fill_keys([self::SUPPORTED_BY_FACT, self::PRESENTATION_ONLY, self::UNSUPPORTED_FACTUAL, self::UNCERTAIN], 0),
            // Supported coverage, and what the text attempted (an unsupported
            // sentence about a task still "covered" it before removal).
            'coverage' => $this->coverage($facts, $used), 'coverage_attempted' => $this->coverage($facts, $mentioned),
            'plan_outcome' => $facts->plan->outcome];
    }

    /**
     * A day-specific open/closed claim checked against the schedule's days.
     * Returns [null] when the sentence makes no such claim, [true, fact ids]
     * when the schedule supports it, or [reason] when it does not.
     *
     * @param  list<SupportedFact>  $facts
     * @return array{0: true|string|null, 1?: list<string>}
     */
    private function dayStatus(string $folded, array $facts): array
    {
        $day = match (true) {
            (bool) preg_match(self::WEEKEND, $folded) => 'weekend',
            (bool) preg_match(self::WEEKDAY, $folded) => 'weekday',
            (bool) preg_match(self::TOMORROW, $folded) => now()->setTimezone(OpeningHours::timezone())->addDay()->isWeekend() ? 'weekend' : 'weekday',
            default => null,
        };
        $claimed = match (true) {
            (bool) preg_match(self::CLOSED_WORDS, $folded) => false,
            (bool) preg_match(self::OPEN_WORDS, $folded) => true,
            default => null,
        };
        if ($day === null || $claimed === null) {
            return [null];
        }
        $schedules = array_values(array_filter($facts, fn (SupportedFact $f) => $f->factType === 'current_opening_hours' && is_array($f->value)));
        if ($schedules === []) {
            return ['says it is '.($claimed ? 'open' : 'closed').' on a '.$day.' with no opening-hours fact'];
        }
        foreach ($schedules as $fact) {
            $open = match ($fact->value['days'] ?? 'unspecified') {
                'weekdays' => $day === 'weekday',
                'daily' => true,
                default => null,
            };
            if ($open === $claimed) {
                return [true, [$fact->id]];
            }
        }

        return ['says it is '.($claimed ? 'open' : 'closed').' on a '.$day.', which the schedule\'s days do not support'];
    }

    /**
     * Tasks with verified facts, and which of them the answer actually
     * covers — recomputed on the FINAL text, so a removed sentence no longer
     * counts.
     *
     * @param  list<string>  $used
     * @return array{tasks: int, covered: int, covered_task_ids: list<string>}
     */
    public function coverage(FactResult $facts, array $used): array
    {
        $withFacts = array_values(array_filter($facts->plan->tasks, fn (array $t) => $t['fact_ids'] !== []));
        $covered = array_values(array_map(fn ($t) => $t['task_id'], array_filter($withFacts, fn ($t) => array_intersect($t['fact_ids'], $used) !== [])));

        return ['tasks' => count($withFacts), 'covered' => count($covered), 'covered_task_ids' => $covered];
    }

    /**
     * The answer without the sentences that carry an unsupported claim —
     * every other task's supported content is kept. Null when nothing useful
     * is left.
     *
     * @param  list<array{text: string, kind: string, detail: string}>  $unsupported
     */
    public function withhold(string $answer, array $unsupported): ?string
    {
        $bad = array_unique(array_column($unsupported, 'text'));
        $kept = array_values(array_filter($this->sentences($answer), fn (string $s) => ! in_array($s, $bad, true)));

        return $kept === [] ? null : implode(' ', $kept);
    }

    /** @return list<string> */
    public function sentences(string $answer): array
    {
        return array_values(array_filter(array_map('trim',
            // Not after an ordinal: "binasının 2. katında" is one sentence.
            // Nor after a title: "Prof. Dr. Ayşe Yılmaz" is one name.
            preg_split('/(?:(?<=[!?…])|(?<=[^\d]\.)(?<!Dr\.)(?<!Prof\.)(?<!Doç\.)(?<!Öğr\.)(?<!Gör\.)(?<!Arş\.)(?<!Mr\.)(?<!Ms\.)(?<!Mrs\.))\s+|\n+/u', trim($answer), -1, PREG_SPLIT_NO_EMPTY) ?: []), fn ($s) => $s !== ''));
    }

    /** Whether a supported date anchor and a claimed date name the same day ("5 ekim" ~ "5 ekim 2026"). */
    private function sameDate(string $anchor, string $claimed): bool
    {
        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $claimed, $d)) {
            $claimed = sprintf('%04d-%02d-%02d', $d[3], $d[2], $d[1]);
        }
        $ru = ['январ' => 'january', 'феврал' => 'february', 'март' => 'march', 'апрел' => 'april', 'ма' => 'may', 'июн' => 'june',
            'июл' => 'july', 'август' => 'august', 'сентябр' => 'september', 'октябр' => 'october', 'ноябр' => 'november', 'декабр' => 'december'];
        if (preg_match('/^(\d{1,2})\s+(\p{Cyrillic}+)$/u', $claimed, $d)) {
            foreach ($ru as $stem => $en) {
                if (str_starts_with($d[2], $stem) && ($stem !== 'ма' || mb_strlen($d[2]) <= 3)) {
                    $claimed = $d[1].' '.$en;
                    break;
                }
            }
        }

        return $anchor === $claimed;
    }

    /** Whether a text reports that something was not found (any supported language). */
    public function reportsGap(string $text): bool
    {
        return (bool) preg_match(self::GAP_PHRASES, TextFold::fold($text));
    }

    private function time(string $t): string
    {
        [$h, $m] = preg_split('/[:.]/', $t) + [1 => '00'];

        return sprintf('%02d:%02d', (int) $h, (int) $m);
    }
}
