<?php

namespace App\Services;

use App\Models\ModerationReport;
use App\Models\User;
use App\Services\Moderation\ModerationVerdict;
use App\Services\Moderation\TextPolicyEngine;
use Illuminate\Support\Str;

/**
 * Server-side text policy for public social surfaces (feed, stories, comments).
 *
 * Matches are deterministic keyword rules (TR / EN / RU), not natural-language
 * understanding. Category codes classify content-policy violations — not legal
 * crimes. Severity S2+ blocks and records a strike; S4/S5 add an escalate
 * prefix on the audit reason. Mild S1 general exclamations are intentionally
 * absent from the block list so ordinary campus posts stay allowed.
 */
class ModerationService
{
    private const BAN_AFTER_STRIKES = 3;

    /**
     * High-confidence block terms keyed by policy category code.
     * Matching uses normalize() + substring / compacted-substring checks.
     *
     * @var array<string, list<string>>
     */
    private const BLOCKED_TERMS = [
        'PROF' => [
            // Kept for existing tests / campus hard line
            'salak', 'aptal', 'ahmak', 'orospu', 'piç', 'yavşak',
            'fuck', 'bitch', 'asshole', 'fck',
            'siktir', 'amına koy', 'amina koy', 'şerefsiz', 'serefsiz',
            'gerizekalı', 'gerizekali', 'mal herif',
            'сука', 'бляд', 'идиот', 'тупой', 'хуй', 'мразь', 'мудак',
            'дебил', 'придурок', 'пошёл на', 'пошел на',
        ],
        'HAR' => [
            'defol git', 'seni bulacağım', 'kes sesini',
            'kimse seni istemiyor', 'hesabını kapat',
            'zavallı yaratık', 'beceriksiz', 'ezik yine',
            'korkak tavuk', 'midem bulanıyor',
            'i will find you', 'fuck off', 'fuck you', 'shut up moron',
            'piece of shit', 'pathetic loser', 'miserable creature',
            'nobody wants you', 'delete your account', 'dumbass',
            'я тебя найду', 'заткнись', 'удали аккаунт',
            'жалкое существо', 'жалкий неудачник',
        ],
        'SEX' => [
            'numaranı ver güzelim', 'bedenin hakkında',
            'özelden yazayım', 'geceleri seni yalnız',
            'give me your number gorgeous', 'won\'t let you sleep tonight',
            'what i\'d do with that body', 'deserve every dirty comment',
            'дай номер красавица', 'спать не дам',
            'грязные комментарии', 'с таким телом',
            'revenge porn', 'intikam pornosu',
            'nude pics for money', 'para karşılığı çıplak',
        ],
        'CSA' => [
            'çocuk pornosu', 'reşit olmayan çıplak',
            'child pornography', 'nude minor',
            'детская порнография', 'обнаженный несовершеннолетний',
            'child sexual abuse material',
        ],
        'VIO' => [
            'işkence videosu', 'kanlı ceset',
            'torture video', 'bloody corpse',
            'видео пыток', 'кровавый труп',
            'yüzünü dağıtırım', 'smash your face', 'лицо разобью',
        ],
        'THR' => [
            'seni öldür', 'seni vuracağım', 'seni geberteceğim',
            'umarım geberirsin', 'hesabını soracağız',
            'adresini biliyorum',
            'i will kill you', 'i will shoot you', 'i will hurt you',
            'smash your face', 'drop dead', 'make you pay',
            'i know your address', 'we\'ll find you',
            'я тебя убью', 'я тебя застрелю', 'я причиню тебе вред',
            'лицо разобью', 'ты сдохнешь', 'знаю твой адрес',
            'заставим заплатить',
        ],
        'SELF' => [
            'intihar edeceğim', 'intihar yöntemi', 'kendimi öldüreceğim',
            'kill myself', 'suicide method', 'how to suicide',
            'want to die tonight', 'end my life',
            'покончить с собой', 'хочу умереть', 'метод самоубийства',
        ],
        'HATE' => [
            'nazi', 'ırkından nefret', 'i hate your race',
            'ненавижу твою расу',
            'göçmenler insan değil', 'hepsini ülkeden sür',
            'kadınların aklı', 'engelliler topluma sadece yük',
            'immigrants are vermin', 'should all be thrown out',
            'women aren\'t intelligent', 'disabled people are nothing but a burden',
            'мигранты паразиты', 'женщинам не хватает ума',
            'инвалиды только обуза', 'чурки никогда',
        ],
        'EXT' => [
            'terör örgütü', 'terrorist attack how', 'join the caliphate',
            'bomb making guide', 'изготовление бомбы',
            'вступить в террор',
        ],
        'CRIME' => [
            'silah satışı', 'how to make a bomb', 'illegal weapons for sale',
            'buy a gun without id', 'изготовление оружия',
        ],
        'DRUG' => [
            'uyuşturucu satışı', 'selling cocaine', 'how to cook meth',
            'mdma satış', 'продажа кокаина', 'варить мет',
        ],
        'SCAM' => [
            'send bitcoin now', 'garanti kazanç yatır', 'western union urgent',
            'nft giveaway seed phrase', 'отправь биткоин',
        ],
        'CYBER' => [
            'steal passwords', 'phishing kit', 'ransomware for sale',
            'взлом аккаунта', 'стиллер паролей',
        ],
        'PRIV' => [
            'tc kimlik numaran', 'doxx', 'social security number is',
            'herkese anlatırım akıllı ol', 'i know things about you',
            'everyone will hear them', 'узнают все',
            'adresini biliyorum', 'i know your address', 'знаю твой адрес',
        ],
        'IMP' => [
            'ben rektörüm', 'official arucad admin', 'я официальный админ',
        ],
        'SPAM' => [
            'follow for follow spam', 'mass dm promo', 'криптосигналы бесплатно',
        ],
        'MISINFO' => [
            'içme suyu zehirlendi kesin', 'campus water is poisoned confirmed',
        ],
        'IP' => [
            'full movie free download pirated', 'crack adobe license',
        ],
        'ANIMAL' => [
            'hayvan işkencesi videosu', 'animal torture video',
            'видео пыток животных',
        ],
        'MINOR' => [
            'meet kids alone privately', 'reşit değilim buluşalım',
            'секретная встреча с ребёнком',
        ],
        // Political campaigning/party content — deliberately named parties,
        // titles and institutions only (never bare words like "seçim" or
        // "hükümet"), because a campus platform's own student-club/council
        // elections are a normal, unrelated topic that those generic words
        // would otherwise catch as false positives.
        'POL' => [
            'akp', 'chp', 'mhp', 'hdp', 'iyi parti', 'cumhurbaşkanı adayı',
            'milletvekili adayı', 'genel seçimlerde oy',
            'republican party', 'democratic party', 'presidential candidate',
            'senate race', 'prime minister candidate',
            'единая россия', 'государственная дума', 'выборы президента',
        ],
    ];

    /**
     * Gap-tolerant phrase families layered on top of BLOCKED_TERMS: each
     * entry is an ordered list of anchor words/phrases that must all appear,
     * in order, within a bounded number of unrelated words of each other —
     * catching a *family* of rephrasing ("seni yarın gerçekten öldürürüm")
     * that an exact BLOCKED_TERMS string only catches verbatim. Anchors are
     * plain TR/EN/RU text (never hand-written regex): each is folded through
     * the same normalize() as every other term before assembly, so the same
     * anti-obfuscation protection applies. Reserved for non-escalate
     * categories only — a fuzzy match on a strike-and-possibly-ban decision
     * (THR/CSA/EXT/SELF/MINOR) stays exact-phrase to keep false positives on
     * a punitive action rare.
     *
     * @var array<string, list<list<string>>>
     */
    private const BLOCKED_PATTERN_FAMILIES = [
        'PROF' => [
            ['sen', 'aptalsın'], ['sen', 'salaksın'],
        ],
        'HAR' => [
            ['hesabını', 'sileceğim'], ['everyone', 'hates you'],
            ['все тебя', 'ненавидят'],
        ],
        'HATE' => [
            ['bu ırktan', 'nefret'], ['kadınlar', 'aptal'],
            ['immigrants', 'vermin'], ['engelliler', 'sadece yük'],
        ],
        'SPAM' => [
            ['dm at', 'kazan'], ['hemen', 'tıkla', 'kazan'],
            ['dm me', 'guaranteed profit'], ['click', 'free followers'],
            ['криптосигнал', 'подпишись'],
        ],
    ];

    /** Max unrelated words tolerated between two anchors of the same family. */
    private const PATTERN_FAMILY_GAP = 6;

    /** Categories that use escalate/ audit prefix (S4/S5-class handling). */
    private const ESCALATE_CATEGORIES = [
        'CSA', 'THR', 'EXT', 'SELF', 'MINOR',
    ];

    /**
     * Checks $text for blocked content. If it's clean, returns null. If
     * it's flagged, records a real strike against $user (banning them once
     * they cross the threshold) and returns the reason to show the caller.
     */
    /**
     * Categories the phrase/severity engine does not model. These stay on
     * the deterministic term list: they are rare, unambiguous, and there is
     * no "aimed at a comment rather than a person" nuance to weigh for a
     * drug sale or a CSA request.
     */
    private const ENGINE_OWNED_CATEGORIES = [
        'PROF', 'HAR', 'SEX', 'THR', 'VIO', 'HATE', 'POL',
    ];

    /** Contexts in which quoting a banned term is not using it. */
    private const EXEMPT_CONTEXTS = [
        'counterspeech', 'reporting_abuse', 'educational',
        'fictional_description', 'self_directed', 'gameplay',
    ];

    /**
     * Checks $text for blocked content. Returns null when it may be
     * published, or the message to show the author when it may not.
     *
     * Two layers run here. TextPolicyEngine weighs severity, who is being
     * addressed and the surrounding context, so an insult aimed at a
     * comment warns while the same word aimed at a person is removed, and a
     * slur quoted in order to report it is left alone. Anything the engine
     * lets through is then checked against the deterministic term list for
     * the categories it does not model (CSA, drugs, scams, and so on).
     */
    public static function checkText(User $user, string $text): ?string
    {
        $verdict = self::evaluate($text);

        if ($verdict->blocksPublication()) {
            $category = $verdict->primaryLabel() ?? 'HAR';
            $prefix = $verdict->isEscalation() ? 'escalate/' : '';
            self::recordStrike($user, $prefix.'metin/'.$category.': '.$verdict->auditSummary());

            return self::messageFor($category);
        }

        if ($verdict->needsReview()) {
            self::queueForReview($user, $text, $verdict);
        } elseif ($verdict->isWarning()) {
            AuditLogger::log('system', 'moderation_warning', 'user', "{$user->name}: ".$verdict->auditSummary());
        }

        // Quoting a term to report or teach about it is not using it, so the
        // deterministic list is skipped in exactly those contexts too.
        if (in_array($verdict->context, self::EXEMPT_CONTEXTS, true)) {
            return null;
        }

        return self::checkDeterministicTerms($user, $text);
    }

    /** The policy decision for $text, with no side effects. */
    public static function evaluate(string $text): ModerationVerdict
    {
        return (new TextPolicyEngine())->evaluate($text);
    }

    private static function checkDeterministicTerms(User $user, string $text): ?string
    {
        $normalized = self::normalize($text);
        foreach (self::BLOCKED_TERMS as $category => $terms) {
            if (in_array($category, self::ENGINE_OWNED_CATEGORIES, true)) {
                continue;
            }
            foreach ($terms as $term) {
                if (! self::containsTerm($normalized, self::normalize($term))) {
                    continue;
                }

                return self::block($user, $category, "\"$term\"");
            }
        }

        return null;
    }

    /**
     * Borderline wording is published but put in front of a moderator —
     * sarcasm, banter and an unnamed target are exactly the cases an
     * automated rule should not be deciding on its own.
     */
    private static function queueForReview(User $user, string $text, ModerationVerdict $verdict): void
    {
        ModerationReport::create([
            'id' => (string) Str::uuid(),
            'kind' => 'text',
            'target_id' => $user->id,
            'target_label' => Str::limit(trim($text), 120),
            'reason' => $verdict->auditSummary(),
            'reported_at' => now(),
            'action' => null,
        ]);

        AuditLogger::log('system', 'moderation_review_queued', 'user', "{$user->name}: ".$verdict->auditSummary());
    }

    private static function messageFor(string $category): string
    {
        if ($category === 'SELF') {
            return 'Bu içerik kendine zarar riski taşıyor ve yayınlanamaz. '
                .'Lütfen yalnız kalma; bir yakınına ulaş veya 112 / yerel kriz hattından destek al.';
        }

        return 'İçerik topluluk kurallarına aykırı olabilecek '
            .self::categoryLabel($category).' içeriyor. Lütfen düzenleyip tekrar dene.';
    }

    private static function block(User $user, string $category, string $matchDescription): string
    {
        $escalate = in_array($category, self::ESCALATE_CATEGORIES, true);
        $prefix = $escalate ? 'escalate/' : '';
        self::recordStrike($user, $prefix."metin/$category: $matchDescription");

        if ($category === 'SELF') {
            return 'Bu içerik kendine zarar riski taşıyor ve yayınlanamaz. '
                .'Lütfen yalnız kalma; bir yakınına ulaş veya 112 / yerel kriz hattından destek al.';
        }

        return 'İçerik topluluk kurallarına aykırı olabilecek '
            .self::categoryLabel($category).' içeriyor. Lütfen düzenleyip tekrar dene.';
    }

    /**
     * True if every anchor in $anchors appears in $normalizedText, in order,
     * each within PATTERN_FAMILY_GAP words of the next. Anchors are
     * normalize()'d before matching so the same anti-obfuscation folding
     * that protects BLOCKED_TERMS applies here too.
     */
    private static function matchesPatternFamily(string $normalizedText, array $anchors): bool
    {
        $parts = array_map(
            static fn (string $a): string => preg_quote(self::normalize($a), '/'),
            $anchors,
        );
        if (in_array('', $parts, true)) {
            return false;
        }
        $gap = '(?:\s+\S+){0,'.self::PATTERN_FAMILY_GAP.'}\s+';
        $pattern = '/'.implode($gap, $parts).'/u';

        return (bool) preg_match($pattern, $normalizedText);
    }

    /**
     * Reserved for an explicit reviewer decision. Local media uploads are
     * held pending; a moderator may apply a strike after reviewing an actual
     * policy violation, without relying on a remote vision service.
     */
    public static function recordImageStrike(User $user, string $reason): void
    {
        self::recordStrike($user, "görsel: $reason");
    }

    /** A local semantic model's high-confidence decision is a real strike. */
    public static function recordMediaViolation(User $user, string $category): void
    {
        $code = self::mediaCategoryCode($category);
        $escalate = in_array($code, self::ESCALATE_CATEGORIES, true);
        $prefix = $escalate ? 'escalate/' : '';
        self::recordStrike($user, $prefix.'medya/'.self::categoryLabel($code));
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        // Zero-width / invisible characters used to evade filters.
        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{180E}]/u', '', $value) ?? $value;

        // Masking stars/hashes: remove without inserting a space so f*u*c*k → fuck.
        $value = str_replace(['*', '#', '•'], '', $value);

        // Leet / Turkish folds first so digits become letters before lookalike folding.
        $value = strtr($value, [
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            '@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's',
            '7' => 't',
        ]);
        // Latin lookalikes → Cyrillic so "тyп0й" and "тупой" share one form.
        $value = strtr($value, [
            'a' => 'а', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'c' => 'с',
            'x' => 'х', 'y' => 'у', 'k' => 'к', 'h' => 'н', 'b' => 'в',
            'm' => 'м', 't' => 'т',
        ]);

        // Keep every Unicode letter: Cyrillic text must be evaluated rather
        // than being stripped before the policy rules see it.
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        // Letter stretched 3+ times for emphasis/evasion ("saaaalak",
        // "fuuuuck") collapses to one occurrence. A normal double letter
        // ("kill", "will", "hall") only ever repeats twice, so this never
        // touches it — both BLOCKED_TERMS and input text go through this
        // same fold, so an existing exact two-letter match is unaffected.
        return preg_replace('/(.)\1{2,}/u', '$1', $value) ?? $value;
    }

    private static function containsTerm(string $text, string $term): bool
    {
        // Turkish case/possessive suffixes turn `salak` into `salaksın` and
        // similar forms. Normalisation has already removed punctuation and
        // leetspeak, so a deterministic substring is the intentional policy
        // match here rather than an English-style word boundary.
        if ($term === '') {
            return false;
        }
        if (str_contains($text, $term)) {
            return true;
        }

        // Also catch a common bypass such as `f.u.c.k` or `s e n i
        // öldür`. The policy remains deterministic: we compare the same
        // normalized characters, only without separators.
        return str_contains(str_replace(' ', '', $text), str_replace(' ', '', $term));
    }

    private static function categoryLabel(string $category): string
    {
        return match ($category) {
            'SEX', 'nudity' => 'cinsel içerik',
            'CSA', 'sexual_exploitation' => 'çocuk güvenliği',
            'VIO', 'graphic_violence' => 'şiddet',
            'THR' => 'tehdit',
            'SELF' => 'kendine zarar',
            'HATE', 'hate_symbol', 'hate_or_harassment' => 'nefret söylemi',
            'HAR' => 'taciz veya hakaret',
            'PROF', 'profanity' => 'küfür veya saldırgan dil',
            'EXT' => 'aşırıcılık',
            'CRIME' => 'yasadışı faaliyet',
            'DRUG' => 'uyuşturucu',
            'SCAM' => 'dolandırıcılık',
            'CYBER' => 'siber kötüye kullanım',
            'PRIV' => 'mahremiyet ihlali',
            'IMP' => 'kimliğe bürünme',
            'SPAM' => 'spam',
            'MISINFO' => 'zararlı yanlış bilgi',
            'IP' => 'telif ihlali',
            'ANIMAL' => 'hayvan istismarı',
            'MINOR' => 'çocuk güvenliği',
            'POL' => 'siyasi içerik',
            'credible_threat' => 'tehdit',
            default => 'küfür veya saldırgan dil',
        };
    }

    private static function mediaCategoryCode(string $category): string
    {
        return match ($category) {
            'nudity' => 'SEX',
            'sexual_exploitation' => 'CSA',
            'graphic_violence' => 'VIO',
            'hate_symbol', 'hate_or_harassment' => 'HATE',
            default => $category,
        };
    }

    private static function recordStrike(User $user, string $reason): void
    {
        $user->increment('strikes');
        $user->refresh();
        AuditLogger::log('system', 'moderation_strike', 'user', "{$user->name} ({$user->strikes}/".self::BAN_AFTER_STRIKES."): $reason");

        if ($user->strikes >= self::BAN_AFTER_STRIKES && ! $user->isBanned()) {
            $user->update(['banned_at' => now()]);
            AuditLogger::log('system', 'ban', 'user', "{$user->name} otomatik olarak yasaklandı (".self::BAN_AFTER_STRIKES." ihlal)");
        }
    }
}
