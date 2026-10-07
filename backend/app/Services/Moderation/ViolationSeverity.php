<?php

namespace App\Services\Moderation;

/**
 * How bad a violation is, from the category that was detected.
 *
 * One map, read by every path that can charge an account: the content
 * gate, the media classifier and a moderator confirming a report
 * (`ReportReason::severityIfConfirmed()` returns the same four bands and
 * agrees with the rows below on purpose). Spam and a credible threat
 * cannot cost the same, and they cannot cost different amounts depending
 * on which door the violation came through.
 *
 * The bands live in `config/moderation.php` so the rules change without a
 * code change — same reason the old strike ladder lived in config.
 *
 * Category codes come from three vocabularies and all three are matched
 * here, lowercased:
 *
 *   - the offline lexicon and text classifier: THR, HAR, SEX, HATE, …
 *   - the OpenAI-compatible provider: `sexual/minors`, `hate/threatening`, …
 *   - the image classifier: `nsfw`, `clip_nudity`, `clip_gore`, …
 *
 * An unrecognised code is `minor`, never nothing: a category nobody has
 * mapped is still a refusal that happened, and silently charging zero for
 * it would make the gap invisible.
 */
final class ViolationSeverity
{
    /** Ordered worst-first — `highest()` returns the first band it finds. */
    private const ORDER = ['critical', 'severe', 'serious', 'minor'];

    /**
     * The severity of one category code.
     */
    public static function for(string $category): string
    {
        $needle = mb_strtolower(trim($category));
        if ($needle === '') {
            return self::fallback();
        }

        foreach (self::ORDER as $band) {
            foreach ((array) config("moderation.enforcement.severity.$band", []) as $code) {
                if (mb_strtolower((string) $code) === $needle) {
                    return $band;
                }
            }
        }

        return self::fallback();
    }

    /**
     * The worst severity among several categories.
     *
     * A submission that is both spam and a threat is a threat. Taking the
     * first category instead would make the charge depend on the order the
     * provider happened to list them in.
     *
     * @param  list<string>  $categories
     */
    public static function highest(array $categories): string
    {
        $found = array_map(self::for(...), $categories);

        foreach (self::ORDER as $band) {
            if (in_array($band, $found, true)) {
                return $band;
            }
        }

        return self::fallback();
    }

    /**
     * A single label for the violation row, for a moderator reading it
     * later. The worst category wins, for the same reason as above.
     *
     * @param  list<string>  $categories
     */
    public static function primaryCategory(array $categories): string
    {
        $worst = self::highest($categories);

        foreach ($categories as $category) {
            if (self::for($category) === $worst) {
                return mb_substr($category, 0, 40);
            }
        }

        return 'policy';
    }

    private static function fallback(): string
    {
        $band = (string) config('moderation.enforcement.default_severity', 'minor');

        return in_array($band, self::ORDER, true) ? $band : 'minor';
    }
}
