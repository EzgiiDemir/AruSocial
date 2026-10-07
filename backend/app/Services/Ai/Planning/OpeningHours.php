<?php

namespace App\Services\Ai\Planning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Reads the opening-hours strings the campus tables actually hold
 * ("Hafta içi 09:00–17:00", "Weekdays 9:00-17:00", "09:00-17:00") and says
 * whether a place is open at a moment. Anything else ("Randevu ile",
 * empty) is unknown — null, never guessed.
 *
 * Times are compared in CAMPUS time (`ai.campus_timezone`): the
 * application clock is UTC, and Kyrenia is two to three hours ahead, so a
 * UTC comparison called a 09:00 office closed until noon.
 */
final class OpeningHours
{
    private const RANGE = '/(\d{1,2})[:.](\d{2})\s*[–\-—]\s*(\d{1,2})[:.](\d{2})/u';

    private const WEEKDAYS = '/hafta\s*i[cç]i|weekdays?|будн/u';

    private const DAILY = '/her\s*g[üu]n|every\s*day|daily|ежедневно/u';

    public static function timezone(): string
    {
        return (string) config('ai.campus_timezone', 'Europe/Nicosia');
    }

    /**
     * Phase 3A/3B view: open at that moment, or null when the hours are not
     * machine-readable. A range without a day qualifier is read as daily.
     */
    public static function isOpen(string $hours, CarbonInterface $at): ?bool
    {
        $schedule = self::parse($hours);
        if ($schedule === null) {
            return null;
        }
        $local = CarbonImmutable::instance($at)->setTimezone(self::timezone());
        if ($schedule['days'] === 'weekdays' && $local->isWeekend()) {
            return false;
        }

        return self::within($schedule, $local);
    }

    /**
     * Phase 3C view, stricter: whether it is open NOW only when the schedule
     * actually covers today. A range with no day qualifier says nothing
     * about weekends, so it yields no answer rather than a guess.
     *
     * @return array{open: ?bool, reason: string, evaluated_at: string, timezone: string}
     */
    public static function evaluate(string $hours, CarbonInterface $at): array
    {
        $local = CarbonImmutable::instance($at)->setTimezone(self::timezone());
        $out = fn (?bool $open, string $reason) => ['open' => $open, 'reason' => $reason,
            'evaluated_at' => $local->toIso8601String(), 'timezone' => self::timezone()];
        $schedule = self::parse($hours);
        if ($schedule === null) {
            return $out(null, 'hours are not machine-readable');
        }
        if ($schedule['days'] === 'unspecified') {
            return $out(null, 'the schedule does not say which days it covers');
        }
        if ($schedule['days'] === 'weekdays' && $local->isWeekend()) {
            return $out(false, 'weekday hours; today is '.$local->englishDayOfWeek);
        }
        $open = self::within($schedule, $local);

        return $out($open, ($open ? 'within ' : 'outside ').$schedule['opens'].'–'.$schedule['closes'].' at '.$local->format('H:i'));
    }

    /** @return array{opens: string, closes: string, days: string}|null */
    public static function parse(string $hours): ?array
    {
        $text = mb_strtolower(trim($hours));
        if (! preg_match(self::RANGE, $text, $m)) {
            return null;
        }

        return [
            'opens' => sprintf('%02d:%s', $m[1], $m[2]),
            'closes' => sprintf('%02d:%s', $m[3], $m[4]),
            'days' => match (true) {
                (bool) preg_match(self::WEEKDAYS, $text) => 'weekdays',
                (bool) preg_match(self::DAILY, $text) => 'daily',
                default => 'unspecified',
            },
        ];
    }

    /** @param array{opens: string, closes: string} $schedule */
    private static function within(array $schedule, CarbonImmutable $local): bool
    {
        [$oh, $om] = array_map('intval', explode(':', $schedule['opens']));
        [$ch, $cm] = array_map('intval', explode(':', $schedule['closes']));
        $minutes = $local->hour * 60 + $local->minute;

        return $minutes >= $oh * 60 + $om && $minutes < $ch * 60 + $cm;
    }
}
