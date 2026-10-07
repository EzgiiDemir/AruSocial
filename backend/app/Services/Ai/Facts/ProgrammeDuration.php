<?php

namespace App\Services\Ai\Facts;

use App\Support\TextFold;

/**
 * A programme length in its normalised form — "4y" (four years), "1-2s"
 * (one to two semesters), as FactValidator writes it — said in a language,
 * or compared with the length a sentence states.
 */
final class ProgrammeDuration
{
    private const NUMBER_WORDS = ['bir' => 1, 'iki' => 2, 'uc' => 3, 'dort' => 4, 'bes' => 5, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];

    /** "4 yıl", "4 years", "4 года", "1–2 yarıyıl"; null for a value not in normalised form. */
    public static function phrase(string $normalized, string $lang): ?string
    {
        if (! preg_match('/^(\d)(?:-(\d))?(y|s)$/', $normalized, $m)) {
            return null;
        }
        $n = $m[2] !== '' ? $m[1].'–'.$m[2] : $m[1];
        $last = (int) ($m[2] !== '' ? $m[2] : $m[1]);

        return $n.' '.match ($lang) {
            'en' => $m[3] === 'y' ? ($last === 1 && $m[2] === '' ? 'year' : 'years') : ($last === 1 && $m[2] === '' ? 'semester' : 'semesters'),
            'ru' => $m[3] === 'y' ? self::russianYears($last) : ($last === 1 ? 'семестр' : ($last <= 4 ? 'семестра' : 'семестров')),
            default => $m[3] === 'y' ? 'yıl' : 'yarıyıl',
        };
    }

    /** Whether a stated length ("4 yıllık", "four years", "4 года") is this normalised year length. */
    public static function sameLength(string $normalized, string $stated): bool
    {
        if (! preg_match('/^(\d)y$/', $normalized, $m)) {
            return false;
        }
        $first = strtok(TextFold::fold(trim($stated)), " \t-");
        $n = ctype_digit((string) $first) ? (int) $first : (self::NUMBER_WORDS[$first] ?? null);

        return $n === (int) $m[1];
    }

    private static function russianYears(int $n): string
    {
        return match (true) {
            $n % 10 === 1 && $n % 100 !== 11 => 'год',
            in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true) => 'года',
            default => 'лет',
        };
    }
}
