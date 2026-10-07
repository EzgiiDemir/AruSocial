<?php

namespace App\Services\Ai;

use App\Models\AiEntityAlias;
use App\Models\Appointment;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\KnowledgeFact;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\User;
use App\Services\Ai\Facts\ProgrammeDuration;
use App\Services\Ai\Planning\OpeningHours;
use App\Support\QueryLanguage;
use App\Support\SchemaColumnCache;
use App\Support\SupportIntent;
use App\Support\TextFold;
use Illuminate\Support\Carbon;

/**
 * Answers the cheap questions without a model.
 *
 * On a campus, question frequency is extremely top-heavy: where is the
 * library, what is on today, when does the canteen open, when is my
 * appointment. Every one of those has exactly one correct answer sitting
 * in our own tables. Sending them to a language model spends a shared
 * quota on a lookup, adds a second of latency, and introduces the only
 * failure mode this system cannot tolerate — an invented fact.
 *
 * Three rules keep this from making the assistant feel like a vending
 * machine:
 *
 * 1. **Short questions only.** "Kütüphane nerede?" is a lookup.
 *    "Kütüphane nerede, nasıl giderim ve kaça kadar açık?" is a
 *    conversation, and conversation is what the model is for.
 * 2. **One clear intent, one resolved entity.** Anything ambiguous
 *    returns null and takes the normal path. A wrong fast answer is
 *    worse than a slow right one.
 * 3. **Never for feelings.** A student saying they are struggling gets
 *    the support path, never a catalogue row. That check runs first.
 *
 * Everything here answers in the language of the question, because an
 * answer a student cannot read is not an answer.
 */
class DirectAnswer
{
    /**
     * A question longer than this is treated as conversation.
     *
     * Generous enough for "where is the student affairs office please"
     * in all three languages, short enough that anything with a second
     * clause goes to the model.
     */
    private const MAX_QUESTION_CHARS = 70;

    /** @return string|null The answer, or null to use the normal path. */
    public function tryAnswer(string $question, ?User $user, Carbon $now): ?string
    {
        if (! (bool) config('ai.direct_answers.enabled', true)) {
            return null;
        }

        $question = trim($question);
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            return null;
        }

        // Distress is never a lookup. First, and unconditionally.
        if (app(SupportIntent::class)->matches($question)) {
            return null;
        }

        $folded = TextFold::fold($question);
        $language = $this->language($question);

        return $this->appointment($folded, $user, $now, $language)
            ?? $this->academicDate($folded, $language)
            ?? $this->programmeDuration($question, $folded, $language)
            ?? $this->eventsToday($folded, $now, $language)
            ?? $this->dining($folded, $now, $language)
            ?? $this->place($folded, $language);
    }

    // ------------------------------------------------------------ intents

    private function appointment(string $q, ?User $user, Carbon $now, string $lang): ?string
    {
        if ($user === null || ! $this->mentions($q, ['randevu', 'appointment', 'запис'])) {
            return null;
        }
        if (! $this->mentions($q, ['benim', 'my', 'мо', 'ne zaman', 'when', 'когда', 'var mi'])) {
            return null;
        }

        $next = Appointment::query()
            ->where('student_user_id', $user->id)
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereDate('slot_date', '>=', $now->toDateString())
            ->orderBy('slot_date')->orderBy('start_time')
            ->first();

        if ($next === null) {
            return match ($lang) {
                'en' => 'You have no upcoming appointments.',
                'ru' => 'У вас нет предстоящих встреч.',
                default => 'Yaklaşan bir randevun görünmüyor.',
            };
        }

        $when = optional($next->slot_date)->format('d.m.Y');
        $time = trim((string) $next->start_time);
        $subject = trim((string) $next->subject);

        return match ($lang) {
            'en' => trim("Your next appointment is on {$when}".($time !== '' ? " at {$time}" : '')
                .($subject !== '' ? " — {$subject}" : '').'.'),
            'ru' => trim("Ваша ближайшая встреча: {$when}".($time !== '' ? " в {$time}" : '')
                .($subject !== '' ? " — {$subject}" : '').'.'),
            default => trim("Yaklaşan randevun {$when}".($time !== '' ? " saat {$time}" : '')
                .($subject !== '' ? " — {$subject}" : '').'.'),
        };
    }

    private function eventsToday(string $q, Carbon $now, string $lang): ?string
    {
        if (! $this->mentions($q, ['etkinlik', 'event', 'меропри'])) {
            return null;
        }
        if (! $this->mentions($q, ['bugun', 'today', 'сегодня', 'ne var', 'what is on', 'whats on'])) {
            return null;
        }

        $today = $this->publishedEvents()
            ->whereDate('event_date', $now->toDateString())
            ->orderBy('time')
            ->limit(5)
            ->get();

        if ($today->isEmpty()) {
            return match ($lang) {
                'en' => 'There are no events scheduled on campus today.',
                'ru' => 'Сегодня мероприятий в кампусе нет.',
                default => 'Bugün kampüste planlanmış bir etkinlik yok.',
            };
        }

        $listed = $today->map(function (Event $event): string {
            $time = trim((string) $event->time);
            $place = trim((string) $event->place_name);

            return $event->title.($time !== '' ? ' ('.$time.')' : '').($place !== '' ? ' — '.$place : '');
        })->implode('; ');

        return match ($lang) {
            'en' => 'Today on campus: '.$listed.'.',
            'ru' => 'Сегодня в кампусе: '.$listed.'.',
            default => 'Bugün kampüste: '.$listed.'.',
        };
    }

    /**
     * "Dersler ne zaman başlıyor?" — from the official academic calendar
     * (AcademicCalendar), never from memory. A calendar whose year has ended
     * is said to be the latest published one, not given as this year's.
     */
    private function academicDate(string $q, string $lang): ?string
    {
        $calendarWords = '/akademik takvim|ders(?:ler)? ne zaman|derslerin baslama|donem\w* ne zaman|(?:guz|bahar) donem\w* (?:ne zaman|basl)|okul\w* ne zaman acil|butunleme|kayitlar ne zaman|ders kayd|final\w* ne zaman|final sinav|sinav\w* ne zaman|ekle.{0,3}birak|bayram tatili'
            .'|academic calendar|semester|classes (?:start|begin|end)|when do classes|add.{0,3}drop|final exams|course registration'
            .'|когда начина|семестр|сесси|академическ/u';
        if (! preg_match($calendarWords, $q) || preg_match('/kulup|club|etkinlik|event|burs|scholarship|yurt|dorm|клуб|мероприят|стипенд/u', $q)) {
            return null;
        }
        $calendars = app(AcademicCalendar::class);
        $calendar = $calendars->current();
        if ($calendar === null) {
            return null;   // no official calendar indexed: the normal path says what it can
        }
        $year = str_replace('-', '–', (string) $calendar['academic_year']);
        if ($calendar['stale']) {
            return match ($lang) {
                'en' => "The latest academic calendar ARUCAD has published is for {$year}; the current year's dates are not available yet.",
                'ru' => "Последний опубликованный академический календарь ARUCAD — на {$year} год; даты текущего года пока недоступны.",
                default => "ARUCAD'ın yayımladığı en güncel akademik takvim {$year} yılına ait; bu yılın tarihleri henüz yayımlanmadı.",
            };
        }
        $kind = AcademicCalendar::kindAsked($q) ?? 'classes_start';
        ['next' => $next, 'recent' => $recent] = $calendars->ofKind($calendar, $kind);
        if ($next === null) {
            return null;
        }
        $what = self::CALENDAR_LABELS[$lang][$kind] ?? self::CALENDAR_LABELS['tr'][$kind];
        $term = self::TERMS[$lang][$next['term']] ?? '';
        $when = $this->dateRange($next['start'], $next['end'], $lang);
        $holiday = $kind === 'holiday' ? ' ('.$next['label'].')' : '';
        // Asked just after a term began: say that too, not only the next term.
        if ($kind === 'classes_start' && $recent !== null) {
            $recentTerm = self::TERMS[$lang][$recent['term']] ?? '';
            $recentWhen = $this->dateRange($recent['start'], $recent['end'], $lang);
            $what .= match ($lang) {
                'en' => " (the {$recentTerm} began on {$recentWhen})",
                'ru' => " ({$recentTerm} начался {$recentWhen})",
                default => " ({$recentTerm} {$recentWhen} tarihinde başladı)",
            };
        }

        return match ($lang) {
            'en' => "{$year} {$term} — {$what}{$holiday}: {$when}. (Source: ARUCAD undergraduate academic calendar)",
            'ru' => "{$year}, {$term} — {$what}{$holiday}: {$when}. (Источник: академический календарь ARUCAD)",
            default => "{$year} {$term} — {$what}{$holiday}: {$when}. (Kaynak: ARUCAD Lisans Akademik Takvim)",
        };
    }

    /**
     * "Görsel İletişim Tasarımı kaç yıl?" — the length the official programme
     * page states (knowledge_facts), for exactly one named programme. Two
     * pages that disagree, or a value not in a known form, leave it to the
     * normal path; a length is never inferred from the degree level.
     */
    private function programmeDuration(string $question, string $q, string $lang): ?string
    {
        if (! preg_match('/kac (?:yil|sene|donem)|egitim suresi|ogrenim suresi|okuma suresi|how long|how many years|duration|сколько (?:лет|длится|учиться)|длительность|срок обучения/u', $q)) {
            return null;
        }
        $programmes = app(ProgrammeCatalog::class)->inText($question);
        if (count($programmes) !== 1) {
            return null;
        }
        $programme = $programmes[0];
        $facts = KnowledgeFact::query()->where('attribute', KnowledgeFact::DURATION)->whereIn('subject_folded', $programme['names'])->get();
        $lengths = $facts->map(function (KnowledgeFact $fact) {
            return preg_match('/^(\d)(?:\s*[-–]\s*(\d))?\s*(yil|yariyil)$/u', TextFold::fold(trim((string) $fact->value)), $m)
                ? $m[1].($m[2] !== '' ? '-'.$m[2] : '').($m[3] === 'yil' ? 'y' : 's') : null;
        })->unique()->values();
        if ($lengths->count() !== 1 || $lengths[0] === null) {
            return null;
        }
        $length = ProgrammeDuration::phrase((string) $lengths[0], $lang);
        $name = $lang === 'tr' ? $programme['name']
            : (AiEntityAlias::query()->where('entity_type', AiEntityAlias::TYPE_PROGRAMME)->where('entity_id', $programme['id'])
                ->where('locale', $lang)->where('active', true)->value('alias') ?? $programme['name']);
        $source = (string) $facts->first()->source_url;

        return match ($lang) {
            'en' => "The {$name} programme takes {$length}. (Source: {$source})",
            'ru' => "Срок обучения по программе «{$name}» — {$length}. (Источник: {$source})",
            default => "{$name} programının eğitim süresi {$length}. (Kaynak: {$source})",
        };
    }

    private const CALENDAR_LABELS = [
        'tr' => ['classes_start' => 'derslerin başlangıcı', 'classes_end' => 'son ders günü', 'registration' => 'ders kayıtları',
            'add_drop_deadline' => 'ders ekleme–bırakma için son gün', 'late_registration_deadline' => 'geç kayıt için son gün',
            'final_exams' => 'dönem sonu sınavları', 'makeup_exams' => 'bütünleme sınavları', 'orientation' => 'oryantasyon', 'holiday' => 'sıradaki tatil'],
        'en' => ['classes_start' => 'classes start', 'classes_end' => 'last day of classes', 'registration' => 'course registration',
            'add_drop_deadline' => 'add–drop deadline', 'late_registration_deadline' => 'late registration deadline',
            'final_exams' => 'final exams', 'makeup_exams' => 'make-up exams', 'orientation' => 'orientation', 'holiday' => 'next holiday'],
        'ru' => ['classes_start' => 'начало занятий', 'classes_end' => 'последний день занятий', 'registration' => 'регистрация на курсы',
            'add_drop_deadline' => 'последний день добавления/отказа от курсов', 'late_registration_deadline' => 'последний день поздней регистрации',
            'final_exams' => 'итоговые экзамены', 'makeup_exams' => 'пересдачи', 'orientation' => 'ориентация', 'holiday' => 'ближайший праздник'],
    ];

    private const TERMS = [
        'tr' => ['fall' => 'Güz dönemi', 'spring' => 'Bahar dönemi', 'summer' => 'Yaz okulu'],
        'en' => ['fall' => 'fall semester', 'spring' => 'spring semester', 'summer' => 'summer school'],
        'ru' => ['fall' => 'осенний семестр', 'spring' => 'весенний семестр', 'summer' => 'летняя школа'],
    ];

    private const MONTH_NAMES = [
        'tr' => ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'],
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'ru' => ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
    ];

    private function dateRange(string $start, string $end, string $lang): string
    {
        $fmt = function (string $d) use ($lang): string {
            [$y, $m, $day] = array_map('intval', explode('-', $d));

            return $day.' '.(self::MONTH_NAMES[$lang] ?? self::MONTH_NAMES['tr'])[$m - 1].' '.$y;
        };

        return $start === $end ? $fmt($start) : $fmt($start).' – '.$fmt($end);
    }

    /**
     * "Yemekhane bugün açık mı / şu an açık mı / yarın açık mı / nerede?" —
     * from the venue's canonical fields only: structured weekly hours when
     * staff entered them (else the free-text hours), its campus place. Each
     * part is stated only when its data exists; otherwise the normal path
     * answers, and says what is not recorded.
     */
    private function dining(string $q, Carbon $now, string $lang): ?string
    {
        if (! $this->mentions($q, ['yemekhane', 'kafeterya', 'kantin', 'canteen', 'cafeteria', 'столов'])) {
            return null;
        }
        $venues = FoodVenue::query()->orderBy('name')->limit(5)->get();
        if ($this->mentions($q, ['nerede', 'nerde', 'where', 'konum', 'где'])) {
            $placed = $venues->map(fn (FoodVenue $v) => [$v, $v->place_id ? Place::query()->find($v->place_id) : null])->filter(fn ($p) => $p[1] !== null);
            if ($placed->isEmpty()) {
                return null;
            }
            $listed = $placed->map(fn ($p) => $p[0]->name.' — '.$p[1]->name)->implode('; ');

            return match ($lang) {
                'en' => 'Location: '.$listed.'.',
                'ru' => 'Местоположение: '.$listed.'.',
                default => 'Konum: '.$listed.'.',
            };
        }
        if (! $this->mentions($q, ['saat', 'hour', 'open', 'acik', 'ne zaman', 'when', 'когда', 'работ', 'открыт'])) {
            return null;
        }
        $tomorrow = $this->mentions($q, ['yarin', 'tomorrow', 'завтра']);
        $lines = [];
        foreach ($venues as $venue) {
            $id = (string) $venue->id;
            $hours = OpeningHour::summary('food_venue', $id, $now) ?? OpeningHour::describe('food_venue', $id, $now, $lang) ?? trim((string) $venue->hours);
            if ($hours === '') {
                continue;
            }
            $status = '';
            if ($tomorrow) {
                $open = OpeningHour::opensOn('food_venue', $id, $now->copy()->addDay())
                    ?? $this->opensOnFromText((string) $venue->hours, $now->copy()->addDay());
                $status = $open === null ? '' : match ($lang) {
                    'en' => $open ? ' — open tomorrow' : ' — closed tomorrow',
                    'ru' => $open ? ' — завтра открыто' : ' — завтра закрыто',
                    default => $open ? ' — yarın açık' : ' — yarın kapalı',
                };
            } else {
                $open = OpeningHour::openAt('food_venue', $id, $now) ?? OpeningHours::evaluate((string) $venue->hours, $now)['open'];
                $time = $now->copy()->setTimezone(OpeningHours::timezone())->format('H:i');
                $status = $open === null ? '' : match ($lang) {
                    'en' => ($open ? ' — open now' : ' — closed now')." (campus time {$time})",
                    'ru' => ($open ? ' — сейчас открыто' : ' — сейчас закрыто')." (время кампуса {$time})",
                    default => ($open ? ' — şu an açık' : ' — şu an kapalı')." (kampüs saatiyle {$time})",
                };
            }
            $lines[] = $venue->name.': '.$hours.$status;
        }
        if ($lines === []) {
            return null;   // Nothing to state; let the normal path say so.
        }

        return match ($lang) {
            'en' => 'Opening hours — '.implode('; ', $lines).'.',
            'ru' => 'Часы работы — '.implode('; ', $lines).'.',
            default => 'Açılış saatleri — '.implode('; ', $lines).'.',
        };
    }

    /** Free-text hours that say which days they cover: open on that date or not; null when they do not say. */
    private function opensOnFromText(string $hours, Carbon $date): ?bool
    {
        $schedule = OpeningHours::parse($hours);

        return match ($schedule['days'] ?? 'unspecified') {
            'weekdays' => ! $date->copy()->setTimezone(OpeningHours::timezone())->isWeekend(),
            'daily' => true,
            default => null,
        };
    }

    private function place(string $q, string $lang): ?string
    {
        if (! $this->mentions($q, ['nerede', 'nerde', 'where', 'konum', 'location', 'где', 'адрес', 'adres'])) {
            return null;
        }

        $match = null;
        foreach (Place::query()->limit(120)->get() as $place) {
            $name = TextFold::fold(trim((string) $place->name));
            if ($name === '' || ! str_contains($q, $name)) {
                continue;
            }
            // Longest name wins: "Nicosia Bandabuliya Campus" must not
            // lose to a place called "Campus".
            if ($match === null || mb_strlen($name) > mb_strlen(TextFold::fold((string) $match->name))) {
                $match = $place;
            }
        }

        if ($match === null) {
            return null;
        }

        $what = trim((string) $match->description);
        $where = trim((string) $match->street);

        /*
         * Knowing the name is not knowing the answer.
         *
         * With neither an address nor a description, the best this path
         * can produce is "Kütüphane is on campus", which tells a student
         * asking where it is precisely nothing. The row exists but the
         * fact does not, so decline and let the normal path try — the
         * knowledge base may have a page that says where it is.
         *
         * Answering anyway is the failure mode this whole fast path is
         * supposed to avoid: confident, instant and useless.
         */
        if ($what === '' && $where === '') {
            return null;
        }

        $accessible = $match->accessible
            ? match ($lang) {
                'en' => ' Step-free access is available.',
                'ru' => ' Есть доступ для маломобильных посетителей.',
                default => ' Engelli erişimine uygun.',
            }
        : '';

        return match ($lang) {
            'en' => trim($match->name.' is on campus'.($where !== '' ? ' at '.$where : '').'.'
                .($what !== '' ? ' '.$what : '').$accessible),
            'ru' => trim($match->name.' находится в кампусе'.($where !== '' ? ': '.$where : '').'.'
                .($what !== '' ? ' '.$what : '').$accessible),
            default => trim($match->name.' kampüste'.($where !== '' ? ', '.$where : '').'.'
                .($what !== '' ? ' '.$what : '').$accessible),
        };
    }

    // ------------------------------------------------------------ helpers

    private function publishedEvents()
    {
        $query = Event::query();
        if (SchemaColumnCache::hasColumn('events', 'draft')) {
            $query->where('draft', false);
        }
        if (SchemaColumnCache::hasColumn('events', 'workflow_status')) {
            $query->where('workflow_status', 'published');
        }

        return $query;
    }

    /** @param list<string> $needles Folded, lowercase stems. */
    private function mentions(string $folded, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($folded, TextFold::fold($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cyrillic decides on sight; otherwise the shared detector, which
     * falls back to Turkish because this is a Turkish campus.
     */
    private function language(string $question): string
    {
        if (preg_match('/\p{Cyrillic}/u', $question) === 1) {
            return 'ru';
        }

        return QueryLanguage::detect($question) ?? 'tr';
    }
}
