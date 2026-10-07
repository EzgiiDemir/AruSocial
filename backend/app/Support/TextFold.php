<?php

namespace App\Support;

/**
 * Fold text to a diacritic-free, lowercase form for matching.
 *
 * Students type "kutuphane", "ogrenci", "basvuru" and "ucret" far more
 * often than they type "kütüphane", "öğrenci", "başvuru" and "ücret" —
 * phone keyboards, habit, and English layouts. Matched literally, those
 * queries hit nothing, and the measured result was that "kutuphane"
 * returned an exam-transfer page while the library page it obviously
 * meant was nowhere in the results.
 *
 * This is for MATCHING only. Nothing folded here is ever shown to a
 * student or handed to the model as content — the original text is what
 * gets displayed and quoted.
 *
 * Turkish `ı`/`i` are deliberately folded together. They are different
 * letters and folding them is wrong as Turkish, but a search index that
 * insists on the distinction fails the person typing on a keyboard that
 * cannot produce one of them.
 */
final class TextFold
{
    /** @var array<string, string> */
    private const REPLACEMENTS = [
        // Turkish
        'ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i', 'û' => 'u', 'Û' => 'u',
        // Latin diacritics that turn up in names and loanwords
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e',
        'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ô' => 'o',
        'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'ñ' => 'n', 'ß' => 'ss',
    ];

    public static function fold(string $text): string
    {
        // Lowercase first, in Turkish-aware fashion: mb_strtolower maps
        // 'I' to 'i' and 'İ' to 'i̇' (with a combining dot), so the
        // replacements below run afterwards to flatten what is left.
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, self::REPLACEMENTS);

        // The combining dot above, left behind by lowercasing 'İ'.
        return str_replace("\u{0307}", '', $text);
    }
}
