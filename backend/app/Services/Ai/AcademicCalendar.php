<?php

namespace App\Services\Ai;

use App\Models\KnowledgeDocument;
use App\Services\Ai\Planning\OpeningHours;
use App\Support\TextFold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Academic dates from ARUCAD's official academic-calendar page.
 *
 * The page is the authoritative source: it lists every date as
 * "<month>[-<month>] <day>[-<day>] <year> <event>" under GÜZ / BAHAR / YAZ
 * headings. The `academic_years` table holds one row (2025-2026, still
 * flagged active) and no term dates, so it cannot answer "when do classes
 * start". Parsing is deterministic: a fixed month table and a fixed
 * event vocabulary — no model, and nothing inferred from prose.
 *
 * A calendar whose academic year has ended is STALE: it is reported as
 * such, never presented as the current year's dates.
 */
final class AcademicCalendar
{
    /** The undergraduate calendar (Turkish page first: it is the one maintained). */
    public const SOURCES = [
        'https://arucad.edu.tr/lisans-akademik-takvim/',
        'https://aday.arucad.edu.tr/lisans-akademik-takvim/',
    ];

    /** How long after a term began "when do classes start" still mentions it. */
    private const RECENT_DAYS = 45;

    private const MONTHS = [
        'ocak' => 1, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayis' => 5, 'haziran' => 6,
        'temmuz' => 7, 'agustos' => 8, 'eylul' => 9, 'ekim' => 10, 'kasim' => 11, 'aralik' => 12,
    ];

    /**
     * Event kinds, by folded wording on the page. One date can carry several
     * events ("Ders Başlangıcı Geç Kayıt Başlangıcı (Cezalı)").
     *
     * @var array<string, string>
     */
    private const KINDS = [
        'classes_start' => '/ders baslangici/u',
        'classes_end' => '/son ders gunu/u',
        'registration' => '/cevrimici ders kayitlari baslangici|ders kayit donemi/u',
        'add_drop_deadline' => '/ders ekleme.{0,3}birakma icin son gun/u',
        'late_registration_deadline' => '/gec ve cezali kayit icin son gun/u',
        'final_exams' => '/donem sonu sinav/u',
        'makeup_exams' => '/butunleme sinavlari(?! icin basvuru)(?! notlarinin)/u',
        'orientation' => '/oryantasyon/u',
        'holiday' => '/bayram|yeni yil|anma gunu/u',
    ];

    /**
     * @return array{academic_year: ?string, source_url: ?string, title: ?string, fetched_at: ?string, stale: bool,
     *     entries: list<array{term: string, kinds: list<string>, label: string, start: string, end: string}>}|null
     */
    public function current(): ?array
    {
        $doc = KnowledgeDocument::query()->whereIn('url', self::SOURCES)->where('document_status', 'indexed')
            ->orderByRaw('case when url = ? then 0 else 1 end', [self::SOURCES[0]])->first();
        if ($doc === null) {
            return null;
        }
        $parsed = Cache::remember('aicad:academic-calendar:'.$doc->content_hash, now()->addDay(),
            fn () => $this->parse((string) ($doc->content_clean ?: $doc->content)));
        if ($parsed['entries'] === []) {
            return null;
        }
        $last = collect($parsed['entries'])->max('end');
        $today = CarbonImmutable::now(OpeningHours::timezone())->toDateString();

        return $parsed + [
            'source_url' => (string) $doc->url,
            'title' => (string) $doc->title,
            'fetched_at' => $doc->fetched_at?->toIso8601String(),
            'stale' => $last < $today,
        ];
    }

    /**
     * @return array{academic_year: ?string, entries: list<array{term: string, kinds: list<string>, label: string, start: string, end: string}>}
     */
    public function parse(string $text): array
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $year = preg_match('/(20\d\d)\s*[-–]\s*(20\d\d)/u', $text, $y) ? $y[1].'-'.$y[2] : null;
        $months = implode('|', ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık']);
        $date = '/('.$months.')(?:\s*-\s*('.$months.'))?\s+(\d{1,2})(?:\s*-\s*(\d{1,2}))?\s+(20\d\d)/u';
        if (! preg_match_all($date, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return ['academic_year' => $year, 'entries' => []];
        }

        $entries = [];
        foreach ($m as $i => $match) {
            $from = $match[0][1] + strlen($match[0][0]);
            $to = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($text);
            // An event label is short; a section's last entry must not absorb the page's tail.
            $label = mb_substr(trim(substr($text, $from, $to - $from)), 0, 160);
            $folded = TextFold::fold($label);
            $kinds = array_keys(array_filter(self::KINDS, fn (string $re) => (bool) preg_match($re, $folded)));
            if ($kinds === []) {
                continue;
            }
            [$startMonth, $endMonth] = [self::MONTHS[TextFold::fold($match[1][0])], isset($match[2][0]) && $match[2][0] !== '' ? self::MONTHS[TextFold::fold($match[2][0])] : null];
            $yearNum = (int) $match[5][0];
            $startDay = (int) $match[3][0];
            $endDay = isset($match[4][0]) && $match[4][0] !== '' ? (int) $match[4][0] : $startDay;
            $endMonth ??= $startMonth;
            // A range ending in an earlier month crosses the year ("Aralık-Ocak 28-02 2027").
            $startYear = $endMonth < $startMonth ? $yearNum - 1 : $yearNum;
            $start = sprintf('%04d-%02d-%02d', $startYear, $startMonth, $startDay);
            $end = sprintf('%04d-%02d-%02d', $yearNum, $endMonth, $endDay);
            if (! checkdate($startMonth, $startDay, $startYear) || ! checkdate($endMonth, $endDay, $yearNum)) {
                continue;
            }
            // Rows from the previous year's summer school, listed for reference, end before this year starts.
            if ($year !== null && $end < substr($year, 0, 4).'-09-01') {
                continue;
            }
            $entries[] = ['term' => $this->term($text, $match[0][1]), 'kinds' => $kinds, 'label' => mb_strimwidth($label, 0, 140, '…'),
                'start' => $start, 'end' => $end];
        }

        return ['academic_year' => $year, 'entries' => $entries];
    }

    /**
     * The entries of one kind, in date order, the next upcoming one, and
     * one that began in the last RECENT_DAYS: asked just after a term
     * starts, "when do classes start" is about that term too.
     *
     * @return array{entries: list<array<string, mixed>>, next: ?array<string, mixed>, recent: ?array<string, mixed>}
     */
    public function ofKind(array $calendar, string $kind): array
    {
        $entries = array_values(array_filter($calendar['entries'], fn ($e) => in_array($kind, $e['kinds'], true)));
        usort($entries, fn ($a, $b) => strcmp($a['start'], $b['start']));
        $today = CarbonImmutable::now(OpeningHours::timezone())->toDateString();
        $next = collect($entries)->first(fn ($e) => $e['end'] >= $today);
        $since = CarbonImmutable::now(OpeningHours::timezone())->subDays(self::RECENT_DAYS)->toDateString();
        $recent = collect($entries)->last(fn ($e) => $e['start'] < $today && $e['start'] >= $since && $e !== $next);

        return ['entries' => $entries, 'next' => $next, 'recent' => $recent];
    }

    /** Which kind a question asks for, by fixed wording (folded); null when it names none. */
    public static function kindAsked(string $folded): ?string
    {
        return match (true) {
            (bool) preg_match('/ekle.{0,6}birak|add.{0,3}drop|добав/u', $folded) => 'add_drop_deadline',
            (bool) preg_match('/butunleme|make.?up|пересдач/u', $folded) => 'makeup_exams',
            (bool) preg_match('/final|sinav|exam|экзамен|сесси/u', $folded) => 'final_exams',
            (bool) preg_match('/kayit|registration|register|регистрац|запис/u', $folded) => 'registration',
            (bool) preg_match('/tatil|bayram|holiday|праздник|каникул/u', $folded) => 'holiday',
            (bool) preg_match('/oryantasyon|orientation|ориентац/u', $folded) => 'orientation',
            (bool) preg_match('/son ders|bitiyor|biter|ends?\b|last day of class|заканчива|конец/u', $folded) => 'classes_end',
            (bool) preg_match('/basl|start|begin|ders(ler)? ne zaman|akademik takvim|academic calendar|semester|donem ne zaman|начина|семестр|занят/u', $folded) => 'classes_start',
            default => null,
        };
    }

    /** The term section an offset falls in: the last UPPERCASE heading before it (labels use title case). */
    private function term(string $text, int $offset): string
    {
        $before = substr($text, 0, $offset);
        $positions = ['fall' => strrpos($before, 'GÜZ DÖNEMİ'), 'spring' => strrpos($before, 'BAHAR DÖNEMİ'), 'summer' => strrpos($before, 'YAZ OKULU')];
        $positions = array_filter($positions, fn ($p) => $p !== false);
        arsort($positions);

        return (string) (array_key_first($positions) ?? 'unknown');
    }
}
