<?php

namespace App\Services\Moderation;

use Illuminate\Container\Container;

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

    /**
     * Tokens either side of an explicit sexual term within which an
     * invitation, or an academic framing, still counts as attached to it.
     * Roughly a clause: wide enough for "grup seks yapmak isteyen var mı",
     * narrow enough that a lesson mentioned two sentences away does not
     * exempt it.
     */
    private const SOLICITATION_WINDOW = 8;

    /**
     * Academic framing has to sit right beside the explicit word, because
     * it works by modifying it: "sex education", "sexual health seminar".
     *
     * A clause-width window was too generous. `ders` — lesson — appears in
     * most Turkish campus posts, so at eight tokens "group sex anyone want
     * to join, ders" exempted itself. Three tokens is the width of a noun
     * phrase, which is the only place a modifier can be.
     */
    private const SOLICITATION_CONTEXT_WINDOW = 3;

    public function __construct(private readonly TextNormalizer $normalizer = new TextNormalizer) {}

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
        if ($pii !== null && ! $this->isOwnContact($n->canonical)) {
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

        // Imperative abuse, decided before the target-detection rules
        // below — these forms are aimed at a person by their grammar and
        // do not need a pronoun to prove it. After the exemption pass, so
        // quoting one in order to report it stays allowed.
        $directed = $this->directedProfanity($n);
        if ($directed !== null) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE, 'S2', ['PROF', 'HAR'], true,
                'directed_profanity', ['directed:'.$directed],
            );
        }

        // Explicit sexual solicitation. Decided after the exemption pass so
        // that quoting a post in order to report it stays allowed.
        $solicitation = $this->sexualSolicitation($n);
        if ($solicitation !== null) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE, 'S3', ['SEX'], false,
                'sexual_solicitation', ['solicitation:'.$solicitation],
            );
        }

        if ($phraseHits !== []) {
            $verdict = $this->decideFromPhrase($phraseHits[0], $n, $targeted, $ambiguity);
            if ($verdict !== null) {
                return $this->softenIfReporting($verdict, $n);
            }
        }

        // Spam and fraud by shape rather than vocabulary — after the phrase
        // rules, never before them. Structure is the fallback for wording
        // nobody listed; a rule that names the thing outright is the better
        // answer and says so more precisely. Running this first downgraded
        // "garantili kazanç, önce kapora gönder" from a removal to a review,
        // which is a clear scam being held instead of refused.
        $structural = $this->decideFromStructure($n, $text);
        if ($structural !== null) {
            return $structural;
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
    /**
     * True when the author is sharing their own contact details.
     *
     * Publishing your own number to a study group is an ordinary thing a
     * student does, and their decision to make. Every Turkish mobile number
     * in every post used to be treated as doxxing — including "benim
     * numaram ...", which is the single commonest way a number appears on a
     * campus feed.
     *
     * Deliberately a marker list rather than a general first-person check:
     * "benim arkadasimin numarasi" is somebody else's number and must stay
     * blocked, so the markers name the possessive and the noun together.
     */
    private function isOwnContact(string $normalised): bool
    {
        foreach (PolicyLexicon::OWN_CONTACT_MARKERS as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spam and fraud, from structure rather than vocabulary.
     *
     * Two signals, never one. Every individual signal here appears in
     * ordinary campus posts — societies shout, sellers post prices, people
     * share links — and firing on any single one would make the feed
     * unusable for exactly the students who use it most.
     *
     * Scam outranks spam: being defrauded costs a student money, being
     * advertised at costs them a scroll.
     */
    private function decideFromStructure(NormalizedText $n, string $original): ?ModerationVerdict
    {
        $scam = StructuralSignals::scam($n->canonical, $original);
        if (count($scam) >= 2) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE,
                'S3',
                ['SCAM'],
                false,
                'structural_scam',
                $scam,
            );
        }

        $spam = StructuralSignals::spam($n->canonical, $original);
        if (count($spam) >= 2) {
            return new ModerationVerdict(
                ModerationVerdict::REMOVE,
                'S2',
                ['SPAM'],
                false,
                'structural_spam',
                $spam,
            );
        }

        // One scam signal on its own is not enough to refuse, and not
        // nothing either: "guaranteed returns, DM me" with no link is the
        // opening move. Held rather than published, which is what the
        // brief asks for ambiguous content.
        if ($scam !== [] && $spam !== []) {
            return new ModerationVerdict(
                ModerationVerdict::REVIEW,
                'S2',
                ['SCAM'],
                false,
                'structural_mixed',
                array_merge($scam, $spam),
            );
        }

        return null;
    }

    /**
     * A report of abuse is not abuse.
     *
     * When the text carries reporting markers, a removal becomes a REVIEW:
     * still not published, still in front of a moderator within the hour,
     * but not refused with a strike against the person who came forward.
     *
     * Deliberately not a clearance. "I will kill you. Who do I report this
     * to?" would otherwise publish on the strength of one trailing phrase,
     * and the shape is too easy to discover.
     *
     * Self-harm is left alone: it is already REVIEW and already routed to
     * support rather than punishment, and there is nothing to soften.
     */
    private function softenIfReporting(ModerationVerdict $verdict, NormalizedText $n): ModerationVerdict
    {
        if (! $verdict->blocksPublication()) {
            return $verdict;
        }

        if (! $this->isReportingContext($n->canonical)) {
            return $verdict;
        }

        return new ModerationVerdict(
            ModerationVerdict::REVIEW,
            $verdict->severity,
            $verdict->labels,
            $verdict->targeted,
            $verdict->context.'+reported',
            $verdict->matches,
        );
    }

    /**
     * True when the text is reporting or quoting rather than doing.
     *
     * Used to downgrade, never to clear: a threat wrapped in "asking for a
     * friend" still reaches a moderator. What it prevents is refusing the
     * message of the person who came to report being threatened, which is
     * the one message this app most needs to accept.
     */
    private function isReportingContext(string $normalised): bool
    {
        foreach (PolicyLexicon::REPORTING_CONTEXT as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function detectPii(string $text): ?string
    {
        // Separators people actually use when writing a number out.
        $compact = preg_replace('/[\s().\-\/]+/u', '', $text) ?? $text;

        // Shape alone is too broad. A T.C. number must also satisfy both
        // check digits; otherwise an ordinary reference/order id would be
        // treated as a privacy violation.
        if (preg_match_all('/(?<!\d)[1-9]\d{10}(?!\d)/', $compact, $ids)) {
            foreach ($ids[0] as $id) {
                if ($this->validTurkishNationalId($id)) {
                    return 'pii:national_id';
                }
            }
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
        if (preg_match_all('/\bTR[ .\-]?\d{2}(?:[ .\-]?\d){22}\b/i', $text, $ibans)) {
            foreach ($ibans[0] as $iban) {
                if ($this->validIban($iban)) {
                    return 'pii:iban';
                }
            }
        }

        return null;
    }

    private function validTurkishNationalId(string $value): bool
    {
        if (! preg_match('/^[1-9]\d{10}$/', $value)) {
            return false;
        }
        $d = array_map('intval', str_split($value));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        $tenth = (($odd * 7) - $even) % 10;
        if ($tenth < 0) {
            $tenth += 10;
        }

        return $d[9] === $tenth && $d[10] === array_sum(array_slice($d, 0, 10)) % 10;
    }

    private function validIban(string $value): bool
    {
        $iban = strtoupper(preg_replace('/[ .\-]+/', '', $value) ?? '');
        if (! preg_match('/^TR\d{24}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = (($remainder * 10) + (int) $digit) % 97;
        }

        return $remainder === 1;
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
            // `graphic_violence` sits here rather than with the ordinary
            // removals because it is first-person intent to injure a named
            // third party — the same seriousness as a direct threat, minus
            // only the second-person pronoun.
            'threat', 'threat_locator', 'weapon', 'sextortion',
            'graphic_violence',
            'minor_safety', 'terrorism', 'criminal_instructions' => $verdict(
                ModerationVerdict::REMOVE_ESCALATE, 'S4',
            ),

            // These phrases are deliberately narrow harmful calls to action,
            // not ordinary disagreement or merely false trivia.
            'misinformation' => $verdict(ModerationVerdict::REMOVE, 'S3'),

            // A student in crisis is not a rule-breaker. REVIEW publishes
            // nothing punitive and records no strike; the caller turns this
            // into an offer of support (see ModerationOutcome::support).
            // Getting this wrong in the punishing direction would teach the
            // people most at risk that saying it out loud costs them.
            'self_harm' => $verdict(ModerationVerdict::REVIEW, 'S4'),

            'hate_coded' => $verdict(ModerationVerdict::REVIEW, 'S4'),
            'explicit_sexual_term' => $verdict(ModerationVerdict::REVIEW, 'S2'),
            'hate_group', 'hate_exclusion', 'wish_harm', 'blackmail', 'family_attack',
            'vulgar_insult',
            'sexual_harassment',
            'dehumanization', 'worthlessness', 'exclusion', 'appearance_attack',
            'ability_attack', 'dismissal', 'direct_insult', 'political',
            'academic_dishonesty', 'scam_fraud', 'drug_sale', 'doxxing',
            'cybercrime', 'impersonation', 'piracy', 'animal_abuse',
            'brigading' => $verdict(
                ModerationVerdict::REMOVE, $hit['severity'],
            ),
            'spam_solicitation' => $verdict(ModerationVerdict::REMOVE, 'S3'),
            'intelligence_jab', 'taunt', 'content_profanity' => $verdict(
                ModerationVerdict::WARN, $hit['severity'],
            ),
            default => null,
        };
    }

    /**
     * @param  array{strong: list<string>, mild: list<string>, profanity: bool, profanity_strong: bool, profanity_strong_terms: list<string>, profanity_severe: bool}  $words
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
            //
            // This was briefly changed to hold all strong obscenity with
            // no detectable target, which looked like the right answer to
            // "siktir is allowed but siktir git is refused" — and held
            // "What the fuck is wrong with this system?" along with it.
            // A labelled corpus of 150 cases already encodes the
            // distinction deliberately, in all three languages.
            //
            // The parity problem it was meant to solve is handled higher
            // up instead, by DIRECTED_PROFANITY: an imperative like
            // "siktir" is aimed at a person by its grammar and is refused
            // on its own, while an exclamation about a broken system is
            // not. That is the real difference, and targeting was only
            // ever a proxy for it.
            //
            // The switch below is that policy made changeable rather than
            // argued about again. A university that wants no coarse
            // language at all sets MODERATION_HOLD_UNTARGETED_PROFANITY=true
            // and gets exactly that — including, unavoidably, a student
            // complaining about the app. It holds for a human rather than
            // refusing, because "this post swears" is not the same finding
            // as "this post attacks someone". Default off, so the corpus
            // keeps describing live behaviour.
            if ($words['profanity_strong'] && $this->holdsUntargetedProfanity()) {
                return new ModerationVerdict(
                    ModerationVerdict::REVIEW, 'S1', $labels, false,
                    'strong_profanity_untargeted', ['profanity_strong'],
                );
            }

            return ModerationVerdict::allow('general_exclamation', 'S1', $labels, ['profanity']);
        }

        return ModerationVerdict::allow();
    }

    /**
     * Forms that are abuse by their grammar, with or without a pronoun.
     *
     * "siktir", "fuck off" and "иди на хуй" are imperatives: they cannot
     * be said *about* a situation, only *at* a person. The general rule
     * of waiting for a detectable target is right for an exclamation and
     * wrong for these — which is how "siktir git" came to be refused
     * while a bare "siktir" published, exactly the kind of gap a student
     * finds in an afternoon.
     */
    /**
     * Whether a token is an ordinary word a root happens to prefix.
     *
     * Matched by prefix, not equality, for the same reason the roots are:
     * Turkish inflects endlessly. The guard listed "sikayetleri" and the
     * text said "şikayetlerinizi", so a complaint about the student
     * council was refused as obscenity. Enumerating every inflection of
     * every innocent word is not achievable; guarding the stem is.
     */
    private function isGuardedWord(string $token): bool
    {
        foreach (PolicyLexicon::FALSE_POSITIVE_GUARD as $guard) {
            $canonical = $this->normalizer->canonicalizeFragment($guard);
            if ($canonical === '') {
                continue;
            }
            if ($token === $canonical || str_starts_with($token, $canonical)) {
                return true;
            }
        }

        return false;
    }

    private function directedProfanity(NormalizedText $n): ?string
    {
        foreach (PolicyLexicon::DIRECTED_PROFANITY as $phrase) {
            $canonical = $this->normalizer->canonicalizeFragment($phrase);
            if ($canonical === '') {
                continue;
            }

            if (str_contains($canonical, ' ')) {
                if ($n->has($canonical)) {
                    return $phrase;
                }

                continue;
            }

            foreach ($n->tokens as $token) {
                if ($this->isGuardedWord($token)) {
                    continue;
                }
                // Prefix, so "siktir" also covers "siktirin", "siktirip".
                if ($token === $canonical || str_starts_with($token, $canonical)) {
                    return $phrase;
                }
                // And a doubled letter does not buy a way past it either.
                $collapsed = $this->normalizer->collapseDoubles($token);
                if ($collapsed !== $token && str_starts_with($collapsed, $canonical)
                    && ! $this->isGuardedWord($collapsed)) {
                    return $phrase;
                }
            }
        }

        return $this->profanityWithDismissalParticle($n);
    }

    /**
     * Whether coarse language aimed at nobody is held for a human.
     *
     * Read through the container rather than the `config()` helper on
     * purpose: this engine is deliberately constructible with `new` and
     * most of its tests are plain PHPUnit cases with no application
     * booted, where the helper throws "Target class [config] does not
     * exist". A policy engine that only works inside a framework is
     * harder to test, and the tests are what make it trustworthy.
     */
    private function holdsUntargetedProfanity(): bool
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return false;
        }

        return (bool) $container->make('config')
            ->get('moderation.text.hold_untargeted_profanity', false);
    }

    /**
     * Somebody proposing sex on a public campus feed.
     *
     * Three conditions, all required, because each one alone is wrong:
     *
     *  - an explicit term, matched as a WHOLE TOKEN. `sex` prefixes
     *    `sexual`, and prefix-matching it would refuse the university's
     *    own "sexual harassment awareness workshop".
     *  - an invitation. Without one the word is describing something —
     *    "the sex of the participants was recorded" is a methods section.
     *  - no academic or safeguarding framing anywhere in the post. A
     *    consent seminar has to be announceable, and the announcement
     *    necessarily contains the word.
     *
     * Returns the matched term, or null.
     */
    private function sexualSolicitation(NormalizedText $n): ?string
    {
        $explicitTerms = array_merge(
            PolicyLexicon::SEXUAL_EXPLICIT_PHRASES,
            PolicyLexicon::SEXUAL_EXPLICIT_TOKENS,
        );

        foreach ($explicitTerms as $term) {
            foreach ($this->tokenPositions($n, $term) as $position) {
                // Both the invitation and the academic framing are judged
                // in a window around the explicit word, never across the
                // whole post.
                //
                // Scanning the whole post for the academic words was a
                // bypass in its own right: the wrapper of an ordinary
                // campus post contains "ders", so appending one lesson
                // reference disabled the rule for everything else in a
                // 300-word post. Framing only exempts the word it is
                // actually attached to.
                if ($this->hasNearby($n, $position, PolicyLexicon::SEXUAL_ACADEMIC_CONTEXT,
                    self::SOLICITATION_CONTEXT_WINDOW)) {
                    continue;
                }
                if ($this->hasNearby($n, $position, PolicyLexicon::SOLICITATION_MARKERS,
                    self::SOLICITATION_WINDOW)) {
                    return $term;
                }
            }
        }

        return null;
    }

    /**
     * Where a term appears, as token indices. Whole tokens only — `sex`
     * prefixes `sexual`, and the difference decides whether a harassment
     * workshop can be announced.
     *
     * @return list<int>
     */
    private function tokenPositions(NormalizedText $n, string $term): array
    {
        $canonical = $this->normalizer->canonicalizeFragment($term);
        if ($canonical === '') {
            return [];
        }

        $words = explode(' ', $canonical);
        $first = $words[0];
        $length = count($words);

        $positions = [];
        foreach ($n->tokens as $index => $token) {
            if ($token !== $first) {
                continue;
            }
            $window = array_slice($n->tokens, $index, $length);
            if (implode(' ', $window) === $canonical) {
                $positions[] = $index;
            }
        }

        return $positions;
    }

    /** @param list<string> $terms */
    private function hasNearby(NormalizedText $n, int $position, array $terms, int $window): bool
    {
        $from = max(0, $position - $window);
        $to = $position + $window;
        $nearby = array_slice($n->tokens, $from, $to - $from + 1);

        foreach ($terms as $term) {
            $canonical = $this->normalizer->canonicalizeFragment($term);
            if ($canonical !== '' && in_array($canonical, $nearby, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any strong obscenity followed by "off"/"you" is a dismissal.
     *
     * Listing the finished phrases meant listing the spellings: "fuck off"
     * was refused and "phuck off" and "fvck you" published, which is the
     * same suffix problem one level up. Anchoring on the particle instead
     * covers every spelling of the root, including ones nobody has typed
     * yet.
     */
    private function profanityWithDismissalParticle(NormalizedText $n): ?string
    {
        $roots = array_map(
            fn (string $word): string => $this->normalizer->canonicalizeFragment($word),
            PolicyLexicon::PROFANITY_STRONG,
        );

        foreach ($n->tokens as $index => $token) {
            $next = $n->tokens[$index + 1] ?? null;
            if ($next === null || ! in_array($next, PolicyLexicon::DISMISSAL_PARTICLES, true)) {
                continue;
            }
            if ($this->isGuardedWord($token)) {
                continue;
            }

            foreach ($roots as $root) {
                if ($root !== '' && str_starts_with($token, $root)) {
                    return $token.' '.$next;
                }
            }
        }

        return null;
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
     * Shortest consonant skeleton allowed to match.
     *
     * A skeleton is only evidence if it is long enough to be unlikely by
     * chance, and short ones are not remotely unlikely. "beat you up"
     * reduces to **btp** — three consonants, which appear in ordinary
     * text constantly.
     *
     * Measured consequence before this limit existed: the Russian
     * sentence "Во сколько завтра открывается библиотека?" was refused as
     * a threat. Homoglyph folding turns "завтра" into "зabtpa", whose
     * skeleton contains btp, and any post carrying a `#` switches
     * skeleton matching on — so an ordinary question with a hashtag was
     * blocked for making a threat.
     *
     * Six is chosen because real obfuscation targets are longer than
     * that: "seni öldür" is snldr, "i will kill you" is wllkll. A phrase
     * too short to clear this bar is still matched literally and through
     * the gap operator; it simply cannot be matched by consonants alone.
     */
    private const MIN_SKELETON_LENGTH = 6;

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

        $phraseSkeleton = $this->normalizer->skeleton(str_replace(' ', '', $canonical));
        if (mb_strlen($phraseSkeleton) < self::MIN_SKELETON_LENGTH) {
            return false;
        }

        return str_contains($this->normalizer->skeleton($n->compact), $phraseSkeleton);
    }

    /**
     * @return array{strong: list<string>, mild: list<string>, profanity: bool, profanity_strong: bool, profanity_strong_terms: list<string>, profanity_severe: bool}
     */
    private function matchWords(NormalizedText $n): array
    {
        $strongProfanity = $this->matchList($n, PolicyLexicon::PROFANITY_STRONG);

        return [
            'strong' => $this->matchList($n, PolicyLexicon::INSULT_STRONG),
            'vulgar' => $this->matchList($n, PolicyLexicon::INSULT_VULGAR),
            'mild' => $this->matchList($n, PolicyLexicon::INSULT_MILD),
            'profanity' => $this->matchList($n, PolicyLexicon::PROFANITY) !== [],
            'profanity_strong' => $strongProfanity !== [],
            'profanity_strong_terms' => $strongProfanity,
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
                if ($this->isGuardedWord($token)) {
                    continue;
                }
                // Prefix matching carries Turkish/Russian inflection
                // ("mal" → "malsın"); the guard above is what keeps it
                // from reaching "malzeme" or "picture".
                if ($token === $canonical || (mb_strlen($canonical) >= 3 && str_starts_with($token, $canonical))) {
                    $found[] = $term;

                    continue 2;
                }
                // "sikktir", "fukk", "bittch" — a doubled letter is the
                // cheapest way past a root, and normalisation deliberately
                // keeps doubles because English needs them ("kill", "hall").
                // Collapsing them here instead leaves that intact.
                $collapsed = $this->normalizer->collapseDoubles($token);
                if ($collapsed !== $token && mb_strlen($canonical) >= 3
                    && str_starts_with($collapsed, $canonical)
                    && ! $this->isGuardedWord($collapsed)) {
                    $found[] = $term;

                    continue 2;
                }
                // A near miss is only trusted when the author was already
                // mixing scripts or digits — "кретuн" vs "кретин".
                //
                // Six characters, not five: at five, ordinary Turkish
                // words sit one edit from an insult. "İptal" — cancelled —
                // is one edit from "aptal", so the university's own
                // phishing warning ("yurt hakkınız iptal edilir") was read
                // as calling the reader stupid. A one-edit window is only
                // safe when the word is long enough that a coincidence is
                // unlikely.
                if ($n->suspicious && mb_strlen($token) >= 6 && $this->normalizer->editDistance($token, $canonical) <= 1) {
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
        //
        // Strong obscenity counts here as well as the insult list. "sikik
        // herif" is a slur attached to a person-noun and read as an
        // untargeted exclamation, because only INSULT_STRONG was being
        // considered — so an obscenity aimed at somebody present was
        // treated the same as swearing at the weather.
        return $this->insultHasPointingNeighbour($n, array_merge(
            $words['strong'] ?? [],
            $words['profanity_strong_terms'] ?? [],
        ));
    }

    /** @param list<string> $strongTerms */
    private function insultHasPointingNeighbour(NormalizedText $n, array $strongTerms): bool
    {
        if ($strongTerms === []) {
            return false;
        }
        $canonicalize = fn (string $word): string => $this->normalizer->canonicalizeFragment($word);
        $personNouns = array_map($canonicalize, PolicyLexicon::PERSON_NOUNS);
        $demonstratives = array_map($canonicalize, PolicyLexicon::DEMONSTRATIVES);

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

            // A person-noun points at somebody from either side: "sikik
            // herif", "herif sikik".
            foreach ([$index - 2, $index - 1, $index + 1] as $neighbourIndex) {
                $neighbour = $n->tokens[$neighbourIndex] ?? null;
                if ($neighbour !== null && in_array($neighbour, $personNouns, true)) {
                    return true;
                }
            }

            // A demonstrative only counts when it comes *first* — "bu ezik",
            // "such a loser". Following the obscenity it opens the next noun
            // phrase instead of pointing back at anyone: "какого хуя эта
            // система не работает" is aimed at the system, and reading its
            // "эта" as a target refused ordinary frustration.
            foreach ([$index - 2, $index - 1] as $neighbourIndex) {
                $neighbour = $n->tokens[$neighbourIndex] ?? null;
                if ($neighbour !== null && in_array($neighbour, $demonstratives, true)) {
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
