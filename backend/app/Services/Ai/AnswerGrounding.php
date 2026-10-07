<?php

namespace App\Services\Ai;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Support\RequestMemo;
use App\Support\TextFold;
use Illuminate\Support\Facades\Schema;

/**
 * Checks that the checkable claims in an answer actually came from the sources.
 *
 * Prompt rules do not reliably hold. Asked about scholarships, the model
 * produced monthly amounts ("12.000 TL/ay"), a source URL
 * (arucad.edu.tr/burslar/) and a contact address (burs@arucad.edu.tr) — none of
 * which existed in any indexed page. A student could act on any of those.
 *
 * So the three claim types that are both high-harm and exactly verifiable are
 * checked against the text the model was actually given: links, e-mail
 * addresses and money/percentage figures. Anything else (prose, reasoning,
 * advice) is left alone — this is a grounding check, not a style filter.
 */
class AnswerGrounding
{
    /**
     * Claims in $answer that do not appear in $source.
     *
     * @return array{urls: list<string>, emails: list<string>, figures: list<string>, names: list<string>}
     */
    public function ungrounded(string $answer, string $source): array
    {
        return [
            'urls' => $this->ungroundedUrls($answer, $source),
            'emails' => $this->ungroundedEmails($answer, $source),
            'figures' => $this->ungroundedFigures($answer, $source),
            'names' => array_values(array_unique(array_merge(
                $this->ungroundedNames($answer, $source),
                $this->ungroundedNamedPlaces($answer, $source),
            ))),
            'dates' => $this->ungroundedDates($answer, $source),
            'facts' => $this->contradictedFacts($answer),
        ];
    }

    /**
     * Remove the sentences that carry an unsupported claim, when what is
     * left is still a useful, fully grounded answer.
     *
     * Strict by default (one sentence removed, three remaining): used before
     * regenerating, where it saves a second 10-15 s model call — measured as
     * the cause of the p95 full-answer latency — without accepting anything
     * unverified. Looser (up to half removed, two remaining) only after a
     * corrective regeneration has also failed, where the alternative is
     * withholding the whole answer.
     */
    public function salvage(string $answer, string $source, bool $afterRetry = false): ?string
    {
        $report = $this->ungrounded($answer, $source);
        if ($this->isClean($report)) {
            return null;
        }
        $claims = array_map(fn ($c) => TextFold::fold((string) $c), $this->claims($report));
        $sentences = preg_split('/(?<=[.!?…])\s+|\n+/u', trim($answer), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];
        $removed = 0;
        foreach ($sentences as $sentence) {
            $folded = TextFold::fold($sentence);
            $bad = false;
            foreach ($claims as $claim) {
                if ($claim !== '' && str_contains($folded, $claim)) {
                    $bad = true;
                    break;
                }
            }
            $bad ? $removed++ : $kept[] = trim($sentence);
        }

        $ok = $afterRetry
            ? $removed > 0 && $removed * 2 <= count($sentences) && count($kept) >= 2
            : $removed === 1 && count($kept) >= 3;
        if (! $ok) {
            return null;
        }
        $result = implode(' ', $kept);

        return $this->isClean($this->ungrounded($result, $source)) ? $result : null;
    }

    /** @return list<string> every flagged claim, as written in the answer */
    private function claims(array $report): array
    {
        return array_merge(
            $report['urls'], $report['emails'], $report['figures'],
            $report['names'] ?? [], $report['dates'] ?? [],
            array_column($report['facts'] ?? [], 'subject'),
        );
    }

    /**
     * Named institutions and buildings the answer asserts that no source
     * contains: "Eastern Mediterranean University", "Hamlet Binası". The
     * all-caps check below catches acronyms (ЕГЭ, YKS); these are written in
     * title case and slipped past it.
     *
     * @return list<string>
     */
    private function ungroundedNamedPlaces(string $answer, string $source): array
    {
        $kinds = 'University|Üniversitesi|Universitesi|Institute|Enstitüsü|College|Academy|Akademisi|Ministry|Bakanlığı'
            .'|Building|Binası|Kampüsü|Campus|Университет|Институт';
        if (preg_match_all('/((?:\p{Lu}[\p{L}’\'.-]+\s+){1,5}(?:'.$kinds.'))/u', $answer, $m) === false) {
            return [];
        }
        $haystack = TextFold::fold($source);
        $out = [];
        foreach (array_unique($m[1]) as $name) {
            // A capitalised sentence opener is not part of the name.
            $name = trim((string) preg_replace('/^(?:'.self::SENTENCE_STARTERS.')\s+/iu', '', trim($name)));
            if (! str_contains($haystack, TextFold::fold($name))) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Sentences that contradict a trusted structured fact: a programme named
     * with the other language of instruction than its extracted fact says.
     * Facts come from KnowledgeFactExtractor (Turkish programme names).
     *
     * @return list<array{subject: string, fact: string}>
     */
    private function contradictedFacts(string $answer): array
    {
        if (! Schema::hasTable('knowledge_facts')) {
            return [];
        }
        $languages = [
            'İngilizce' => ['ingilizce', 'english', 'английск'],
            'Türkçe' => ['turkce', 'turkish', 'турецк'],
        ];
        $facts = app(RequestMemo::class)->remember('knowledge_facts:language', fn () => KnowledgeFact::query()
            ->where('attribute', KnowledgeFact::LANGUAGE)
            ->get(['subject', 'subject_folded', 'value'])->unique('subject_folded'));
        if ($facts->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (preg_split('/(?<=[.!?…])\s+|\n+/u', $answer, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
            $folded = TextFold::fold($sentence);
            foreach ($facts as $fact) {
                if (! str_contains($folded, (string) $fact->subject_folded) || ! isset($languages[$fact->value])) {
                    continue;
                }
                $own = $this->mentionsAny($folded, $languages[$fact->value]);
                foreach ($languages as $value => $words) {
                    if ($value !== $fact->value && $this->mentionsAny($folded, $words) && ! $own) {
                        $out[] = ['subject' => $fact->subject, 'fact' => $fact->value];
                    }
                }
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * Public view of the trusted-fact check, for evaluation assertions.
     *
     * @return list<array{subject: string, fact: string}>
     */
    public function factContradictions(string $answer): array
    {
        return $this->contradictedFacts($answer);
    }

    /** @param list<string> $words */
    private function mentionsAny(string $folded, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($folded, $word)) {
                return true;
            }
        }

        return false;
    }

    /** True when nothing checkable was invented. */
    public function isClean(array $report): bool
    {
        return $report['urls'] === [] && $report['emails'] === []
            && $report['figures'] === [] && ($report['names'] ?? []) === []
            && ($report['dates'] ?? []) === [] && ($report['facts'] ?? []) === [];
    }

    /** A flat list for logging and for telling the model what it got wrong. */
    public function describe(array $report): string
    {
        return implode(', ', array_merge(
            $report['urls'],
            $report['emails'],
            $report['figures'],
            $report['names'] ?? [],
            $report['dates'] ?? [],
            array_map(fn ($f) => $f['subject'].' (eğitim dili: '.$f['fact'].')', $report['facts'] ?? []),
        ));
    }

    /**
     * Named institutions and systems the answer asserts that appear nowhere
     * in what it was given.
     *
     * Measured on real questions: asked whether ARUCAD is a state university
     * the model answered that it is governed by "Arkın Eğitim Kültür ve
     * Araştırma Vakfı (AREKAV)", and asked about dormitories it said to apply
     * "AYDIN sistemi üzerinden". Neither exists. Both answers had seven
     * retrieved sources, so every earlier check passed: the TOPIC was
     * grounded and the CLAIM was invented.
     *
     * An acronym is the right thing to check because it is unambiguous — a
     * student cannot tell an invented institution from a real one, and will
     * go looking for a system that is not there. Prose is still left alone.
     *
     * Anything genuinely ours is already in the prompt this is checked
     * against: the institution profile names ARUCAD, YÖK, KKTC, İLAD and the
     * rest, and the retrieved pages carry the others.
     *
     * @return list<string>
     */
    private function ungroundedNames(string $answer, string $source): array
    {
        /*
         * \p{Lu} rather than a literal [A-ZÇĞİÖŞÜ] class.
         *
         * The literal class silently matched nothing once the file had been
         * through the formatter, while the identical pattern typed at a
         * prompt worked — an encoding difference in the source that cost an
         * hour to find and would have come back. A Unicode property has no
         * bytes to corrupt and covers every alphabet the site uses.
         *
         * Three or more capitals: two-letter forms are too often initials or
         * ordinary words to be worth the false positives.
         */
        if (preg_match_all('/\p{Lu}{3,}/u', $answer, $matches) === false || $matches[0] === []) {
            return [];
        }

        $haystack = TextFold::fold($source);
        $out = [];
        foreach (array_unique($matches[0]) as $name) {
            $folded = TextFold::fold($name);
            if ($folded === '' || in_array($folded, self::COMMON_ACRONYMS, true)) {
                continue;
            }
            if (! str_contains($haystack, $folded)) {
                $out[$name] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Acronyms that are never worth flagging.
     *
     * Either they are about the answer's own formatting rather than a claim
     * about the world, or they are so widely known that inventing them is
     * not a failure mode.
     */
    private const SENTENCE_STARTERS = 'Ayrıca|Ancak|Ama|Bu|Şu|Ve|Veya|Örneğin|Also|However|The|In|At|For|And|Or|Its|This|Также|Однако|В';

    private const COMMON_ACRONYMS = [
        'pdf', 'url', 'http', 'https', 'www', 'html', 'api', 'sms', 'pcr',
        'cev', 'not', 'ders', 'ocak', 'subat', 'mart', 'nisan', 'mayis',
        'haziran', 'temmuz', 'agustos', 'eylul', 'ekim', 'kasim', 'aralik',
        'usd', 'eur', 'try', 'gbp', 'tel', 'faks', 'fax',
    ];

    /**
     * Calendar dates the answer states that no source states.
     *
     * A date is the claim a student is most likely to act on and least able
     * to sanity-check. Measured: told that the only exam timetable we held
     * was last year's, the model did not conclude that the new one was
     * unpublished — it answered "2026-2027 final sınavları 2026-09-01'den
     * itibaren başlamıştır", a date that exists in no source. That is worse
     * than the stale-but-real date it replaced, and three attempts at
     * fixing it with prompt wording did not hold.
     *
     * Bare years are deliberately not checked: "2026-2027 akademik yılı" is
     * a reference, not a claim about when something happens.
     *
     * @return list<string>
     */
    private function ungroundedDates(string $answer, string $source): array
    {
        $months = 'Ocak|Şubat|Mart|Nisan|Mayıs|Haziran|Temmuz|Ağustos|Eylül'
            .'|Ekim|Kasım|Aralık|January|February|March|April|May|June|July'
            .'|August|September|October|November|December';

        $patterns = [
            // 21.01.2026, 21/01/2026, 21-01-2026
            '~\b\d{1,2}[./-]\d{1,2}[./-]\d{2,4}\b~u',
            // 2026-09-01
            '~\b\d{4}-\d{1,2}-\d{1,2}\b~u',
            // 21 Ocak 2026
            '~\b\d{1,2}\s+(?:'.$months.')\s+\d{4}\b~iu',
        ];

        $haystack = $this->digitsOnly($source);

        /*
         * The same day written differently is still the same day.
         *
         * The calendar tool gives "2025-09-01"; the model rightly writes
         * "1 Eylül 2025". Compared as digit runs ("1 2025" in "2025 09 01")
         * they never matched, so a correct, grounded date was rejected twice
         * and the student got the fallback instead. Every date on both sides
         * is normalised to Y-m-d first; the digit check stays as a fallback
         * for anything the normaliser cannot read.
         */
        $sourceDays = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $s);
            foreach ($s[0] as $date) {
                $day = $this->canonicalDate($date);
                if ($day !== null) {
                    $sourceDays[$day] = true;
                }
            }
        }

        $bad = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $answer, $m) === false) {
                continue;
            }
            foreach (array_unique($m[0]) as $date) {
                $day = $this->canonicalDate($date);
                if ($day !== null && isset($sourceDays[$day])) {
                    continue;
                }
                if (! str_contains($haystack, $this->digitsOnly($date))) {
                    $bad[] = trim($date);
                }
            }
        }

        return array_values(array_unique($bad));
    }

    /** "21.01.2026", "2026-01-21" and "21 Ocak 2026" all become "2026-01-21"; null if unreadable. */
    private function canonicalDate(string $date): ?string
    {
        $months = [
            'ocak' => 1, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayis' => 5, 'haziran' => 6,
            'temmuz' => 7, 'agustos' => 8, 'eylul' => 9, 'ekim' => 10, 'kasim' => 11, 'aralik' => 12,
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
            'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        ];
        $folded = TextFold::fold(trim($date));

        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $folded, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('~^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})$~', $folded, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y = $y < 100 ? 2000 + $y : $y;
        } elseif (preg_match('~^(\d{1,2})\s+(\p{L}+)\s+(\d{4})$~u', $folded, $m) && isset($months[$m[2]])) {
            [$d, $mo, $y] = [(int) $m[1], $months[$m[2]], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Digits only, so the same day written 21.01.2026, 21/01/2026 and
     * 2026-01-21 compares equal. Crude on purpose: the question is whether
     * those numbers appear in the source at all, not whether the two
     * formats agree.
     */
    private function digitsOnly(string $text): string
    {
        return (string) preg_replace('/[^0-9]+/u', ' ', $text);
    }

    /** @return list<string> */
    private function ungroundedUrls(string $answer, string $source): array
    {
        preg_match_all('~https?://[^\s<>"\'\)\],]+~i', $answer, $m);

        $bad = [];
        foreach (array_unique($m[0]) as $url) {
            $normalized = rtrim($url, '.,;:');
            if (str_contains($source, $normalized) || str_contains($source, rtrim($normalized, '/'))) {
                continue;
            }
            // A link we did not put in the prompt is still fine if it is a page
            // we have actually indexed.
            if (KnowledgeDocument::query()
                ->where('url', $normalized)
                ->orWhere('url', rtrim($normalized, '/').'/')
                ->exists()) {
                continue;
            }
            $bad[] = $normalized;
        }

        return $bad;
    }

    /** @return list<string> */
    private function ungroundedEmails(string $answer, string $source): array
    {
        preg_match_all('~[\w.+-]+@[\w-]+\.[\w.-]+~u', $answer, $m);

        $bad = [];
        foreach (array_unique($m[0]) as $email) {
            $normalized = rtrim(mb_strtolower($email), '.,;:');
            if (str_contains(mb_strtolower($source), $normalized)) {
                continue;
            }
            $bad[] = $normalized;
        }

        return $bad;
    }

    /**
     * Money and percentage figures the sources never stated.
     *
     * Only figures carrying a currency or percent marker are checked: bare
     * numbers are usually counts or dates the model may legitimately phrase
     * itself, and flagging those produced noise without catching harm.
     *
     * @return list<string>
     */
    private function ungroundedFigures(string $answer, string $source): array
    {
        $known = $this->numbersIn($source);

        $patterns = [
            '~\d[\d.,]*\s*(?:TL|₺|USD|EUR|\$|€|lira)~iu',   // 12.000 TL
            '~(?:TL|₺|USD|EUR|\$|€)\s*\d[\d.,]*~iu',        // ₺12.000
            '~%\s*\d[\d.,]*~u',                              // %50
            '~\d[\d.,]*\s*%~u',                              // 50%
        ];

        $bad = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $answer, $m);
            foreach (array_unique($m[0]) as $figure) {
                $digits = $this->normalizeNumber($figure);
                if ($digits === '' || in_array($digits, $known, true)) {
                    continue;
                }
                $bad[] = trim($figure);
            }
        }

        return array_values(array_unique($bad));
    }

    /**
     * Every number in a text, normalised to bare digits so "12.000", "12,000"
     * and "12000" all compare equal.
     *
     * @return list<string>
     */
    private function numbersIn(string $text): array
    {
        preg_match_all('~\d[\d.,]*~u', $text, $m);

        $numbers = [];
        foreach ($m[0] as $token) {
            $digits = $this->normalizeNumber($token);
            if ($digits !== '') {
                $numbers[] = $digits;
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * Reduce a written number to a canonical value, so the same amount written
     * differently compares equal: "65.000,00", "65,000.00" and "65000" all
     * become "65000".
     *
     * A trailing group of one or two digits after the last separator is a
     * decimal fraction; three digits is a thousands group. A fraction of zeros
     * carries no information and is dropped.
     */
    private function normalizeNumber(string $token): string
    {
        $digits = (string) preg_replace('~[^\d.,]~u', '', $token);
        if ($digits === '') {
            return '';
        }

        $fraction = '';
        if (preg_match('~^(.*)[.,](\d{1,2})$~', $digits, $m) === 1) {
            $digits = $m[1];
            $fraction = rtrim($m[2], '0');
        }

        $whole = ltrim((string) preg_replace('~\D~u', '', $digits), '0');
        if ($whole === '') {
            $whole = '0';
        }

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
