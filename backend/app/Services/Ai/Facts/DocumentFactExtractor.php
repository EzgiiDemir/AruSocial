<?php

namespace App\Services\Ai\Facts;

use App\Support\TextFold;

/**
 * Fact-type-specific extraction from untrusted document passages. Each
 * extractor knows exactly which fact it is trying to establish and for
 * which subject, and returns a value only when the passage STATES it —
 * relevance (Phase 3B's cue words) is not enough.
 *
 * Deterministic on purpose: the two document-backed fact types AICAD needs
 * today have regular official phrasing ("Eğitim Dili: İngilizce",
 * "Gerekli belgeler: …"), so no model call is spent, and a passage cannot
 * talk an extractor into a different fact type, subject or source — the
 * only output is a value from a fixed shape, or nothing.
 */
final class DocumentFactExtractor
{
    /** Fact types this class can extract from passages. */
    public const TYPES = ['program_language', 'required_documents'];

    private const SPAN_CHARS = 240;

    /**
     * Language-of-instruction statements, with the stated language captured.
     * `[iİ]` is spelled out: under /iu PCRE does not fold Turkish İ (U+0130)
     * to i, so "İngilizce" and "EĞİTİM DİLİ" would silently never match.
     *
     * @var list<string>
     */
    private const LANGUAGE_PATTERNS = [
        '/(?:e[ğĞ][iİ]t[iİ]m|[öÖ][ğĞ]ret[iİ]m)\s+d[iİ]l[iİ]\s*[:\-–]?\s*([iİ]ng[iİ]l[iİ]zce|t[üÜ]rk[çÇ]e)/iu',
        '/language\s+of\s+instruction\s*(?:is|:|-|–)?\s*(english|turkish)/iu',
        '/(?:taught|offered|delivered)\s+(?:entirely\s+|fully\s+)?in\s+(english|turkish)/iu',
        '/язык\s+обучения\s*[:\-–]?\s*(английский|турецкий)/iu',
    ];

    private const LANGUAGE_CODES = [
        'ingilizce' => 'en', 'english' => 'en', 'английский' => 'en',
        'turkce' => 'tr', 'turkish' => 'tr', 'турецкий' => 'tr',
    ];

    /** A list of required documents is introduced by one of these, then a colon. */
    private const DOCUMENT_HEADERS = '/(?:kay[ıI]t\s+[iİ]ç[iİ]n\s+)?(?:gerekl[iİ]|[iİ]stenen|[iİ]stenilen)\s+(?:belgeler|evraklar)(?:\s+şunlardır)?|kay[ıI]t\s+belgeler[iİ]|required\s+documents|documents\s+required|necessary\s+documents|необходимые\s+документы|требуемые\s+документы/iu';

    private const MAX_ITEMS = 12;

    /**
     * @param  list<string>|null  $subjectNames  folded names the passage must attribute the fact to (null: no subject to check)
     * @return array{value: mixed, normalized: string, span: string}|array{none: string}
     */
    public function extract(string $factType, string $passage, ?string $title, ?array $subjectNames): array
    {
        return match ($factType) {
            'program_language' => $this->language($passage, $title, $subjectNames),
            'required_documents' => $this->documents($passage),
            default => ['none' => 'no extractor for '.$factType],
        };
    }

    /** @return array{value: mixed, normalized: string, span: string}|array{none: string} */
    private function language(string $passage, ?string $title, ?array $subjectNames): array
    {
        if ($subjectNames === null || $subjectNames === []) {
            // Without a known programme the language cannot be attributed to anything.
            return ['none' => 'no programme subject to attribute the language to'];
        }
        $haystack = TextFold::fold(($title ?? '').' '.$passage);
        if (! collect($subjectNames)->contains(fn (string $name) => $name !== '' && str_contains($haystack, $name))) {
            return ['none' => 'passage does not name the programme'];
        }

        $found = [];
        foreach (self::LANGUAGE_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $passage, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $i => [$word]) {
                    $code = self::LANGUAGE_CODES[TextFold::fold($word)] ?? null;
                    if ($code !== null) {
                        $found[$code] ??= ['word' => $word, 'span' => $this->span($passage, (int) $m[0][$i][1], strlen($m[0][$i][0]))];
                    }
                }
            }
        }
        if ($found === []) {
            return ['none' => 'passage does not state a language of instruction'];
        }
        if (count($found) > 1) {
            // One passage stating both languages (a page listing several
            // programmes) cannot attribute either to this one.
            return ['none' => 'passage states more than one language of instruction'];
        }
        $code = array_key_first($found);

        return ['value' => $code === 'en' ? 'İngilizce' : 'Türkçe', 'normalized' => $code, 'span' => $found[$code]['span']];
    }

    /** @return array{value: mixed, normalized: string, span: string}|array{none: string} */
    private function documents(string $passage): array
    {
        if (! preg_match(self::DOCUMENT_HEADERS, $passage, $header, PREG_OFFSET_CAPTURE)) {
            return ['none' => 'passage introduces no list of required documents'];
        }
        $after = mb_substr($passage, mb_strlen(substr($passage, 0, (int) $header[0][1])) + mb_strlen($header[0][0]));
        if (! preg_match('/^\s*[:：]\s*/u', $after, $colon)) {
            return ['none' => 'documents are mentioned but not listed'];
        }
        $list = mb_substr($after, mb_strlen($colon[0]));
        // The list ends at the end of its sentence.
        $list = preg_split('/(?<=[.!?])\s+(?=\p{Lu})|\n\s*\n/u', $list, 2)[0] ?? '';
        $list = rtrim(mb_substr($list, 0, 600), " \t.;");

        $items = [];
        foreach (preg_split('/\s*(?:[,;•·]|\n|\s(?:ve|and|и)\s|\(?\d+[.)]\s)\s*/u', $list) ?: [] as $item) {
            $item = trim($item, " \t-–—*.:");
            $words = preg_split('/\s+/u', $item, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($item !== '' && count($words) <= 8 && mb_strlen($item) <= 80 && preg_match('/\p{L}{2,}/u', $item)) {
                $items[] = $item;
            }
        }
        $items = array_slice(array_values(array_unique($items)), 0, self::MAX_ITEMS);
        if ($items === []) {
            return ['none' => 'no list items after the documents header'];
        }

        return ['value' => $items, 'normalized' => implode('|', array_map(fn ($i) => TextFold::fold($i), $items)),
            'span' => $this->span($passage, (int) $header[0][1], strlen($header[0][0]) + strlen($list) + 2)];
    }

    /** The minimal excerpt around a byte offset — provenance, not a copy of the document. */
    private function span(string $passage, int $byteOffset, int $byteLength): string
    {
        $start = mb_strlen(substr($passage, 0, $byteOffset));
        $length = mb_strlen(substr($passage, $byteOffset, $byteLength));

        return mb_strimwidth(trim(mb_substr($passage, $start, max($length, 1))), 0, self::SPAN_CHARS, '…');
    }
}
