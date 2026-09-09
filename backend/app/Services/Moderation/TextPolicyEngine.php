<?php

namespace App\Services\Moderation;

/**
 * Decides what to do with a piece of user text.
 *
 * The pipeline is: normalize → check whether the context exempts the text
 * at all (quoting, reporting, teaching, fiction, self-deprecation, in-game
 * trash talk) → work out whether a person is actually being addressed →
 * match the lexicon → turn severity plus shape-of-attack into one of five
 * decisions.
 *
 * The shape matters as much as the words. "What a stupid comment" and
 * "you are stupid" contain the same insult but are not the same act: the
 * first is aimed at content and earns a warning, the second is aimed at a
 * person and is removed. A flat blocklist cannot express that difference,
 * which is why it kept both over- and under-blocking.
 *
 * Behaviour is pinned by backend/tests/fixtures/moderation_cases.jsonl.
 */
final class TextPolicyEngine
{
    /** Unrelated words tolerated between two anchors of a "a~b" phrase. */
    private const PHRASE_GAP_WORDS = 5;

    public function __construct(private readonly TextNormalizer $normalizer = new TextNormalizer()) {}

    public function evaluate(string $text): ModerationVerdict
    {
        $n = $this->normalizer->normalize($text);
        if ($n->canonical === '') {
            return ModerationVerdict::allow();
        }

        $phraseHits = $this->matchPhraseRules($n);
        $words = $this->matchWords($n);
        $targeted = $this->detectTargeting($n, $words);

        // A statement about hurting yourself is the one thing no exemption
        // may wave through. "self_directed" exists so "I'm such an idiot"
        // isn't punished as an insult — but it matches the grammar of a real
        // crisis post just as well, and silently allowing that means the
        // student is never offered help. Decided before exemptions for that
        // reason alone.
        foreach ($phraseHits as $hit) {
            if ($hit['kind'] === 'self_harm') {
                return new ModerationVerdict(
                    ModerationVerdict::REVIEW, 'S4', ['SELF'], false, 'self_harm',
                    ['self_harm:'.$hit['phrase']],
                );
            }
        }

        $exemption = $this->detectExemption($n, $targeted);
        if ($exemption !== null) {
            $isRealWorldThreat = $this->hasKind($phraseHits, 'threat_locator');
            if ($exemption !== 'gameplay' || ! $isRealWorldThreat) {
                return new ModerationVerdict(
                    ModerationVerdict::ALLOW,
                    $words['profanity'] || $words['profanity_strong'] ? 'S1' : 'S0',
                    $words['profanity'] || $words['profanity_strong'] ? ['PROF'] : [],
                    false,
                    $exemption,
                    ['context:'.$exemption],
                );
            }
        }

        $ambiguity = $this->detectAmbiguity($n, $words);
        $contentAttack = $this->hasContentTarget($n);

        // Identity numbers and full phone numbers are checked on the raw
        // text, because normalisation folds the digits and separators these
        // depend on. Checked after the exemption pass so a student quoting
        // their own number in a lost-property post is not treated the same
        // as someone publishing another person's.
        $pii = $this->detectPii($text);
        if ($pii !== null) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE, 'S3', ['PRIV'], true, 'pii_exposure', [$pii],
            );
        }

        // Hate is decided before the ordinary insult scoring, because it
        // does not follow the same rules. An insult needs a target — "you
        // are an idiot" is an attack, "what an idiot" is a grumble. A slur
        // needs nobody: the word is the attack, and "lanet zenci" with no
        // pronoun anywhere used to score as a mild non-targeted grumble and
        // publish. It sits after the exemption pass so quoting a slur in
        // order to condemn or report it stays allowed.
        $hate = $this->detectHate($n);
        if ($hate !== null) {
            return $hate;
        }

        if ($phraseHits !== []) {
            $verdict = $this->decideFromPhrase($phraseHits[0], $n, $targeted, $ambiguity);
            if ($verdict !== null) {
                return $verdict;
            }
        }

        return $this->decideFromWords($n, $words, $targeted, $contentAttack, $ambiguity);
    }

    /**
     * Hate speech: an unambiguous slur, or a group named with hostility.
     *
     * The two halves exist because they fail in opposite directions. A slur
     * list alone misses "lanet siyahiler" — every word in it is innocent on
     * its own. Adding the identity words to the slur list instead would
     * block "siyahi arkadaşım" and any discussion of racism, which is worse
     * than useless: it silences exactly the people the rule protects.
     *
     * So slurs match alone, and identity words only match when something
     * hostile appears with them.
     */
    private function detectHate(NormalizedText $n): ?ModerationVerdict
    {
        $slurs = $this->matchList($n, PolicyLexicon::SLURS);
        if ($slurs !== []) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE, 'S4', ['HATE'], true, 'hate_slur',
                array_map(fn (string $s): string => 'slur:'.$s, $slurs),
            );
        }

        $groups = $this->matchList($n, PolicyLexicon::PROTECTED_GROUPS);
        if ($groups === []) {
            return null;
        }
        $hostile = $this->matchList($n, PolicyLexicon::HOSTILE_MODIFIERS);
        if ($hostile === []) {
            return null;
        }

        return new ModerationVerdict(
            ModerationVerdict::REMOVE, 'S4', ['HATE'], true, 'hate_group',
            ['group:'.$groups[0], 'hostility:'.$hostile[0]],
        );
    }

    /**
     * Personal identifiers that should never appear in a public campus post.
     *
     * Kept narrow on purpose. A campus feed is full of harmless numbers —
     * room 204, extension 1006, "2026 mezunuyum", prices, dates — so these
     * patterns only fire on shapes that are specifically identity-bearing:
     * a full 11-digit T.C. kimlik number, a complete Turkish mobile number,
     * or an IBAN. Anything shorter is left alone rather than risking a
     * timetable post being read as doxxing.
     */
    private function detectPii(string $text): ?string
    {
        // Separators people actually use when writing a number out.
        $compact = preg_replace('/[\s().\-\/]+/u', '', $text) ?? $text;

        // T.C. kimlik: exactly 11 digits, never starting with 0, and not
        // part of a longer digit run (which would be an order id, not an
        // identity number).
        if (preg_match('/(?<!\d)[1-9]\d{10}(?!\d)/', $compact)) {
            return 'pii:national_id';
        }

        // Turkish mobile: +90/0 followed by a 5xx line, 10 digits total.
        if (preg_match('/(?<!\d)(?:\+?90|0)?5\d{9}(?!\d)/', $compact)) {
            return 'pii:phone';
        }

        // Matched against the original text, not the compacted one: joining
        // the separators also joins the preceding word to the "TR", which
        // destroys the word boundary an IBAN needs to be recognised by.
        // People do write IBANs in spaced groups, so separators are allowed
        // inside the pattern instead.
        if (preg_match('/\bTR[ .\-]?\d{2}(?:[ .\-]?\d){22}\b/i', $text)) {
            return 'pii:iban';
        }

        return null;
    }

    /**
     * Phrase rules carry their own outcome, so the decision is mostly a
     * lookup — the exception is a threat, where whether a person is being
     * addressed decides between escalation and a plain removal.
     *
     * @param  array{kind: string, labels: list<string>, severity: string, phrase: string}  $hit
     */
    private function decideFromPhrase(array $hit, NormalizedText $n, bool $targeted, ?string $ambiguity): ?ModerationVerdict
    {
        $match = [$hit['kind'].':'.$hit['phrase']];

        $verdict = fn (string $decision, string $severity): ModerationVerdict => new ModerationVerdict(
            $decision, $severity, $hit['labels'], $targeted, $hit['kind'], $match,
        );

        return match ($hit['kind']) {
            // These phrases only *are* threats because they name the person
            // ("your face", "your address", "тебя найдём"), so the target is
            // built into the match rather than needing a separate pronoun.
            // Escalation, not just removal: these need a human to look at
            // the account, not a counter to tick over.
            'threat', 'threat_locator', 'weapon', 'sextortion',
            'minor_safety', 'terrorism', 'criminal_instructions' => $verdict(
                ModerationVerdict::REMOVE_ESCALATE, 'S4',
            ),

            // Deciding what is true is not a word list's job, so this goes
            // to a moderator rather than being refused outright.
            'misinformation' => $verdict(ModerationVerdict::REVIEW, 'S2'),

            // A student in crisis is not a rule-breaker. REVIEW publishes
            // nothing punitive and records no strike; the caller turns this
            // into an offer of support (see ModerationOutcome::support).
            // Getting this wrong in the punishing direction would teach the
            // people most at risk that saying it out loud costs them.
            'self_harm' => $verdict(ModerationVerdict::REVIEW, 'S4'),

            'hate_coded' => $verdict(ModerationVerdict::REVIEW, 'S4'),
            'hate_group', 'wish_harm', 'blackmail', 'family_attack', 'sexual_harassment',
            'dehumanization', 'worthlessness', 'exclusion', 'appearance_attack',
            'ability_attack', 'dismissal', 'direct_insult', 'political',
            'academic_dishonesty', 'scam_fraud', 'drug_sale', 'doxxing',
            'cybercrime', 'impersonation', 'piracy', 'animal_abuse' => $verdict(
                ModerationVerdict::REMOVE, $hit['severity'],
            ),
            'intelligence_jab', 'taunt', 'content_profanity',
            'spam_solicitation' => $verdict(
                ModerationVerdict::WARN, $hit['severity'],
            ),
            default => null,
        };
    }

    /**
     * @param  array{strong: list<string>, mild: list<string>, profanity: bool, profanity_strong: bool, profanity_severe: bool}  $words
     */
    private function decideFromWords(
        NormalizedText $n,
        array $words,
        bool $targeted,
        bool $contentAttack,
        ?string $ambiguity,
    ): ModerationVerdict {
        $matches = array_merge($words['strong'], $words['mild']);

        // Ambiguous wording is routed to a human rather than auto-actioned:
        // sarcasm, friendly banter, an unnamed target and "I'm not insulting
        // you, but…" all read as abuse to a matcher and often are not.
        if ($ambiguity !== null) {
            $severity = in_array($ambiguity, ['implied_insult', 'vague_reference'], true) && $words['strong'] !== []
                ? 'S2'
                : 'S1';

            return new ModerationVerdict(
                ModerationVerdict::REVIEW, $severity, ['HAR'], $targeted, $ambiguity, $matches ?: [$ambiguity],
            );
        }

        // Obscene insults are removed even when pointed at a piece of work —
        // there is no version of calling something "orospu" that is critique.
        if ($words['vulgar'] !== []) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE, 'S2', ['PROF', 'HAR'], $targeted, 'direct_attack', $words['vulgar'],
            );
        }

        if ($words['strong'] !== []) {
            if ($contentAttack) {
                return new ModerationVerdict(ModerationVerdict::WARN, 'S2', ['HAR'], $targeted, 'content_attack', $matches);
            }
            if ($targeted) {
                return new ModerationVerdict(ModerationVerdict::REMOVE, 'S2', ['HAR'], true, 'direct_attack', $matches);
            }

            // An insult with nobody addressed is exactly the case a human
            // should read: it is often about a third party or a thing.
            return new ModerationVerdict(ModerationVerdict::REVIEW, 'S1', ['HAR'], false, 'vague_reference', $matches);
        }

        if ($words['mild'] !== []) {
            return $targeted
                ? new ModerationVerdict(ModerationVerdict::WARN, 'S2', ['HAR'], true, 'taunting', $matches)
                : ModerationVerdict::allow('clean', 'S1', ['HAR'], $matches);
        }

        if ($words['profanity_severe']) {
            return new ModerationVerdict(
                ModerationVerdict::WARN, $targeted ? 'S2' : 'S1', ['PROF'], $targeted,
                $targeted ? 'direct_attack' : 'general_exclamation', ['profanity_severe'],
            );
        }

        if ($words['profanity_strong'] || $words['profanity']) {
            $labels = ['PROF'];
            if ($contentAttack) {
                return new ModerationVerdict(ModerationVerdict::WARN, 'S2', array_merge($labels, ['HAR']), $targeted, 'content_attack', ['profanity']);
            }
            if ($targeted && $words['profanity_strong']) {
                return new ModerationVerdict(ModerationVerdict::REMOVE, 'S2', array_merge($labels, ['HAR']), true, 'direct_attack', ['profanity_strong']);
            }

            // Swearing at a situation, a bug or the weather is not abuse.
            return ModerationVerdict::allow('general_exclamation', 'S1', $labels, ['profanity']);
        }

        return ModerationVerdict::allow();
    }

    /**
     * @return list<array{kind: string, labels: list<string>, severity: string, phrase: string}>
     */
    private function matchPhraseRules(NormalizedText $n): array
    {
        $hits = [];
        foreach (PolicyLexicon::PHRASE_RULES as $rule) {
            foreach ($rule['phrases'] as $phrase) {
                $canonical = $this->normalizer->canonicalizeFragment($phrase);
                if ($canonical === '') {
                    continue;
                }
                if (str_contains($phrase, '~')) {
                    $matched = $this->matchesWithGap($n, $phrase);
                } elseif (mb_strlen($canonical) <= 4 && ! str_contains($canonical, ' ')) {
                    // A short single word ("akp") must be a whole token: a
                    // substring check would find it inside "bak parti" once
                    // spaces are stripped for the split-text comparison.
                    $matched = in_array($canonical, $n->tokens, true);
                } else {
                    $matched = $n->has($canonical) || $this->matchesThroughMasking($n, $canonical);
                }

                if ($matched) {
                    $hits[] = [
                        'kind' => $rule['kind'],
                        'labels' => $rule['labels'],
                        'severity' => $rule['severity'],
                        'phrase' => $phrase,
                    ];

                    continue 2;
                }
            }
        }

        // Highest severity first so "I know your address" outranks a mere
        // insult present in the same sentence.
        usort($hits, fn (array $a, array $b): int => $b['severity'] <=> $a['severity']);

        return $hits;
    }

    /**
     * "a~b" means: anchor a, then anchor b, with up to a few unrelated words
     * between them. It is what lets one rule cover "hesabını sileceğim" and
     * "hesabını yakında tamamen sileceğim" without listing both.
     */
    private function matchesWithGap(NormalizedText $n, string $phrase): bool
    {
        $anchors = array_values(array_filter(array_map(
            fn (string $anchor): string => $this->normalizer->canonicalizeFragment(trim($anchor)),
            explode('~', $phrase),
        )));
        if (count($anchors) < 2) {
            return false;
        }

        $parts = array_map(fn (string $anchor): string => preg_quote($anchor, '/'), $anchors);

        // `\S*` lets an anchor be a stem rather than a whole word. Turkish
        // inflects at the end — "ben rektör" is written "ben rektörüm" —
        // and requiring whitespace straight after the anchor meant the
        // suffix broke the match: "ben rektörüm … para" found nothing.
        $gap = '\S*(?:\s+\S+){0,'.self::PHRASE_GAP_WORDS.'}\s+';

        return (bool) preg_match('/'.implode($gap, $parts).'/u', $n->canonical);
    }

    /**
     * Only consulted when the author actually tried to mask characters:
     * comparing consonant skeletons is deliberately loose, and letting it
     * run on clean text would start matching unrelated words.
     */
    private function matchesThroughMasking(NormalizedText $n, string $canonical): bool
    {
        if (! in_array('masking', $n->obfuscations, true)) {
            return false;
        }

        $textSkeleton = $this->normalizer->skeleton($n->compact);
        $phraseSkeleton = $this->normalizer->skeleton(str_replace(' ', '', $canonical));

        return $phraseSkeleton !== '' && str_contains($textSkeleton, $phraseSkeleton);
    }

    /**
     * @return array{strong: list<string>, mild: list<string>, profanity: bool, profanity_strong: bool, profanity_severe: bool}
     */
    private function matchWords(NormalizedText $n): array
    {
        return [
            'strong' => $this->matchList($n, PolicyLexicon::INSULT_STRONG),
            'vulgar' => $this->matchList($n, PolicyLexicon::INSULT_VULGAR),
            'mild' => $this->matchList($n, PolicyLexicon::INSULT_MILD),
            'profanity' => $this->matchList($n, PolicyLexicon::PROFANITY) !== [],
            'profanity_strong' => $this->matchList($n, PolicyLexicon::PROFANITY_STRONG) !== [],
            'profanity_severe' => $n->hasAny(array_map(
                fn (string $p): string => $this->normalizer->canonicalizeFragment($p),
                PolicyLexicon::PROFANITY_SEVERE,
            )),
        ];
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function matchList(NormalizedText $n, array $terms): array
    {
        $found = [];
        foreach ($terms as $term) {
            $canonical = $this->normalizer->canonicalizeFragment($term);
            if ($canonical === '') {
                continue;
            }
            if (in_array($canonical, PolicyLexicon::REQUIRES_TURKISH_HINT, true)
                && ! $this->looksTurkish($n->original)) {
                continue;
            }

            if (str_contains($canonical, ' ')) {
                if ($n->has($canonical)) {
                    $found[] = $term;
                }

                continue;
            }

            foreach ($n->tokens as $token) {
                if (in_array($token, PolicyLexicon::FALSE_POSITIVE_GUARD, true)) {
                    continue;
                }
                // Prefix matching carries Turkish/Russian inflection
                // ("mal" → "malsın"); FALSE_POSITIVE_GUARD above is what
                // keeps it from reaching "malzeme" or "picture".
                if ($token === $canonical || (mb_strlen($canonical) >= 3 && str_starts_with($token, $canonical))) {
                    $found[] = $term;

                    continue 2;
                }
                // A near miss is only trusted when the author was already
                // mixing scripts or digits — "кретuн" vs "кретин".
                if ($n->suspicious && mb_strlen($token) >= 5 && $this->normalizer->editDistance($token, $canonical) <= 1) {
                    $found[] = $term;

                    continue 2;
                }
            }
        }

        return $found;
    }

    /**
     * Single-word markers match whole tokens only; multi-word ones are
     * phrases and stay substring matches.
     */
    private function hasContentTarget(NormalizedText $n): bool
    {
        foreach (PolicyLexicon::CONTENT_TARGETS as $marker) {
            $canonical = $this->normalizer->canonicalizeFragment($marker);
            if ($canonical === '') {
                continue;
            }
            if (str_contains($canonical, ' ')) {
                if ($n->has($canonical)) {
                    return true;
                }

                continue;
            }
            if (in_array($canonical, $n->tokens, true)) {
                return true;
            }
        }

        return false;
    }

    /** Turkish diacritics are the cheapest reliable "this is Turkish" signal. */
    private function looksTurkish(string $original): bool
    {
        return (bool) preg_match('/[çğışÇĞİŞ]/u', $original);
    }

    /** @param array{strong: list<string>, mild: list<string>} $words */
    private function detectTargeting(NormalizedText $n, array $words): bool
    {
        if ($n->hasMention) {
            return true;
        }

        foreach (PolicyLexicon::SECOND_PERSON as $pronoun) {
            $canonical = $this->normalizer->canonicalizeFragment($pronoun);
            if ($canonical !== '' && in_array($canonical, $n->tokens, true)) {
                return true;
            }
        }

        // A post whose entire content is an insult has no second reading —
        // "salak" on its own is not commentary about an absent third party.
        if (count($n->tokens) <= 3 && ($words['strong'] ?? []) !== []) {
            return true;
        }

        // Turkish carries "you" in the verb: "davranıyorsun", "tekisin".
        foreach ($n->tokens as $token) {
            if (mb_strlen($token) < 6) {
                continue;
            }
            foreach (PolicyLexicon::TURKISH_SECOND_PERSON_SUFFIXES as $suffix) {
                if (str_ends_with($token, $suffix)) {
                    return true;
                }
            }
        }

        // "bu ezik", "this loser", "такой бездарь" — an insult pinned to a
        // demonstrative or a person-noun is pointed at somebody present.
        return $this->insultHasPointingNeighbour($n, $words['strong'] ?? []);
    }

    /** @param list<string> $strongTerms */
    private function insultHasPointingNeighbour(NormalizedText $n, array $strongTerms): bool
    {
        if ($strongTerms === []) {
            return false;
        }
        $pointers = array_map(
            fn (string $word): string => $this->normalizer->canonicalizeFragment($word),
            array_merge(PolicyLexicon::DEMONSTRATIVES, PolicyLexicon::PERSON_NOUNS),
        );

        foreach ($n->tokens as $index => $token) {
            $isInsult = false;
            foreach ($strongTerms as $term) {
                $canonical = $this->normalizer->canonicalizeFragment($term);
                if ($canonical !== '' && str_starts_with($token, $canonical)) {
                    $isInsult = true;
                    break;
                }
            }
            if (! $isInsult) {
                continue;
            }

            foreach ([$index - 2, $index - 1, $index + 1] as $neighbourIndex) {
                $neighbour = $n->tokens[$neighbourIndex] ?? null;
                if ($neighbour !== null && in_array($neighbour, $pointers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function detectExemption(NormalizedText $n, bool $targeted): ?string
    {
        foreach (PolicyLexicon::EXEMPTIONS as $context => $markers) {
            $canonicalMarkers = array_map(
                fn (string $marker): string => $this->normalizer->canonicalizeFragment($marker),
                $markers,
            );
            if (! $n->hasAny($canonicalMarkers)) {
                continue;
            }

            // Talking about yourself, or narrating someone else's words, only
            // holds while nobody is being addressed.
            if (in_array($context, ['self_directed', 'reporting_abuse'], true) && $targeted) {
                continue;
            }

            return $context;
        }

        return null;
    }

    /** @param array{strong: list<string>} $words */
    private function detectAmbiguity(NormalizedText $n, array $words): ?string
    {
        $canonical = fn (array $list): array => array_map(
            fn (string $item): string => $this->normalizer->canonicalizeFragment($item),
            $list,
        );

        if ($n->hasAny($canonical(PolicyLexicon::AMBIGUITY['implied_insult']))) {
            return 'implied_insult';
        }

        // Praise inside quote marks is how sarcasm is written down.
        foreach ($n->quoted as $segment) {
            foreach ($canonical(PolicyLexicon::AMBIGUITY['sarcasm_praise']) as $praise) {
                if ($praise !== '' && str_contains($segment, $praise)) {
                    return 'sarcasm';
                }
            }
        }

        if ($n->hasAny($canonical(PolicyLexicon::AMBIGUITY['vague_reference']))) {
            return 'vague_reference';
        }

        // An insult between friends, signed off with a laugh, is usually
        // banter — but a moderator, not this class, should be the judge.
        if ($words['strong'] !== []
            && in_array('xlaugh', $n->tokens, true)
            && $n->hasAny($canonical(PolicyLexicon::AMBIGUITY['banter_address']))) {
            return 'banter_ambiguous';
        }

        return null;
    }

    /** @param list<array{kind: string}> $hits */
    private function hasKind(array $hits, string $kind): bool
    {
        foreach ($hits as $hit) {
            if ($hit['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }
}
