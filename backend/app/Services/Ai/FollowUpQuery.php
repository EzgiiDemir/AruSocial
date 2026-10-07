<?php

namespace App\Services\Ai;

use App\Support\TextFold;

/**
 * What to search the knowledge base with, when the latest message does not
 * say what it is about.
 *
 * Extracted from AiController for the same reason AskPromptBuilder was: the
 * evaluation harness has to resolve a follow-up EXACTLY as the product does,
 * and a second copy of the rule is a second thing to be wrong.
 *
 * It was measured being wrong. The harness verified a multi-turn answer
 * against a prompt built from the bare question "And how much does it cost?",
 * while the controller had answered using the enriched query that includes
 * "Tell me about the dormitory". The model read €3,740 off the indexed
 * accommodation page and answered correctly; the harness, looking at a prompt
 * that had never retrieved that page, reported it as an invented figure. A
 * grounding check comparing against the wrong prompt does not measure
 * grounding — and it fails in the direction that wastes an afternoon chasing
 * a hallucination that did not happen.
 */
final class FollowUpQuery
{
    /** Below this, a question cannot be carrying a subject of its own. */
    private const SUBJECTLESS_WORDS = 4;

    /**
     * Expressions that point at something said earlier and nothing else.
     *
     * Matched as whole words against folded text, so the Turkish entries cover
     * their dotless and diacritic-free spellings too.
     *
     * Bare "bu"/"this" is deliberately absent: "bu yıl kayıt ücreti" names its
     * own topic. Only the pronominal forms ("bunu", "bunun") are here, because
     * those cannot be read as a determiner on a noun that follows.
     *
     * @var list<string>
     */
    private const MARKERS = [
        // Turkish — third-person and distal pronouns, plus continuation
        // particles that only make sense after a previous turn.
        'peki', 'o', 'onu', 'ona', 'onun', 'onlar', 'onları', 'onların',
        'ora', 'orada', 'oraya', 'orası', 'orayı', 'oranın', 'oradan',
        'bunu', 'buna', 'bunun', 'bunlar', 'bunları', 'bunlardan',
        'şunu', 'şuna', 'şunun', 'bahsettiğin', 'dediğin', 'söylediğin',
        'yukarıdaki', 'aynısı', 'diğeri', 'diğerleri', 'hangisi', 'ikisi',
        // English
        'it', 'its', 'that', 'those', 'these', 'them', 'they', 'there',
        'what about', 'how about', 'the same', 'which one', 'the other',
        // Russian
        'он', 'она', 'они', 'его', 'её', 'их', 'это', 'этот', 'там',
        'туда', 'оттуда', 'а что', 'тот же', 'который',
    ];

    /**
     * The latest question, with the subject an earlier turn supplied when this
     * one has none of its own.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public static function resolve(array $messages, string $prompt): string
    {
        if (! self::isFollowUp($prompt)) {
            return $prompt;
        }

        $subject = self::subject($messages);

        return $subject === '' ? $prompt : $subject.' '.$prompt;
    }

    /**
     * Whether a message leans on the conversation for its subject.
     *
     * This used to be a character count: anything over 40 characters was
     * assumed to stand on its own. Turkish agglutinates, so "Peki bu bölümün
     * mezunları nerede çalışıyor?" is 44 characters of pure anaphora and was
     * retrieved with no subject at all, while the rule also fired on short
     * questions that named their own topic.
     */
    public static function isFollowUp(string $text): bool
    {
        $folded = TextFold::fold(trim($text));
        if ($folded === '') {
            return false;
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return false;
        }
        if (count($words) <= self::SUBJECTLESS_WORDS) {
            return true;
        }

        $padded = ' '.implode(' ', $words).' ';
        foreach (self::MARKERS as $marker) {
            if (str_contains($padded, ' '.TextFold::fold($marker).' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The most recent user turn that actually names a subject.
     *
     * Walks back past earlier follow-ups rather than stopping at the turn
     * immediately before. A student who asks about a programme, then "taban
     * puanlar?", then "ya burslar?" is still asking about that programme —
     * stopping at "taban puanlar?" would have restored nothing.
     *
     * Assistant turns are skipped: they are long, and the source-priority
     * rules in the system prompt already say the conversation is for
     * understanding the question, never a source of fact.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private static function subject(array $messages): string
    {
        $fallback = '';
        $seenLast = false;
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? 'user') === 'assistant') {
                continue;
            }
            if (! $seenLast) {
                $seenLast = true;   // this is the prompt itself

                continue;
            }
            $earlier = trim((string) ($m['content'] ?? ''));
            if ($earlier === '') {
                continue;
            }
            if ($fallback === '') {
                $fallback = $earlier;
            }
            if (! self::isFollowUp($earlier)) {
                return $earlier;
            }
        }

        // Every earlier turn was itself a follow-up: the nearest one is still
        // better context than none.
        return $fallback;
    }
}
