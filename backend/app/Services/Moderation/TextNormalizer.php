<?php

namespace App\Services\Moderation;

/**
 * Folds raw user text — and every lexicon term — into one comparable shape.
 *
 * Everything here exists because of a real evasion seen in the labelled
 * corpus (backend/tests/fixtures/moderation_cases.jsonl): masking
 * ("s*ktir"), letter spacing ("i d i o t"), punctuation splitting
 * ("f.u.c.k."), leetspeak ("ş3r3fs1z", "bi7ch"), symbol substitution
 * ("m@l"), emoji standing in for a word ("💩"), and mixed Cyrillic/Latin
 * scripts ("кретuн", "тyп0й"). Text and lexicon run through the identical
 * pipeline, so a rule written in plain Turkish still matches its obfuscated
 * form without anyone hand-maintaining spelling variants.
 */
final class TextNormalizer
{
    /** Emoji that stand in for a word rather than decorate one. */
    private const EMOJI_TOKENS = [
        '💩' => ' xscat ',
        '🖕' => ' xfinger ',
        '😏' => ' xsmirk ',
        '😂' => ' xlaugh ',
        '🤣' => ' xlaugh ',
        '😅' => ' xlaugh ',
        '😄' => ' xgrin ',
        '😁' => ' xgrin ',
        '🔪' => ' xknife ',
        '🔫' => ' xgun ',
    ];

    /** Digits/symbols used as letters. Applied before alphabet folding. */
    private const LEET = [
        '@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a',
        '5' => 's', '7' => 't', '$' => 's', '!' => 'i', '€' => 'e',
    ];

    /** Turkish diacritics → ASCII, so one lexicon entry covers both. */
    private const TURKISH = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u',
    ];

    /**
     * Cyrillic characters with a Latin twin, folded to the Latin side. Both
     * "тупой" and the mixed-script "тyп0й" converge on the same string, so
     * neither needs its own lexicon entry.
     */
    private const CYRILLIC_LOOKALIKE = [
        'а' => 'a', 'е' => 'e', 'ё' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c',
        'х' => 'x', 'у' => 'y', 'к' => 'k', 'н' => 'h', 'в' => 'b', 'м' => 'm',
        'т' => 't', 'і' => 'i', 'ѕ' => 's',
        // Not a strict lookalike, but the substitution people actually
        // make: "пиzдец" typed with a Latin z. Folding both sides of the
        // comparison the same way is what makes the swap harmless.
        'з' => 'z',
    ];

    private const MASKING_CHARS = ['*', '#', '•', '×', '¤'];

    private const QUOTE_PAIRS = [
        ['«', '»'], ['“', '”'], ['‘', '’'], ['"', '"'], ["'", "'"], ['„', '”'],
    ];

    public function normalize(string $text): NormalizedText
    {
        $lower = $this->foldCase($text);
        $obfuscations = [];

        $hasMention = (bool) preg_match('/@\p{L}[\p{L}\p{N}_]*/u', $lower);
        $quotedRaw = $this->extractQuoted($lower);

        // Strip invisible characters used purely to break up words.
        $work = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{180E}]/u', '', $lower) ?? $lower;

        foreach (self::EMOJI_TOKENS as $emoji => $token) {
            if (str_contains($work, $emoji)) {
                $obfuscations[] = 'emoji_substitution';
                $work = str_replace($emoji, $token, $work);
            }
        }

        if ($this->containsAny($work, self::MASKING_CHARS)) {
            $obfuscations[] = 'masking';
        }
        $work = str_replace(self::MASKING_CHARS, '', $work);

        // "f.u.c.k." / "п.о.ш.ё.л." — separators *between* letters only, so
        // ordinary sentence punctuation is left alone.
        $rejoined = preg_replace('/(?<=\p{L})[.\-_](?=\p{L})/u', '', $work) ?? $work;
        if ($rejoined !== $work) {
            $obfuscations[] = 'punctuation_split';
        }
        $work = $rejoined;

        if (preg_match('/[\p{L}][@0134579$!](?=[\p{L}])/u', $work)) {
            $obfuscations[] = 'leetspeak';
        }
        $work = strtr($work, self::LEET);
        $work = strtr($work, self::TURKISH);

        $mixedScript = $this->hasMixedScript($work);
        if ($mixedScript) {
            $obfuscations[] = 'mixed_script';
        }
        $work = strtr($work, self::CYRILLIC_LOOKALIKE);

        // Everything that is not a letter or digit becomes a separator.
        $work = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $work) ?? $work;
        $work = trim(preg_replace('/\s+/u', ' ', $work) ?? $work);

        // "g e r i z e k a l ı" — three or more single characters in a row
        // are a spelled-out word, not three words.
        $tokens = $work === '' ? [] : explode(' ', $work);
        $joined = $this->joinSpelledOutRuns($tokens);
        if ($joined !== $tokens) {
            $obfuscations[] = 'spacing';
        }
        $tokens = $joined;
        $work = implode(' ', $tokens);

        // "saaaalak" → "salak". Two repeats stay (kill, will, hall).
        $work = preg_replace('/(.)\1{2,}/u', '$1', $work) ?? $work;
        $tokens = $work === '' ? [] : explode(' ', $work);

        $quoted = array_values(array_filter(array_map(
            fn (string $segment): string => $this->canonicalizeFragment($segment),
            $quotedRaw,
        )));

        return new NormalizedText(
            original: $text,
            canonical: $work,
            compact: str_replace(' ', '', $work),
            tokens: $tokens,
            quoted: $quoted,
            obfuscations: array_values(array_unique($obfuscations)),
            hasMention: $hasMention,
            suspicious: $obfuscations !== [],
        );
    }

    /**
     * Lowercase, then drop the combining marks lowercasing can leave behind.
     *
     * Turkish "İ" lowercases to "i" followed by U+0307 COMBINING DOT ABOVE.
     * The tokenizer treats a non-letter as a word separator, so "İBNE"
     * became the two tokens "i" and "bne" and matched nothing — anyone
     * typing in caps lock walked straight past the filter, for every
     * Turkish word beginning with İ, not just this one. Stripping the
     * U+0300–U+036F block keeps the letter and loses the mark.
     */
    private function foldCase(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');

        return preg_replace('/\p{Mn}/u', '', $lower) ?? $lower;
    }

    /** Folds a lexicon term through the same pipeline as user text. */
    public function canonicalizeFragment(string $fragment): string
    {
        $work = $this->foldCase($fragment);
        $work = str_replace(self::MASKING_CHARS, '', $work);
        $work = strtr($work, self::LEET);
        $work = strtr($work, self::TURKISH);
        $work = strtr($work, self::CYRILLIC_LOOKALIKE);
        $work = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $work) ?? $work;
        $work = trim(preg_replace('/\s+/u', ' ', $work) ?? $work);

        return preg_replace('/(.)\1{2,}/u', '$1', $work) ?? $work;
    }

    /**
     * Consonant skeleton — only consulted when the text already showed a
     * masking attempt, so "f*ck" can reach "fuck" without "fck" quietly
     * matching unrelated words in clean text.
     */
    /**
     * "sikktir" → "siktir". Normalisation keeps doubled letters on purpose
     * (kill, hall, добби), so callers that want them gone ask for it.
     */
    public function collapseDoubles(string $value): string
    {
        return preg_replace('/(.)\1+/u', '$1', $value) ?? $value;
    }

    public function skeleton(string $value): string
    {
        return preg_replace('/[aeiouyıöüаеёиоуыэюя]/u', '', $value) ?? $value;
    }

    /** Multibyte-safe edit distance, for near-miss script swaps. */
    public function editDistance(string $a, string $b): int
    {
        $aChars = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bChars = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $aLen = count($aChars);
        $bLen = count($bChars);
        if ($aLen === 0 || $bLen === 0) {
            return max($aLen, $bLen);
        }
        if (abs($aLen - $bLen) > 2) {
            return 99;
        }

        $prev = range(0, $bLen);
        for ($i = 1; $i <= $aLen; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $bLen; $j++) {
                $cost = $aChars[$i - 1] === $bChars[$j - 1] ? 0 : 1;
                $current[$j] = min($prev[$j] + 1, $current[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $current;
        }

        return $prev[$bLen];
    }

    /** @return list<string> */
    private function extractQuoted(string $text): array
    {
        $found = [];
        foreach (self::QUOTE_PAIRS as [$open, $close]) {
            $pattern = '/'.preg_quote($open, '/').'(.{1,120}?)'.preg_quote($close, '/').'/u';
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $segment) {
                    $found[] = $segment;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function joinSpelledOutRuns(array $tokens): array
    {
        $out = [];
        $run = [];
        $flush = function () use (&$run, &$out): void {
            if (count($run) >= 3) {
                $out[] = implode('', $run);
            } else {
                foreach ($run as $single) {
                    $out[] = $single;
                }
            }
            $run = [];
        };

        foreach ($tokens as $token) {
            if (mb_strlen($token) === 1 && preg_match('/\p{L}/u', $token)) {
                $run[] = $token;

                continue;
            }
            $flush();
            $out[] = $token;
        }
        $flush();

        return $out;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasMixedScript(string $text): bool
    {
        foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
            if (mb_strlen($token) < 4) {
                continue;
            }
            $hasCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $token);
            $hasLatin = (bool) preg_match('/[a-z]/u', $token);
            if ($hasCyrillic && $hasLatin) {
                return true;
            }
        }

        return false;
    }
}
