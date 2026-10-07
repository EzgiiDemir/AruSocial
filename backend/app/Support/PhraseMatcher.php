<?php

namespace App\Support;

/**
 * Whole-phrase matching over FOLDED text (see TextFold), shared by every
 * place the assistant decides what a question is about: place/service
 * resolution, entity resolution and domain routing.
 *
 * It exists because `str_contains` was measured getting routing wrong in
 * both directions — `it` matched "tuition", the place `Eve` matched
 * "events", and the career keyword `iş` matched "öğrenci işleri". A phrase
 * must START at a word boundary; a short trailing inflection is tolerated
 * because Turkish agglutinates and Russian declines.
 */
final class PhraseMatcher
{
    /**
     * Longest ending tolerated after an alias stem.
     *
     * Capped so a genuine ending is tolerated while a different word is not:
     * "kütüphaneci" (+2) resolves to the library. The leading boundary, not
     * this cap, is what keeps `eve` out of "events".
     */
    public const MAX_INFLECTION = 4;

    /**
     * Aliases that are also ordinary words, and must never match on their own.
     *
     * The IT service's id is `it`, which made "is it free for students?"
     * resolve to IT Support. Only two-or-three letter tokens need listing:
     * anything longer is not a word a student uses by accident.
     *
     * @var list<string>
     */
    public const AMBIGUOUS = [
        // en
        'it', 'is', 'at', 'to', 'in', 'on', 'or', 'an', 'a', 'be', 'do', 'go',
        'my', 'me', 'we', 'so', 'no', 'up', 'if', 'of', 'as',
        // tr
        'o', 'bu', 'su', 've', 'ne', 'mi', 'mu', 'da', 'de', 'ki', 'bir',
        // ru
        'и', 'в', 'на', 'с', 'к', 'по', 'от', 'до', 'за', 'о',
    ];

    /**
     * Offset of `$alias` in `$text`, or null. Both must already be folded.
     *
     * `$minAlias` is the shortest alias that may carry an inflection. Entity
     * resolution keeps it at 5 — a wrong place card is worse than none —
     * while routing lowers it to 4 so "kulüplere" reaches `kulup` and
     * "sporu" reaches `spor`; a wrong routing guess only adds context.
     */
    public static function position(string $text, string $alias, int $minAlias = 5): ?int
    {
        if ($alias === '' || in_array($alias, self::AMBIGUOUS, true)) {
            return null;
        }

        // Exact, whole-word: always preferred, and the only thing tried for
        // very short aliases where an "ending" would double the word.
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u';
        if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $match[0][1];
        }

        if (mb_strlen($alias) < $minAlias) {
            return null;
        }

        /*
         * The alias STEM, plus whatever ending the sentence put on it.
         *
         * Turkish appends ("kütüphane" → "kütüphanede") and Russian replaces
         * the final vowel ("библиотека" → "библиотеки"). Both are covered by
         * trimming the alias's own trailing vowels first. For a multi-word
         * alias only the last word may inflect ("ogrenci isleri" →
         * "ogrenci islerine").
         */
        $stem = rtrim($alias, 'aeiouıöüяаиеоуыэюё');
        // A short word ending in a vowel ("hoca", "menu") would trim to a
        // stem too short to trust; it keeps its vowel and takes the ending
        // after it instead ("hocaya", "menude").
        if (mb_strlen($stem) < 4) {
            $stem = $alias;
        }
        $lastWord = (string) (strrchr(' '.$stem, ' ') ?: $stem);
        if (mb_strlen(trim($lastWord)) < 3 || mb_strlen($stem) < 4) {
            return null;
        }

        $inflected = '/(?<![\p{L}\p{N}])'.preg_quote($stem, '/')
            .'\p{L}{0,'.self::MAX_INFLECTION.'}(?![\p{L}\p{N}])/u';
        if (preg_match($inflected, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
            return (int) $match[0][1];
        }

        return null;
    }

    /**
     * Typo-tolerant match: every word of the alias must appear as a word of
     * the text within one edit (insert, delete, substitute or swap).
     *
     * Only words of five or more letters are compared fuzzily — at four
     * letters one edit already turns "spor" into "spot" — and shorter words
     * must match exactly. A fuzzy hit is reported separately from an exact
     * one so callers can rank it below every exact match.
     */
    public static function fuzzy(string $text, string $alias): bool
    {
        $aliasWords = self::words($alias);
        if ($aliasWords === [] || in_array($alias, self::AMBIGUOUS, true)) {
            return false;
        }
        $textWords = self::words($text);

        foreach ($aliasWords as $want) {
            $found = false;
            foreach ($textWords as $have) {
                if ($have === $want
                    || (mb_strlen($want) >= 5 && self::withinOneEdit($have, $want))) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: []));
    }

    private static function withinOneEdit(string $a, string $b): bool
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $la = count($a);
        $lb = count($b);
        if (abs($la - $lb) > 1) {
            return false;
        }

        // Optimal string alignment distance, bounded at 1. The arrays are a
        // couple of words long, so the full table is cheaper than cleverness.
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$la][$lb] <= 1;
    }
}
