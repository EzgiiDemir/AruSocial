<?php

namespace App\Services\Knowledge;

/**
 * Minimal, dependency-free HTML → readable-text extraction.
 *
 * Deliberately not a full DOM/readability library: the crawler only needs the
 * visible words of a WordPress page for the AI to read, so a small, robust
 * strip is preferable to adding a parsing dependency. Everything here is pure
 * string work over the raw markup.
 */
class HtmlTextExtractor
{
    /** Extract the readable body text, scripts/styles/markup removed. */
    public static function extract(string $html): string
    {
        // Remove whole non-content elements first, contents included.
        $html = preg_replace('#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Common WordPress chrome that is navigation, not content.
        $html = preg_replace('#<(nav|header|footer|form)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        // Block elements become line breaks so words do not run together.
        $html = preg_replace('#<(/?)(p|div|li|br|h[1-6]|tr|section|article)\b[^>]*>#i', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse whitespace; keep paragraph breaks readable.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    public static function title(string $html): ?string
    {
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return $title !== '' ? mb_substr($title, 0, 250) : null;
        }

        return null;
    }

    /**
     * Absolute, same-scheme links found in the markup, for discovery.
     *
     * @return list<string>
     */
    public static function links(string $html, string $baseUrl): array
    {
        // Delimiter is ~ (not #) precisely because the character class below
        // excludes '#' to drop fragments — a '#' delimiter would terminate
        // the pattern there and raise "Unknown modifier".
        if (! preg_match_all('~<a\b[^>]*href=["\']([^"\'#]+)["\']~i', $html, $m) || empty($m[1])) {
            return [];
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $out = [];

        foreach ($m[1] as $href) {
            $href = trim($href);
            if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
                || str_starts_with($href, 'javascript:')) {
                continue;
            }
            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                $out[] = $href;
            } elseif (str_starts_with($href, '//')) {
                $out[] = $scheme.':'.$href;
            } elseif (str_starts_with($href, '/')) {
                $out[] = $scheme.'://'.$host.$href;
            }
            // Relative-without-slash links are rare on these WP sites and
            // skipped rather than resolved imperfectly.
        }

        return array_values(array_unique($out));
    }
}
