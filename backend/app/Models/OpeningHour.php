<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One opening range of a campus subject on one day of the week
 * (see the 2026_10_06 canonical-fields migration).
 */
class OpeningHour extends Model
{
    public const SUBJECT_TYPES = ['food_venue', 'service', 'place'];

    protected $fillable = ['subject_type', 'subject_id', 'day_of_week', 'opens', 'closes', 'timezone', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'valid_from' => 'date', 'valid_until' => 'date'];
    }

    /** @param  Builder<OpeningHour>  $query */
    public function scopeFor(Builder $query, string $type, string $id): void
    {
        $query->where('subject_type', $type)->where('subject_id', $id);
    }

    /**
     * The subject's rows in force on that day (in each row's own timezone).
     *
     * @return Collection<int, OpeningHour>
     */
    public static function inForce(string $type, string $id, CarbonInterface $at): Collection
    {
        return self::query()->for($type, $id)->orderBy('day_of_week')->orderBy('opens')->get()
            ->filter(function (OpeningHour $row) use ($at) {
                $day = CarbonImmutable::instance($at)->setTimezone($row->timezone)->toDateString();

                return ($row->valid_from === null || $row->valid_from->toDateString() <= $day)
                    && ($row->valid_until === null || $row->valid_until->toDateString() >= $day);
            })->values();
    }

    /**
     * Open at that moment by the structured schedule; null when the subject
     * has no rows in force (the free-text hours then decide). A day with no
     * row is a closed day: the rows ARE the week.
     */
    public static function openAt(string $type, string $id, CarbonInterface $at): ?bool
    {
        $rows = self::inForce($type, $id, $at);
        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->contains(function (OpeningHour $row) use ($at) {
            $local = CarbonImmutable::instance($at)->setTimezone($row->timezone);
            $now = $local->format('H:i');

            return $row->day_of_week === $local->dayOfWeekIso && $now >= self::hm($row->opens) && $now < self::hm($row->closes);
        });
    }

    /**
     * The schedule in the free-text form the rest of AICAD reads
     * ("Hafta içi 09:00–17:00", "Her gün 08:00–20:00") — only when that form
     * says exactly the same thing. Any other week (a short Friday, split
     * lunch hours) returns null rather than a simplification.
     */
    public static function summary(string $type, string $id, CarbonInterface $at): ?string
    {
        $byDay = [];
        foreach (self::inForce($type, $id, $at) as $row) {
            $byDay[$row->day_of_week][] = self::hm($row->opens).'–'.self::hm($row->closes);
        }
        $ranges = array_unique(array_map(fn (array $r) => implode(',', $r), $byDay));
        if (count($ranges) !== 1 || str_contains($ranges[array_key_first($ranges)], ',')) {
            return null;
        }
        $range = reset($ranges);
        $days = array_keys($byDay);
        sort($days);

        return match ($days) {
            [1, 2, 3, 4, 5, 6, 7] => 'Her gün '.$range,
            [1, 2, 3, 4, 5] => 'Hafta içi '.$range,
            default => null,
        };
    }

    /**
     * Whether the subject opens at all on that date (its weekday has a row in
     * force); null when it has no rows in force.
     */
    public static function opensOn(string $type, string $id, CarbonInterface $date): ?bool
    {
        $rows = self::inForce($type, $id, $date);
        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->contains(fn (OpeningHour $row) => $row->day_of_week === CarbonImmutable::instance($date)->setTimezone($row->timezone)->dayOfWeekIso);
    }

    /**
     * The whole week in words, consecutive days with the same hours grouped:
     * "Pazartesi–Perşembe 08:00–16:00; Cuma 08:00–12:00". Null without rows.
     */
    public static function describe(string $type, string $id, CarbonInterface $at, string $lang = 'tr'): ?string
    {
        $names = [
            'tr' => [1 => 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'],
            'en' => [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'ru' => [1 => 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'],
        ][$lang] ?? null;
        $byDay = [];
        foreach (self::inForce($type, $id, $at) as $row) {
            $byDay[$row->day_of_week][] = self::hm($row->opens).'–'.self::hm($row->closes);
        }
        if ($byDay === [] || $names === null) {
            return null;
        }
        ksort($byDay);
        $groups = [];
        foreach ($byDay as $day => $ranges) {
            $text = implode(', ', $ranges);
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['text'] === $text && $groups[$last]['to'] === $day - 1) {
                $groups[$last]['to'] = $day;
            } else {
                $groups[] = ['from' => $day, 'to' => $day, 'text' => $text];
            }
        }

        return implode('; ', array_map(fn ($g) => $names[$g['from']].($g['to'] !== $g['from'] ? '–'.$names[$g['to']] : '').' '.$g['text'], $groups));
    }

    private static function hm(mixed $time): string
    {
        return substr((string) $time, 0, 5);
    }
}
