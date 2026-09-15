<?php

namespace App\Services\Moderation;

/**
 * Spam and fraud recognised by shape rather than vocabulary.
 *
 * A phrase list is the wrong tool for these two categories and the
 * benchmark said so plainly: SPAM sat at 17% recall and SCAM at 38% while
 * profanity was at 90%. The reason is that spam has no vocabulary. "жми
 * сюда жми сюда жми сюда" is spam because it repeats, not because any word
 * in it is forbidden; "1000 takipçi 50TL" is spam because it is a price
 * list, and next week it will be a different price and a different product.
 * Listing the words is chasing a moving target.
 *
 * What does not move is the structure: repetition, shouting, a throwaway
 * domain, a number multiplied by a promise. Each signal alone is innocent —
 * plenty of legitimate posts shout, and plenty include a link — so nothing
 * here fires on one. Two independent signals is the bar, which is what
 * keeps club recruitment and second-hand sales out of it.
 *
 * Two text forms are used on purpose, and getting this wrong is silent:
 *
 *   - **Word signals** run on the canonical text with the needles put
 *     through the same canonicaliser. Normalisation folds Cyrillic letters
 *     to Latin lookalikes, so "продаю" becomes "пpoдaю" — a pure-Cyrillic
 *     needle matches nothing, and every Russian signal here scored zero
 *     until the needles were folded too.
 *   - **Digit and link signals** run on the *original* text. The
 *     de-obfuscation pass maps 1→i, 0→o, 3→e, so "1000 за 300" canonicalises
 *     to "io za eoo" and no amount of money is visible in it at all.
 */
class StructuralSignals
{
    /**
     * Free registrars that legitimate institutions do not use and that
     * phishing kits do, plus the shorteners that hide where a link goes.
     */
    private const THROWAWAY_HOSTS = [
        '.tk', '.ml', '.ga', '.cf', '.gq', '.xyz', '.top', '.click', '.link',
        'bit.ly', 'tinyurl', 'goo.gl', 't.co/', 'is.gd', 'cutt.ly', 'shorturl',
    ];

    /** Asking for the thing that should never be typed into a link. */
    private const SECRET_NOUNS = [
        'sifre', 'parola', 'kart bilgi', 'kart numara', 'tc kimlik', 'kimlik numara',
        'cvv', 'iban',
        'password', 'card number', 'card details', 'credit card', 'pin code',
        'login details', 'bank details',
        'пароль', 'номер карты', 'данные карты', 'реквизит', 'пин код',
    ];

    /** Somewhere to type it. */
    private const ENTRY_VERBS = [
        'gir', 'girin', 'giriniz', 'yaz', 'yazin', 'dogrula', 'onayla', 'guncelle',
        'enter', 'verify', 'confirm', 'update', 'submit', 'claim',
        'введите', 'ввести', 'подтвердите', 'обновите', 'укажите',
    ];

    /** The promise that makes an offer fraudulent rather than optimistic. */
    private const GUARANTEE_WORDS = [
        'garanti', 'garantili', 'kesin kazanc', 'risksiz',
        'guaranteed', 'guarantee', 'risk free', 'no risk', 'limited spots',
        'гарантированно', 'гарантия', 'без риска', 'без вложений',
    ];

    /** Words that make a repeated short line commercial rather than chatty. */
    private const COMMERCE_WORDS = [
        'takipci', 'satis', 'satiyorum', 'kampanya', 'bedava', 'kazan', 'tikla',
        'followers', 'cheap', 'free money', 'click here', 'buy now', 'dm me',
        'подписчик', 'продаю', 'бесплатн', 'жми', 'заработ',
    ];

    /**
     * Signals found in the text, as `name:evidence` strings.
     *
     * Returned rather than a boolean so the audit row can say *why* — "this
     * was removed as spam" is not an answer anyone can check.
     *
     * @return list<string>
     */
    public static function spam(string $canonical, string $original): array
    {
        $signals = [];

        if ($phrase = self::repeatedPhrase($canonical)) {
            $signals[] = 'repeat:'.$phrase;
        }

        if (self::isShouting($original)) {
            $signals[] = 'shouting';
        }

        if ($host = self::throwawayLink($original)) {
            $signals[] = 'link:'.$host;
        }

        if ($word = self::firstMatch($canonical, self::COMMERCE_WORDS)) {
            $signals[] = 'commerce:'.$word;
        }

        return $signals;
    }

    /**
     * @return list<string>
     */
    public static function scam(string $canonical, string $original): array
    {
        $signals = [];

        // Credential phishing: a request for a secret *and* somewhere to
        // put it. Either alone is ordinary — "never share your password" is
        // the university's own advice and must stay publishable.
        $secret = self::firstMatch($canonical, self::SECRET_NOUNS);
        $entry = self::firstMatch($canonical, self::ENTRY_VERBS);
        if ($secret !== null && $entry !== null) {
            $signals[] = 'credentials:'.$secret.'+'.$entry;
        }

        if ($word = self::firstMatch($canonical, self::GUARANTEE_WORDS)) {
            $signals[] = 'guarantee:'.$word;
        }

        if (self::multipliedMoney($original)) {
            $signals[] = 'multiplied_money';
        }

        if ($host = self::throwawayLink($original)) {
            $signals[] = 'link:'.$host;
        }

        return $signals;
    }

    /**
     * A short phrase repeated three or more times.
     *
     * Three, not two: "reminder: reminder:" is someone being emphatic, and
     * the safe set contains exactly that sentence.
     */
    private static function repeatedPhrase(string $canonical): ?string
    {
        $words = preg_split('/\s+/u', trim($canonical)) ?: [];
        if (count($words) < 6) {
            return null;
        }

        $counts = [];
        for ($size = 2; $size <= 3; $size++) {
            for ($i = 0; $i + $size <= count($words); $i++) {
                $gram = implode(' ', array_slice($words, $i, $size));
                if (mb_strlen($gram) < 6) {
                    continue;
                }
                $counts[$gram] = ($counts[$gram] ?? 0) + 1;
            }
        }

        arsort($counts);
        $top = array_key_first($counts);

        return ($top !== null && $counts[$top] >= 3) ? $top : null;
    }

    /**
     * Written in capitals with excessive punctuation.
     *
     * Both, not either: a short all-caps line is someone being emphatic,
     * and a row of exclamation marks on its own is enthusiasm.
     */
    private static function isShouting(string $original): bool
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', $original) ?? '';
        if (mb_strlen($letters) < 12) {
            return false;
        }

        $upper = preg_replace('/[^\p{Lu}]+/u', '', $original) ?? '';
        $ratio = mb_strlen($upper) / max(1, mb_strlen($letters));

        return $ratio > 0.6 && preg_match('/[!?]{3,}/u', $original) === 1;
    }

    private static function throwawayLink(string $canonical): ?string
    {
        foreach (self::THROWAWAY_HOSTS as $host) {
            if (str_contains($canonical, $host)) {
                return $host;
            }
        }

        return null;
    }

    /**
     * A small amount promised as a larger one — the shape of every
     * advance-fee offer, in any currency and any language.
     */
    private static function multipliedMoney(string $canonical): bool
    {
        if (! preg_match_all('/(?<!\d)(\d{2,7})(?!\d)/u', $canonical, $m)) {
            return false;
        }

        $numbers = array_map('intval', $m[1]);
        sort($numbers);

        $smallest = $numbers[0];
        $largest = end($numbers);

        return count($numbers) >= 2 && $smallest >= 10 && $largest >= $smallest * 3;
    }

    /**
     * Matches needles against canonical text.
     *
     * The needles are canonicalised with the same normaliser the text went
     * through, because it folds Cyrillic to Latin lookalikes: a needle
     * written in real Russian cannot match text that has been folded out of
     * it. The lexicon's phrase matcher already does this; this did not, and
     * every Russian signal here silently scored zero.
     *
     * Cached because the needle lists are constants and this runs on every
     * post.
     *
     * @param  list<string>  $needles
     */
    private static function firstMatch(string $haystack, array $needles): ?string
    {
        static $folded = [];

        $key = md5(serialize($needles));
        if (! isset($folded[$key])) {
            $normalizer = new TextNormalizer;
            $folded[$key] = [];
            foreach ($needles as $needle) {
                $canonical = $normalizer->canonicalizeFragment($needle);
                if ($canonical !== '') {
                    $folded[$key][$canonical] = $needle;
                }
            }
        }

        foreach ($folded[$key] as $canonical => $original) {
            if (str_contains($haystack, $canonical)) {
                return $original;
            }
        }

        return null;
    }
}
