<?php

namespace App\Services\Moderation;

/**
 * The Turkish / English / Russian term and phrase tables the policy engine
 * reasons over.
 *
 * Two kinds of entry live here:
 *  - Word lists (INSULT_*, PROFANITY_*) matched on token prefix, so
 *    agglutinated forms ("aptal" → "aptalsın", "идиот" → "идиотом") are
 *    covered without listing every inflection.
 *  - PHRASE_RULES, matched as whole phrases, each carrying the policy
 *    outcome it implies. A phrase rule is how the engine tells apart
 *    "your brain took the day off" (a jab, warn) from "the room improves
 *    when you leave" (denial of worth, remove) — both are euphemisms, and
 *    a bare word list cannot separate them.
 *
 * Everything is written in plain language; TextNormalizer folds both these
 * entries and the incoming text into the same comparable form.
 */
final class PolicyLexicon
{
    /** Person-directed insults that warrant removal when aimed at someone. */
    public const INSULT_STRONG = [
        // Turkish
        'aptal', 'salak', 'gerizekali', 'gerzek', 'ahmak', 'mal', 'ezik', 'beceriksiz',
        'serefsiz', 'pic', 'orospu', 'yavsak', 'sersem', 'embesil', 'moron',
        'denyo', 'godos', 'hayvan herif', 'yuz karasi',
        // English
        'idiot', 'moron', 'dumbass', 'dumbfuck', 'asshole', 'arsehole', 'loser',
        'shithead', 'shit head', 'dipshit', 'jackass', 'halfwit', 'nitwit',
        'imbecile', 'retard', 'scumbag', 'bitch', 'bastard', 'jerk', 'douchebag',
        'incompetent', 'pathetic', 'worthless', 'disgrace', 'stupid',
        // Russian
        'идиот', 'дебил', 'мудак', 'мраз', 'придурок', 'кретин', 'тупиц', 'тупой',
        'тупая', 'бездар', 'ничтожеств', 'урод', 'ублюдок', 'сволоч', 'никчём',
        'никчем', 'неудачник',
    ];

    /**
     * Obscene personal insults. Unlike INSULT_STRONG these are removed even
     * when aimed at a piece of work: "this comment is stupid" is harsh
     * criticism, "this comment is a piç" is not criticism at all.
     */
    public const INSULT_VULGAR = [
        'pic', 'orospu', 'yavsak', 'serefsiz', 'amcik', 'godos',
        'asshole', 'arsehole', 'bitch', 'bastard', 'cunt', 'dumbfuck', 'whore', 'slut',
        'мудак', 'мраз', 'ублюдок', 'сволоч', 'пизд', 'шлюх',
    ];

    /** Milder taunts — a warning rather than a removal. */
    public const INSULT_MILD = [
        // `korkak tavuk` as a phrase, not the bare words. "Tavuklu olan
        // gerçekten iyiydi" is somebody saying the chicken was nice, and
        // listing `tavuk` warned them for it; English `chicken` has the
        // same problem on a canteen menu. The insult is the pairing.
        'korkak', 'korkak tavuk', 'cabuk kizan',
        'coward', 'crybaby', 'clown',
        'трус', 'нытик', 'клоун',
    ];

    /** Coarse language that is not, by itself, aimed at a person. */
    public const PROFANITY = [
        'lanet', 'bok', 'kahretsin', 'hasiktir',
        'damn', 'shit', 'crap', 'bloody hell', 'xscat',
        'чёрт', 'черт', 'дерьм', 'блин',
    ];

    /**
     * Strong obscenity: still allowed untargeted, removed when targeted.
     *
     * These are ROOTS, not finished words. `matchList` matches a token
     * that *starts with* one of them, which is what carries inflection:
     * Turkish is agglutinative, so one root has to cover "sikerim",
     * "sikeyim", "siktiğimin", "sikik"; Russian declines, so "хуй" has to
     * cover "хуя", "хуём", "нахуй".
     *
     * Listing finished words instead is why "siktir git" was refused
     * while "siktir", "sikerim seni" and "sikik herif" published. A filter
     * that can be beaten by a suffix is a filter students learn to beat.
     *
     * Every root here is at least three characters, because `matchList`
     * only prefix-matches at that length — shorter ones (`oç`, `aq`) are
     * exact-token entries below and cannot inflect anyway.
     *
     * ROOTS THAT ENDANGER ORDINARY WORDS ARE GUARDED in
     * FALSE_POSITIVE_GUARD. The Turkish ones matter most because
     * normalisation folds ş→s: the root `sik` would otherwise refuse
     * "şikayet" — *complaint* — which is among the words a campus app
     * most needs to accept.
     */
    public const PROFANITY_STRONG = [
        // Turkish roots.
        // `yarra`, not `yarrak`: Turkish softens a final k to ğ before a
        // vowel suffix, so the root appears as "yarrağımı". Any root
        // ending in k, p, t or ç needs the softened form covered, and
        // truncating before the final consonant is the cheapest way.
        // ("yarın" — tomorrow — begins "yari", so this is safe.)
        'sik', 'sok', 'yarra', 'yarag', 'amcik', 'amina', 'amina koy',
        'orospu', 'kahpe', 'gotver', 'gotlek', 'pezeven', 'godos',
        // Common SMS abbreviations. Exact tokens: they do not inflect.
        'amk', 'aq', 'oc', 'mk', 'aminakoyim',
        // English roots. No ordinary English word begins with these.
        'fuck', 'fuk', 'fck', 'fvck', 'phuck', 'motherfuck', 'mofo',
        'cunt', 'bitch', 'asshole', 'arsehole', 'bastard', 'whore', 'slut',
        'stfu', 'wtf',
        // Russian roots. Declension is why these are stems.
        'хуй', 'хуя', 'хуё', 'хуе', 'нахуй', 'похуй', 'хуев',
        'пизд', 'ебат', 'ебан', 'ебал', 'ебуч', 'ёбан', 'заеб',
        'блядь', 'бляд', 'блять', 'бля', 'сука', 'суки', 'сучар',
        'мудак', 'мудил', 'пидор', 'пидар', 'гандон', 'долбоёб', 'долбоеб',
    ];

    /**
     * Abuse by construction — refused whether or not a target is found.
     *
     * The engine otherwise allows strong obscenity until it can see who
     * it is aimed at, which is a reasonable rule for an exclamation
     * ("fuck, I left my laptop"). It is the wrong rule for an imperative:
     * "siktir", "fuck off" and "иди на хуй" are aimed at somebody by
     * their grammar, and waiting for a pronoun to prove it is how
     * "siktir git" came to be refused while a bare "siktir" published.
     *
     * Deliberately narrow. Only forms that cannot be said *about* a
     * situation, only *at* a person, belong here.
     */
    public const DIRECTED_PROFANITY = [
        'siktir', 'sikerim seni', 'sikeyim seni', 'gotver', 'gotlek',
        // Written as a phrase because it is usually spaced ("göt veren"),
        // and because the bare root `göt` is deliberately not listed:
        // "götürmek" — to take — is everyday Turkish.
        'got veren', 'gotunu',
        // First-person possessive + accusative ("yarrağımı ye"): the form
        // only exists to order somebody to act on it, so unlike the bare
        // root it has no exclamatory reading.
        'yarragimi', 'yarragi ye',
        // "Amına koyayım" is NOT here: in Turkish it is overwhelmingly an
        // exclamation about a situation ("amına koyayım böyle sistemin"),
        // and listing it refused ordinary frustration. It stays on the
        // severe-profanity path, which warns.
        'orospu cocugu',
        'fuck you', 'fuck off', 'fuk off', 'fck you', 'stfu',
        'иди на хуй', 'пошел на хуй', 'нахуй пошел', 'иди нахуй',
    ];

    /**
     * Particles that turn any obscenity into a dismissal aimed at a person.
     *
     * These carry the target, so the obscenity in front of them can be
     * spelled any way at all — which is the point: "fuck off" was listed
     * and "phuck off" was not.
     */
    public const DISMISSAL_PARTICLES = [
        'off', 'you', 'u', 'yourself', 'urself', 'ur',
    ];

    /**
     * Explicit sexual solicitation — a category the policy claimed and the
     * engine did not have.
     *
     * Every SEX rule in PHRASE_RULES is about harassing a *person*: quid
     * pro quo, coercion, objectifying someone by name. None of them covers
     * somebody posting "group sex, do u want to come in" to a public feed,
     * and testing found that exact post publishing. The semantic layer did
     * not save it either — its SEX exemplars are harassment-shaped, so
     * "group sex" scored **negative** (-0.121).
     *
     * These three lists are read together by
     * TextPolicyEngine::sexualSolicitation(), which needs an explicit term
     * AND an invitation AND no academic framing. All three matter:
     *
     *   "who wants to have sex"            -> refused
     *   "sex education seminar on Tuesday" -> published
     *   "the sex of participants was recorded" -> published (no invitation)
     *
     * Matched as WHOLE TOKENS, never prefixes. `sex` prefixes `sexual`, and
     * "sexual harassment awareness workshop" is a post this university
     * needs to be able to make.
     */
    public const SEXUAL_EXPLICIT_TOKENS = [
        'sex', 'seks', 'секс', 'sekse', 'seksi yapmak',
        'nudes', 'threesome', 'orgy', 'gangbang', 'blowjob', 'handjob',
        'hookup', 'horny', 'porn', 'porno', 'porna',
        'sikis', 'sikismek', 'sakso', 'orji',
        'минет', 'порно', 'порнуха', 'оргия',
        // Misspellings and initialisms. `fwb` is an abbreviation students
        // use precisely because a word list would not know it.
        'sexs', 'sexx', 'seksss', 'fwb',
    ];

    /** Compound terms unambiguous enough to stand alone. */
    public const SEXUAL_EXPLICIT_PHRASES = [
        'group sex', 'gorup sex', 'grup seks', 'toplu seks', 'grup sex',
        'oral sex', 'anal sex', 'oral seks', 'anal seks',
        'one night stand', 'tek gecelik', 'casual sex', 'sex party',
        'seks partisi', 'групповой секс', 'оральный секс', 'анальный секс',
        'секс вечеринка',
        // As phrases, not the bare word. `fuck` on its own is an
        // exclamation far more often than a proposition, and listing it as
        // an explicit token would have refused "fuck, I want to go home".
        'want to fuck', 'wants to fuck', 'wanna fuck', 'down to fuck',
        'friends with benefits', 'wanna smash', 'want to smash',
        'sikismek isteyen', 'yatmak isteyen',
    ];

    /**
     * An invitation. Without one of these an explicit word is being used
     * to describe something, not to propose it.
     */
    public const SOLICITATION_MARKERS = [
        'want', 'wants', 'wanna', 'join', 'come', 'coming', 'dm', 'pm',
        'message', 'text', 'tonight', 'anyone', 'who', 'interested',
        'meet', 'meetup', 'down', 'up',
        'isteyen', 'ister', 'istiyor', 'gelmek', 'gel', 'gelsin', 'yazsin',
        'yaz', 'bulusalim', 'bulusmak', 'musun', 'misin', 'var',
        'хочешь', 'хочет', 'кто', 'приходи', 'встретимся', 'пиши', 'желающие',
        // Plural imperatives: "приходите", not "приходи". Russian inflects
        // the invitation itself, and only the singular was listed — so
        // "групповой секс приходите" found no marker and published.
        'приходите', 'пишите', 'присоединяйтесь', 'хотите', 'ищу', 'ищем',
        'kim', 'arayan', 'ariyorum', 'gelen',
        'looking', 'seeking', 'free', 'available',
    ];

    /**
     * Academic, clinical or safeguarding framing. Its presence means the
     * explicit word belongs to a subject being taught or a warning being
     * given, and the post publishes.
     */
    public const SEXUAL_ACADEMIC_CONTEXT = [
        'egitim', 'egitimi', 'seminer', 'semineri', 'calistay', 'calistayi',
        'panel', 'paneli', 'ders', 'dersi', 'konferans', 'taciz', 'tacize',
        'saglik', 'sagligi', 'siddet', 'siddeti', 'bilinclendirme',
        'farkindalik', 'arastirma', 'tez', 'rapor', 'politika', 'yonerge',
        'riza', 'basvuru', 'bildirim',
        'education', 'educational', 'seminar', 'workshop', 'panel', 'lecture',
        'course', 'module', 'curriculum', 'harassment', 'assault', 'abuse',
        'health', 'consent', 'awareness', 'studies', 'research', 'thesis',
        'dissertation', 'policy', 'violence', 'safeguarding', 'reporting',
        'prevention', 'counselling', 'counseling', 'clinic',
        'образование', 'семинар', 'лекция', 'курс', 'насилие',
        'домогательств', 'здоровье', 'исследование', 'политика',
        'профилактика', 'согласие',
    ];

    /** Obscenity coarse enough to warn on even with no target. */
    public const PROFANITY_SEVERE = [
        'amina koy', 'amina koyay', 'ananin ami', 'avradini',
    ];

    /**
     * Ordinary words that a short insult term happens to prefix. Without
     * this, "mal" flags "malzeme"/"mall" and "piç" flags "picture" — the
     * kind of false positive that makes a filter untrustworthy and gets it
     * switched off.
     */
    public const FALSE_POSITIVE_GUARD = [
        'malzeme', 'malzemeler', 'maliyet', 'mali', 'maliye', 'malum', 'mallar', 'malta',
        'mall', 'malls', 'malformed', 'mally',
        'picture', 'pictures', 'pics', 'picnic', 'pick', 'picked', 'picking', 'pixel',
        'bokal', 'bokser',
        'crapshoot', 'shirt', 'shift',
        'тупик', 'тупика', 'мразьте',

        // --- Guards for the roots added with inflection support --------
        //
        // Turkish normalisation folds ş→s, so the root `sik` reaches
        // "şikayet" — *complaint*. Refusing that on a university app
        // would block the word students need most when something has
        // gone wrong, which is close to the worst possible failure.
        'sikayet', 'sikayetci', 'sikayetler', 'sikayetleri', 'sikayetimiz',
        'sikayette', 'sikayetten', 'sikayetname',
        'sikke', 'sikkeler', 'siklet', 'siklon', 'sikago',
        // `sok` reaches "sokak" (street), "sokmak", "soket".
        'sokak', 'sokaga', 'sokakta', 'sokaklar', 'soket', 'sokum',
        // `gotver`/`gotlek` are specific enough not to need guards, but
        // the shorter `got` is deliberately NOT a root for this reason:
        // "götürmek" (to take) is everyday Turkish.
        //
        // English: `bitch` and `bastard` are clean, but `mofo`/`fck` are
        // short enough to prefix-match oddities, and Scunthorpe is the
        // canonical reminder that substring filters embarrass themselves.
        'scunthorpe', 'penistone', 'mofos',
        // `dick` is deliberately NOT a root: it is a given name and a
        // surname prefix ("Dickens", "Dickinson").
        //
        // Russian: `хуй`-family roots are safe, but `ху` alone would
        // reach "художник" (artist) — which is why the roots below are
        // full three-letter forms, not the bare stem.
        'художник', 'художника', 'художники', 'художественная',
        'художественный', 'художество',
        // `сук` would reach "сукно" (cloth); the roots listed are the
        // inflected insult forms instead.
        'сукно', 'сукна', 'суконный',
        // `муд` would reach "мудрость" (wisdom).
        'мудрость', 'мудрый', 'мудрая', 'мудро',
        // `бля` would reach "блины" (pancakes) only if truncated; guarded
        // anyway because breakfast is not abuse.
        'блин', 'блины', 'блинов', 'блинная',
        'хертфордшир', 'херувим',
    ];

    /**
     * The text is *reporting* something rather than doing it.
     *
     * A student who writes «he said "you will regret it", who do I report
     * this to?» is quoting a threat in order to escalate it. Refusing that
     * message punishes the person the policy exists to protect, and teaches
     * everyone watching that reporting is the thing that gets you blocked.
     *
     * These downgrade rather than clear: the text still reaches a moderator,
     * because a real threat wrapped in "asking for a friend" must not
     * publish on the strength of one phrase. See
     * TextPolicyEngine::isReportingContext().
     */
    public const REPORTING_CONTEXT = [
        // Turkish
        'bildirebilirim', 'bildirmek istiyorum', 'nereye bildir', 'kime bildir',
        'sikayet etmek istiyorum', 'sikayet edebilir miyim', 'nereye basvur',
        'diye yazdi', 'diye mesaj atti', 'boyle yazdi', 'soyle yazdi',
        'bana yazdi', 'tehdit etti', 'tehdit ediyor', 'ne yapmaliyim',
        // English
        'who do i report', 'how do i report', 'where do i report',
        'i want to report', 'should i report', 'reporting this',
        'he said', 'she said', 'they said', 'messaged me saying',
        'wrote to me saying', 'sent me this', 'is this a threat',
        'what should i do', 'threatened me',
        // Russian
        'куда пожаловаться', 'кому пожаловаться', 'как пожаловаться',
        'хочу пожаловаться', 'написал мне', 'написала мне', 'мне написали',
        'угрожает мне', 'угрожал мне', 'что мне делать', 'это угроза',
    ];

    /**
     * The contact detail being shared belongs to the author.
     *
     * Publishing your own number to a study group is an ordinary thing a
     * student does and their own decision to make. Before this, any Turkish
     * mobile number in any post was treated as doxxing — including
     * "benim numaram ...", which is the single commonest way a number
     * appears on a campus feed.
     */
    public const OWN_CONTACT_MARKERS = [
        'benim numaram', 'numaram ', 'benim telefonum', 'telefonum ',
        'bana ulasabilirsiniz', 'bana ulasmak icin', 'benim mailim',
        'benim e postam', 'benim adresim',
        'my number is', 'my number:', 'my phone is', 'my phone:',
        'my email is', 'my email:', 'you can reach me', 'contact me on',
        'мой номер', 'мой телефон', 'моя почта', 'мой адрес',
        'со мной можно связаться', 'пишите мне на',
    ];

    /** Second-person markers: the text is speaking *to* someone. */
    public const SECOND_PERSON = [
        'sen', 'seni', 'sana', 'senin', 'sende', 'senden', 'siz', 'sizi', 'size',
        'you', 'your', 'youre', 'yours', 'yourself', 'u',
        'ты', 'тебя', 'тебе', 'тобой', 'твой', 'твоя', 'твоё', 'твое', 'твои', 'вы',
    ];

    /** Turkish 2nd-person verb endings ("davranıyorsun", "tekisin"). */
    public const TURKISH_SECOND_PERSON_SUFFIXES = ['sin', 'sun', 'siniz', 'sunuz'];

    /** "bu ezik", "this loser", "этот жалкий" — points at a present person. */
    public const DEMONSTRATIVES = [
        'bu', 'su', 'o', 'this', 'that', 'этот', 'эта', 'тот', 'такой', 'такая',
    ];

    /** Nouns an insult attaches to when naming a person indirectly. */
    public const PERSON_NOUNS = [
        'herif', 'adam', 'tip', 'kisi', 'cocuk', 'kadin',
        'guy', 'man', 'dude', 'person', 'people', 'kid',
        'человек', 'тип', 'парень', 'мужик',
    ];

    /**
     * Markers that the insult is aimed at work/content, not the person.
     * Single words here are matched as whole tokens — "работа" (the noun)
     * must not be found inside "работает" (the verb), or swearing at a
     * broken system reads as an attack on somebody's work.
     */
    public const CONTENT_TARGETS = [
        'yorum', 'yorumu', 'proje', 'projeyi', 'gonderi', 'paylasim', 'yazanin',
        'yazdigin', 'tasarim', 'yaptigin is', 'isin icine', 'bu yazi',
        'comment', 'comments', 'post', 'posts', 'project', 'article', 'wrote this',
        'your work', 'this code', 'the code', 'design',
        'комментарий', 'комментарии', 'проект', 'проекта', 'работа', 'работу',
        'пост', 'кто это написал', 'статья',
    ];

    /**
     * Terms whose spelling collides with an everyday word in another
     * language, so they only count when the text actually looks Turkish.
     * "piç" folds to "pic", which is also an ordinary English word.
     */
    public const REQUIRES_TURKISH_HINT = ['pic'];

    /**
     * Ordered phrase rules. `kind` selects the decision path in the engine;
     * the first matching rule of the highest severity wins.
     *
     * @var list<array{kind: string, labels: list<string>, severity: string, phrases: list<string>}>
     */
    /**
     * Words that are only ever slurs.
     *
     * These carry no neutral use: there is no sentence where calling
     * somebody one of these is anything but an attack on who they are. So
     * unlike an insult, no target pronoun is needed — the word alone is the
     * violation. Quoting one to condemn, report or teach about it is still
     * allowed, because the exemption pass runs before this (see
     * TextPolicyEngine::evaluate and the counterspeech corpus cases).
     *
     * Kept deliberately separate from PROTECTED_GROUPS below: "siyahi" and
     * "arap" are ordinary words for who someone is and must never be
     * blocked on their own.
     *
     * @var list<string>
     */
    public const SLURS = [
        // Racial — Turkish
        'zenci', 'zenciler', 'arap kokulu', 'maymun gibi siyah',
        // Racial — English
        'nigger', 'nigga', 'niggas', 'niggers', 'negro', 'coon', 'jigaboo',
        // Racial — Russian
        'ниггер', 'негритос', 'черномазый',
        // Ethnic — Turkish
        'cingene', 'kiro', 'moruk kurt', 'pkkli', 'terorist kurt',
        // Ethnic — Russian/other
        'чурка', 'чурки', 'хач', 'жид', 'жиды', 'хохол',
        // Ethnic — English
        'kike', 'spic', 'chink', 'gook', 'wetback', 'towelhead', 'raghead',
        'paki', 'gypsy scum',
        // Homophobic / transphobic
        'ibne', 'ibneler', 'top oglan', 'nonos',
        // As compounds only. Bare `top` is a ball, and "top oynamak" is
        // what half the sports posts on this campus are about.
        'top herif', 'top mudur', 'ibnelik', 'gotveren herif',
        'faggot', 'faggots', 'fag', 'tranny', 'dyke',
        'пидор', 'пидорас', 'петух',
        // Ableist
        'sakat herif', 'ozurlu herif', 'mongol',
        'retard', 'retarded', 'spastic', 'cripple',
        'даун', 'дебил',
    ];

    /**
     * Identity words that are perfectly normal on their own.
     *
     * "Siyahi", "Kürt", "Arap", "Suriyeli" are how people describe
     * themselves and each other, and a filter that blocks them makes it
     * impossible to talk about identity at all — including to discuss
     * racism. They only become an attack when paired with something
     * hostile, which is what HOSTILE_MODIFIERS is for.
     *
     * @var list<string>
     */
    public const PROTECTED_GROUPS = [
        'siyahi', 'siyahiler', 'siyah irk', 'zencı',
        'arap', 'araplar', 'kurt', 'kurtler', 'ermeni', 'ermeniler',
        'suriyeli', 'suriyeliler', 'afgan', 'afganlar', 'gocmen', 'gocmenler',
        'multeci', 'multeciler', 'yahudi', 'yahudiler', 'musluman', 'muslumanlar',
        'hristiyan', 'hristiyanlar', 'alevi', 'aleviler', 'rum', 'rumlar',
        'kadinlar', 'erkekler', 'engelliler', 'escinseller', 'translar',
        'black people', 'jews', 'muslims', 'christians', 'arabs', 'kurds',
        'immigrants', 'refugees', 'gay people', 'trans people', 'disabled people',
        'women', 'foreigners',
        'евреи', 'мусульмане', 'мигранты', 'беженцы', 'женщины', 'инвалиды',
    ];

    /**
     * Hostility that turns naming a group into attacking it.
     *
     * @var list<string>
     */
    public const HOSTILE_MODIFIERS = [
        'lanet', 'pis', 'igrenc', 'asagilik', 'adi', 'serefsiz', 'pislik',
        'dolu', 'tohumu', 'bozuntusu', 'kirmasi', 'melez',
        'defol', 'defolsun', 'gitsin', 'gitsinler', 'kovun', 'sinir disi',
        'istemiyoruz', 'terorist', 'hain', 'kopek', 'hayvan', 'maymun',
        'parazit', 'bocek', 'olsun', 'gebersin', 'gebersinler', 'yok edilmeli',
        'temizlenmeli', 'insan degil', 'asalak', 'ustun irk', 'alt irk',
        'damn', 'filthy', 'dirty', 'disgusting', 'vermin', 'parasite',
        'subhuman', 'inferior', 'scum', 'should die', 'get out', 'go home',
        'kick them out', 'throw them out', 'not human', 'animals',
        'грязные', 'мрази', 'паразиты', 'убирайтесь', 'не люди', 'выгнать',
    ];

    public const PHRASE_RULES = [
        [
            'kind' => 'threat',
            'labels' => ['THR', 'VIO'],
            'severity' => 'S4',
            'phrases' => [
                'yuzunu dagitirim', 'seni oldur', 'seni geberte', 'gebertirim', 'seni vurac',
                'canini yakarim', 'parcalarim seni', 'seni bulup',
                // 'seni bulacagim' on its own is not a threat — "yarın
                // seni bulacağım, notları vermem lazım" is an ordinary
                // sentence and was being refused. The menace is in the
                // plural: a group announcing it will find you.
                'seni bulacagiz', 'seni bulacaz', 'sizi bulacagiz',
                'seni bulacagim~pisman', 'seni bulacagim~merak etme',
                'hesabini soracagiz', 'kemiklerini kirarim',
                // Measured gaps. Turkish inflects the verb at the end, so
                // "canını yakarım" was listed but "canını yakacağım" —
                // the commoner future form — went straight through. The
                // stem plus the gap operator covers the whole family.
                'canini~yak', 'canini yakac', 'canini yakaca',
                // Bringing a weapon to campus. Anchored on the weapon AND
                // an arrival verb so it cannot fire on the game-design or
                // war-photography posts in the safe set, which mention
                // weapons without anyone arriving anywhere with one.
                'bicakla~gelec', 'bicakla~gelic', 'silahla~gelec',
                'birini~bicaklay',
                'coming for you tomorrow',
                'smash your face', 'i will kill you', 'ill kill you', 'i will hurt you',
                'beat you up', 'we will find you', 'well find you', 'make you pay',
                'break your legs', 'come after you',
                'лицо разобью', 'я тебя убью', 'тебя найдём', 'тебя найдем',
                'заставим заплатить', 'тебе конец', 'я тебя урою',

                // Third-person threats. Everything above is aimed at "you",
                // so a threat about somebody else — the commonest shape in
                // a group post — went straight through: "onu geberteceğim",
                // "I will break his legs", "я его убью" all published.
                'onu~gebert', 'onu~oldur', 'onu~hallede', 'onu~yasatmam',
                'gebertece', 'gebertic', 'gebertirim', 'gebertecegim',
                'kemiklerini~kir', 'kafasini~kir', 'canina~oku',
                'elimden~kurtulamaz', 'yasatmayacagim', 'pisman edecegim',
                'break his legs', 'break her legs', 'break their legs',
                'i will break his', 'ill break his', 'i will break her',
                'gonna kill him', 'going to kill him', 'i will kill him',
                'i will kill her', 'ill kill him', 'put him in hospital',
                'put her in hospital', 'he is dead', 'hes a dead man',
                'wont walk again', 'will not walk again',
                'я его убью', 'я её убью', 'я ее убью', 'ноги переломаю',
                'переломаю ему', 'переломаю ей', 'ему конец', 'ей конец',
                'разберёмся с ним', 'разберемся с ним', 'разберёмся с ней',
                'приедем и разберёмся', 'приедем и разберемся',

                // Lying in wait. The threat is the pairing — a bare
                // "don't go out alone" is a friend being careful.
                'bekliyor olacagim~yalniz', 'bekliyorum~yalniz cikma',
                'durakta~bekliyor olacagim',
                // Surveillance as the threat: no violence named, the point
                // is letting someone know they are watched. Both anchors
                // required — "nerede takıldığını biliyorum" between friends
                // is ordinary, "bu kadar rahat dolaşma" is what turns it.
                'nerede takildigini biliyoruz', 'nerede takildigini biliyorum',
                'takildigini biliyoruz~dolasma', 'rahat dolasma',
                'her yerde goruyoruz seni', 'izliyoruz seni',
                'we know where you hang out', 'we are watching you',
                'знаем где ты бываешь', 'мы за тобой следим',
                // Numbers as the threat: friends waiting outside, nothing
                // violent named. "Merak etme, görüşeceğiz" is reassurance
                // in any other sentence — the menace is the escort.
                'yanimda arkadaslarim olacak', 'arkadaslarimla bekliyor olacagiz',
                'kapidan cikarken~arkadaslarim', 'cikisinda~arkadaslarimla',
                'merak etme gorusecegiz', 'yanimda adamlarim',
                'i will not be alone next time', 'we will be waiting outside',
                'my friends will be with me~see you',
                'я буду не один', 'мы будем ждать снаружи',
                'приду не один~увидимся',
                'waiting for you~alone', 'ill be waiting~alone',

                // Conditional threats: no violent verb anywhere, which
                // is exactly why they went through. The menace is in the
                // structure — a condition, then an unnamed consequence.
                'pisman olacaksin', 'pisman edecegim', 'pisman olursun',
                'bir daha~gorursun', 'ne olacagini gorursun',
                'anlarsin kiminle', 'kiminle ugrastigini',
                'adimi anarsan', 'bir daha konusursan',
                'you will regret it', 'youll regret it', 'you will be sorry',
                'keep talking and', 'say that again and',
                'you will see what happens', 'youll see what happens',
                'пожалеешь', 'ты пожалеешь', 'ещё раз~пожалеешь',
                'еще раз~пожалеешь', 'откроешь рот~пожалеешь',

                // Waiting for someone by name of place. Anchored on the
                // consequence so an ordinary "meet you at the exit" does
                // not match — see the safe set, which contains exactly
                // that sentence.
                'bekliyorum~pisman', 'cikisinda bekliyorum~pisman',
                'waiting for you~regret', 'жду тебя~пожалеешь',
                'буду ждать~одна', 'жду тебя~одна',
            ],
        ],
        [
            'kind' => 'threat_locator',
            'labels' => ['THR', 'PRIV'],
            'severity' => 'S4',
            'phrases' => [
                'adresini biliyorum', 'nerede oturdugunu biliyorum', 'evini biliyorum',
                'i know your address', 'know where you live',
                'знаю твой адрес', 'знаю где ты живёшь', 'знаю где ты живешь',
            ],
        ],
        [
            'kind' => 'wish_harm',
            'labels' => ['HAR', 'THR'],
            'severity' => 'S3',
            'phrases' => [
                'umarim geberirsin', 'umarim olursun', 'geberirsin de',
                'hope you drop dead', 'hope you die', 'drop dead',
                'надеюсь ты сдохнешь', 'ты сдохнешь', 'чтоб ты сдох',
            ],
        ],
        [
            'kind' => 'blackmail',
            'labels' => ['HAR', 'PRIV'],
            'severity' => 'S3',
            'phrases' => [
                'hakkinda bildiklerimi', 'herkese anlatirim', 'ifsa ederim',
                'i know things about you', 'everyone will hear them', 'or everyone will know',
                'многое о тебе знаю', 'узнают все', 'иначе узнают',
            ],
        ],
        [
            'kind' => 'family_attack',
            'labels' => ['HAR'],
            'severity' => 'S3',
            'phrases' => [
                'annen seni', 'anneni', 'annen keske', 'dogurmasaydi',
                'your mother should have', 'your mom should have', 'trouble of having you',
                'твоей матери стоило', 'прежде чем рожать',

                // Sexual abuse aimed at someone's family, which in Turkish
                // is the standard escalation and the coarsest thing said on
                // a campus feed. `amina koy` was already on the
                // severe-profanity path, which only warns — but "ananın
                // amına koyayım" is not frustration at a situation, it
                // names a person's mother.
                'anani sik', 'ananin amina', 'ananin ami', 'anani avradini',
                'avradini sik', 'bacini sik', 'karini sik', 'kizini sik',
                'ananizi sik', 'ananin dolu', 'sulalene',
                'fuck your mother', 'fuck your mum', 'fuck your mom',
                'fuck your sister', 'fuck your family',
                'ебал твою мать', 'мать твою ебал', 'ёб твою мать',
                'еб твою мать', 'мать твою за ногу',
            ],
        ],
        [
            'kind' => 'sexual_harassment',
            'labels' => ['SEX', 'HAR'],
            'severity' => 'S3',
            'phrases' => [
                'bedenin hakkinda', 'ozelden yazayim', 'geceleri seni yalniz',
                'with that body', 'tell you privately what', 'what id do with that body',
                'с таким телом', 'напишу в личку', 'спать не дам',

                // Soliciting intimate images, and naming the purpose of a
                // meeting. Both are ordinary shapes of unwanted sexual
                // contact and neither was listed in any language.
                'ciplak fotograf~gonder', 'ciplak fotonu~at', 'nude~send me',
                'send me nudes', 'send nudes', 'send me your nudes',
                'скинь свои голые', 'голые фото~скинь', 'голые фото~пришли',
                'скинь нюдсы', 'пришли голые',
                'seks icin bulusalim', 'sex icin bulusalim',
                'meet for sex', 'meet up for sex',
                'встретимся для секса', 'давай встретимся для секса',

                // Quid pro quo: a grade, a signature or a placement offered
                // for sex. The coercion is the *pairing* of an academic
                // lever with a private meeting, so every entry anchors both
                // — "come to dinner" and "sign my placement" are each
                // perfectly ordinary on their own.
                'stajini~yemege', 'stajini~aksam yeme', 'staj~benimle cikar',
                'notunu~yemege', 'notunu~benimle', 'gecmek istiyorsan~benimle',
                'imzalamam icin~yemege', 'imzalatmak istiyorsan~yeme',
                'grade~dinner with me', 'pass~dinner with me',
                'sign~dinner with me', 'placement~dinner with me',
                'sort your grade~photo', 'fix your grade~photo',
                'photo without the shirt', 'send a photo without',
                'send me your photos', 'send me your photo', 'send nudes',
                'скинь фото~личку', 'скинь мне свои фото', 'пришли свои фото',
                'приходи ко мне одна', 'приходи одна вечером',
                'решим вопрос с оценкой', 'вопрос с оценкой~вечером',
                // The staff-office variant: attendance or a grade settled
                // in private, alone. "Odama gel" alone is how a tutor
                // arranges a normal meeting, so `yalniz` carries the rule.
                'odama yalniz gel', 'yalniz gelirsen~hallede',
                'yalniz gelirsen~devamsizlik', 'odama gel~hallederiz',
                'come to my office alone', 'come alone~sort out your',
                // Undressed described obliquely, which is the usual
                // register — "less clothing", "поинтимнее", "daha az giysili".
                'pictures with less clothing', 'photos with less clothing',
                'picture with less clothing', 'less clothing~mark',
                'less clothing~grade', 'daha az giysili', 'ustsuz bir fotograf',
                'fotograflarini yollarsan~notuna', 'yollarsan~notuna bakarim',
                'снимки поинтимнее', 'фото поинтимнее', 'пару снимков~оценку',
                // Objectifying a named person on a public feed. Anchored
                // on "seksi" PLUS a speculation about acting on it, so an
                // ordinary use of the word is not caught — the attack is
                // the pairing, not the adjective.
                'seksi~ne yapardim', 'seksi~bir bilsen', 'seksi~yapardim',
                // Quid pro quo: grades or help traded for sexual content.
                // Distinct from the nude-in-photography discussion in the
                // safe set, which has no request and no exchange.
                'send me nudes', 'send nudes', 'ciplak fotograf~gonder',
                'nudes~pass the', 'nudes~help you',
            ],
        ],
        [
            'kind' => 'sexual_harassment',
            'labels' => ['SEX', 'HAR'],
            'severity' => 'S2',
            'phrases' => [
                'numarani ver', 'gelecek yorumlara da katlanacaksin', 'boyle giyinirsen',
                'give me your number', 'wont let you sleep', 'deserve every dirty comment',
                'asking for it',
                'дай номер', 'грязные комментарии', 'терпи грязные',
            ],
        ],
        [
            'kind' => 'hate_group',
            'labels' => ['HATE'],
            'severity' => 'S4',
            'phrases' => [
                'gocmenler insan degil', 'hepsini ulkeden sur', 'hepsini sinir disi',
                'immigrants are vermin', 'should all be thrown out', 'are vermin',
                'мигранты паразиты', 'чурки', 'их всех нужно выгнать',
            ],
        ],
        [
            'kind' => 'hate_group',
            'labels' => ['HATE'],
            'severity' => 'S3',
            'phrases' => [
                'kadinlarin akli', 'engelliler topluma', 'sadece yuk oluyor',
                'cingene ruhlu', 'kadinlar boyle isleri',
                'women arent intelligent', 'women are not intelligent',
                'disabled people are nothing but a burden', 'burden on society',
                'женщинам не хватает ума', 'инвалиды только обуза', 'только обуза',
            ],
        ],
        [
            // Glorifying genocide or its perpetrators. Named separately
            // from slurs because it attacks a group without using one:
            // "Hitler haklıydı" contains no insult a word list would see.
            'kind' => 'hate_group',
            'labels' => ['HATE'],
            'severity' => 'S4',
            'phrases' => [
                'hitler hakliydi', 'hitler dogru', 'keske hitler', 'gaz odalari',
                'soykirim yalan', 'holokost yalan', 'yine gelsin hitler',
                'hitler was right', 'gas the', 'holocaust is a lie',
                'holocaust never happened', 'heil hitler', 'white power',
                'blood and soil', 'race war', 'final solution',
                'гитлер был прав', 'холокост ложь', 'зиг хайль',
            ],
        ],
        [
            'kind' => 'hate_coded',
            'labels' => ['HATE'],
            'severity' => 'S4',
            'phrases' => [
                'you people are a disease', 'you people are', 'siz millet olarak',
                'вы все как один',
            ],
        ],
        [
            'kind' => 'dehumanization',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'zavalli yaratik', 'yaratik',
                'miserable creature', 'you creature', 'subhuman',
                'жалкое существо', 'существо',
                // Calling a group filth and telling it to leave. The
                // Russian case published in a live probe: the TR and EN
                // equivalents were covered and the RU one was not.
                'грязь~не место', 'пусть убираются', 'им не место',
                'они грязь',
            ],
        ],
        [
            'kind' => 'direct_insult',
            'labels' => ['PROF', 'HAR'],
            'severity' => 'S2',
            'phrases' => [
                'bok herif', 'pezevenk',
                'piece of shit', 'sack of shit',
                'кусок дерьма',
            ],
        ],
        [
            /*
             * Obscene compound insults — a vulgar root welded to a noun.
             *
             * These are listed as phrases because their roots cannot be
             * listed alone. The engine allows untargeted strong profanity
             * (swearing at a situation publishes, by a deliberate policy
             * in the 150-case corpus), so "sik kafalı" inherited that
             * reading and published: the root matched, and the verdict was
             * `general_exclamation`. But a compound like this is not an
             * exclamation — it is a name for a person.
             *
             * And `göt` cannot be a root at all: "götürmek" — to take — is
             * everyday Turkish, so only the compounds are listed.
             */
            /*
             * Vulgar nouns for a sex act. Held for a human, not refused.
             *
             * These share the `sik` root with the verb forms, and the verb
             * forms are exclamations ("siktiğim ders" is "this bloody
             * lesson"). A noun is not: "sikiş" names the act, and nothing
             * innocent is spelled that way. Holding rather than blocking,
             * because a word is not an attack and a moderator can see the
             * post in a second.
             */
            'kind' => 'explicit_sexual_term',
            'labels' => ['SEX', 'PROF'],
            'severity' => 'S2',
            'phrases' => [
                'sikis', 'sikisme', 'sikismek', 'duzusme', 'duzusmek',
                // NOT 'goturme': it is a substring of "götürmek" — to take
                // — so listing it refused "kitapları götürmek istiyorum".
                // Caught in testing, and it is the exact trap the `göt`
                // root is kept out of the word lists to avoid.
                'otuz bir cek', 'otuzbir cek', 'mastürbasyon yap',
                'oral yap', 'sakso cek',
                'porn video', 'porno video', 'sex video', 'seks videosu',
                'трахаться', 'ебля', 'дрочить',
            ],
        ],
        [
            'kind' => 'vulgar_insult',
            'labels' => ['PROF', 'HAR'],
            'severity' => 'S3',
            'phrases' => [
                // `sik` compounds.
                'sik kafali', 'sikkafali', 'sik kafa', 'sik surat',
                'sikik herif', 'sikik surat', 'siki tutmus',
                // `göt` compounds — the root itself stays unlisted.
                'got lalesi', 'got deligi', 'gotunun deligi', 'got herif',
                'got kafali', 'gotu boklu', 'got oglani',
                // `am` compounds. The bare syllable is never listed:
                // "tamam", "ambulans", "amfi" and "Amasya" are ordinary
                // Turkish and one of them appears in most campus posts.
                'am biti', 'amcik agizli', 'amina kodugum', 'amina kodugumun',
                'amcik herif',
                'yarrak kafali', 'tasak herif',
                'dickhead', 'dick head', 'shit for brains', 'arse face',
                'cunt face', 'fuckface', 'fuck face',
                'хуйло', 'хуеплёт', 'хуеплет', 'мудозвон', 'говноед',
            ],
        ],
        [
            'kind' => 'worthlessness',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'oksijene yazik', 'dunyadaki oksijene', 'nefes almana yazik',
                'bos ve gereksiz',
                'room improves when you leave', 'youre useless', 'you are useless',
                'world would be better without you',
                'без тебя всем лучше', 'ты бесполезный', 'от тебя никакого толку',
                // Synonyms for the same denial of worth. The list read as a
                // handful of exact sentences; these are how it is actually
                // said, including about a third party rather than to "you".
                'waste of space', 'waste of oxygen', 'waste of a place',
                'better off if she', 'better off if he', 'better off without her',
                'better off without him', 'sinifin yuz karasi',
                'aramizda olmasa daha iyi', 'yok sayilsa daha iyi',
                'пустое место', 'лучше бы её не было', 'лучше бы его не было',
            ],
        ],
        [
            'kind' => 'exclusion',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'kimse seni istemiyor', 'seni burada istemiyor', 'buradan defol',
                'hesabini~kapat', 'hesabini~sil', 'hesabini~sileceg',
                'nobody wants you here', 'nobody wants you', 'delete your account',
                'никто не хочет видеть', 'удали аккаунт', 'тебя здесь никто',

                // Organised shunning. The harm is in the instruction to
                // everyone else, so these are anchored on an imperative
                // plus a third person rather than on any word being rude.
                'herkes engellesin', 'hepiniz engelleyin', 'kimse konusmasin',
                'kimse yazmasin', 'kimse muhatap olmasin',
                'everyone ignore her', 'everyone ignore him', 'everyone ignore them',
                'everyone block her', 'everyone block him',
                'nobody talk to her', 'nobody talk to him', 'no one talk to her',
                'figure it out alone', 'leave her out of', 'leave him out of',
                'перестанем с ней разговаривать', 'перестанем с ним разговаривать',
                'пусть сама справляется', 'пусть сам справляется',
            ],
        ],
        [
            // Collective expulsion: a whole group told it does not belong.
            //
            // Separate from `exclusion` above, which is aimed at one person,
            // and from the slur rules, which need a named group. This is the
            // shape that carries no slur at all — "all those people should be
            // thrown out, they don't belong in this country" — and it reached
            // nobody: no slur to match, and the semantic layer scored the
            // English version below its own safe ceiling.
            //
            // Anchored on a plural subject PLUS a removal verb, because each
            // half alone is ordinary. The safe set contains a housing-policy
            // debate and an immigration panel, both of which say "should
            // leave" and "does not belong" without naming anyone.
            'kind' => 'hate_exclusion',
            'labels' => ['HATE', 'HAR'],
            'severity' => 'S4',
            'phrases' => [
                'bunlarin hepsi~gitsin', 'hepsi~gitsin buradan',
                'yurttan~cikarilmali', 'yurttan~atilmali',
                'bunlarin burada isi yok', 'onlarin burada isi yok',
                'burada isi yok~hepsi', 'hepsi defolsun',
                'ulkeden~gitsinler', 'ulkemizde~istemiyoruz',
                'o dinden olanlarla', 'o dinden olanlar',
                // Refusing to share a room or a class with a named group,
                // and the flattening that always travels with it.
                'ayni sinifta olmak istemiyorum', 'ayni sinifta~istemiyorum',
                'onlarla ayni~istemiyorum',
                'should be expelled', 'should all be expelled',
                'do not belong here', 'dont belong here',
                'нельзя учиться', 'с такими людьми нельзя',
                'ayni atolyede calismam~hepsi', 'hepsi ayni zaten',
                'all those people should be', 'those people should be thrown',
                'thrown out of the dorms', 'should be thrown out~belong',
                'do not belong in this country', 'dont belong in this country',
                'they do not belong here~all', 'send them all back',
                'всех этих надо выгнать', 'надо выгнать из общежития',
                'им здесь не место', 'им здесь вообще не место',
                'пусть все уезжают', 'выгнать их всех',
                // Coded class contempt, which carries no slur at all: a
                // group named by where it came from, blamed for a decline.
                // Both anchors are needed — "students from the villages"
                // is ordinary demographic description in a recruitment post.
                'koyden gelen~yuzunden', 'koyden gelenler~seviye',
                'koyden gelen bu tipler', 'tasradan gelme~cahil',
                'bu tipler yuzunden~seviye', 'bunlar yuzunden~seviye dustu',
                'из деревни~из-за них', 'понаехали~уровень',
            ],
        ],
        [
            // Organised pile-on: inciting a group against one person.
            //
            // Separate from `direct_insult` because the grammar is
            // different and the harm is worse — nothing here is addressed
            // to the target, so a rule looking for "you are ..." misses it
            // entirely. Both live-probe cases were of this shape and
            // published.
            //
            // Anchored on the instruction to the crowd, not on the
            // sentiment. "Rezil oldum" (I was humiliated) is someone
            // describing their own bad day and must stay clean, so the
            // third-person imperative "rezil olsun" is what is listed.
            'kind' => 'brigading',
            'labels' => ['HAR'],
            'severity' => 'S3',
            'phrases' => [
                'herkes~gulsun', 'rezil olsun', 'rezil edelim', 'hepimiz~yazalim',
                'linc edelim', 'ifsa edelim', 'yayalim herkese',
                'everyone~spam it', 'spam it until', 'screenshot~spam',
                'until she deletes', 'until he deletes', 'lets all report',
                'brigade', 'pile on her', 'pile on him',
                'давайте все~напишем', 'затравим', 'засыпем её',
            ],
        ],
        [
            // First-person intent to inflict physical injury, described
            // graphically. Distinct from the `threat` rule above, which
            // needs a second-person target: this catches "onu yakalayıp
            // kafasını duvara vuracağım" where the victim is a third party.
            //
            // The safe set deliberately contains horror-film analysis and
            // war photography, so the anchors are the act plus its result,
            // never the subject matter.
            'kind' => 'graphic_violence',
            'labels' => ['VIO'],
            'severity' => 'S4',
            'phrases' => [
                'kafasini~vurac', 'kafasini~duvara', 'kani aksin', 'kanini akit',
                'yakalayip~vurac', 'dislerini~dokec',
                'smash his head', 'smash her head', 'make him bleed', 'make her bleed',
                'разобью ему голову', 'пусть кровь',
            ],
        ],
        [
            'kind' => 'appearance_attack',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'su tipe bak', 'filtre olmadan', 'midem bulaniyor', 'fotografina bakinca',
                'suratina bak',
                'look at that face', 'filters are doing charity', 'makes me physically sick',
                'your photo makes me',
                'посмотрите на это лицо', 'фильтры не спасают', 'меня тошнит',
                'от твоей фотографии',

                // Daily grinding-down, which is what bullying mostly is: not
                // one severe insult but the same small one every day.
                'her gun ayni~surat', 'ayni cirkin', 'cirkin surat',
                'aynaya bakmaktan', 'aynaya bakiyor musun',
                'same ugly face', 'that ugly face',
                'do you even look in the mirror', 'look in a mirror',
                'то же уродливое лицо', 'ты в зеркало смотришь',
            ],
        ],
        [
            'kind' => 'ability_attack',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'senin gibi beceriksiz', 'bu isi yapmasi zaten',
                'someone this incompetent', 'this incompetent',
                'такой бездарь', 'как такой бездарь',
                // Recording someone's disability to mock it. The harm is
                // imitation plus publication, so both are anchored — a post
                // about a speech-therapy service names the same condition
                // and must publish.
                'kekele~taklit', 'kekeme~taklit', 'taklit eden~hikaye',
                'taklit edip~paylas', 'topallamasini~taklit',
                // Same act, other media: a voice recording put on a story.
                'taklit eden~ses kaydi', 'taklit eden~story',
                'taklit ederek~kaydettim', 'konusmasini taklit',
                'ses kaydi yapip~story', 'videoya cekip~dalga',
                'mimic~stutter', 'mocking his stutter', 'mocking her stutter',
                'imitating~stutter', 'made a video mocking',
                'передразнива~заикан', 'высмеива~заикан',
            ],
        ],
        [
            'kind' => 'dismissal',
            'labels' => ['PROF', 'HAR'],
            'severity' => 'S2',
            'phrases' => [
                'siktir git', 'siktir ol', 'defol git', 'defol', 'kes sesini', 'cek git',
                'fuck off', 'fuck you', 'get lost', 'shut up', 'piss off', 'get out of here',
                'иди на хуй', 'иди на х', 'пошёл ты', 'пошел ты', 'заткнись', 'проваливай',
                'отвали',
            ],
        ],
        [
            'kind' => 'intelligence_jab',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'beyni tatile', 'kafasi calismiyor', 'beyni yok',
                'brain took the day off', 'no functioning brain', 'brain is on vacation',
                'мозг снова ушёл в отпуск', 'мозг явно не работает', 'мозг отключился',
            ],
        ],
        [
            'kind' => 'taunt',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'cevap versene', 'korkak tavuk',
                'answer me you coward', 'you coward', 'too scared to answer',
                'ответь уже', 'что молчишь трус',
            ],
        ],
        [
            // Party-political campaigning/recruitment. A party or politician
            // name alone is ordinary news/discussion and must not match.
            'kind' => 'political',
            'labels' => ['POL'],
            'severity' => 'S2',
            'phrases' => [
                'genel secimlerde oy verin', 'akp kazanmali', 'chp kazanmali',
                'mhp kazanmali', 'iyi parti kazanmali', 'partimize katil',
                'cumhurbaskani adayina oy ver', 'milletvekili adayina oy ver',
                'vote republican', 'vote democratic', 'join our political party',
                'vote for the presidential candidate',
                'голосуйте за единую россию', 'вступайте в нашу партию',
            ],
        ],
        [
            'kind' => 'content_profanity',
            'labels' => ['PROF', 'HAR'],
            'severity' => 'S2',
            'phrases' => [
                'isin icine sicmis', 'icine sicmis', 'batirmissin',
                'fucked up this', 'fucked this up', 'screwed this up',
                'просрал этот', 'просрал проект', 'запорол',
            ],
        ],

        // ------------------------------------------------------------------
        // Campus policy. The rules above are about abuse between people, and
        // a general-purpose moderation model covers that ground well. The
        // rules below are things that are perfectly legal to say in public
        // but are against *university* rules or prey on students — an
        // external model has no reason to flag "I'll sit your exam for you",
        // yet on a campus app it is one of the more damaging posts possible.
        // ------------------------------------------------------------------

        [
            // Someone in crisis. Deliberately NOT punished — see the engine's
            // handling: this reaches a human quickly and the author is shown
            // support contacts instead of a strike. Treating a student who
            // says this as a rule-breaker would be the single worst outcome
            // this system could produce, so a false positive here (hyperbole
            // after a bad exam) costs only an offer of help.
            'kind' => 'self_harm',
            'labels' => ['SELF'],
            'severity' => 'S4',
            // Stems, not full conjugations. Turkish inflects the verb at
            // the end ("kendimi öldüreceğim", "kendimi öldürmek istiyorum",
            // "kendimi öldürürüm"), so listing finished sentences misses
            // most of the ways someone actually says it — "kendimi öldürmek
            // istiyorum", one of the commonest phrasings, went straight
            // through. Matching the stem covers the whole family.
            'phrases' => [
                // Completion euphemisms. Nobody in crisis reliably uses the
                // clinical word, and "bu gece her şeyi bitireceğim" is a
                // sentence this app has to hear.
                'her seyi bitirec', 'her seyi bitirmek', 'hepsini bitirec',
                'bu gece bitirec',
                'going to end it', 'end it tonight', 'end it all',
                'i am going to end it', 'im going to end it',
                'всё закончу', 'все закончу', 'всё закончить',

                // Both stems: Turkish softens the final consonant before a
                // vowel, so the verb is "intihar et-mek" but "intihar
                // ed-eceğim". One stem catches half the conjugations.
                'intihar et', 'intihar ed', 'intihara', 'intihar dusun',
                // Finality without the word. "Bu gece her şeyi bitireceğim,
                // kimse aramasın" says it plainly and matched nothing —
                // it published in a live probe. Anchored on exhaustion or
                // finality PLUS an ending, because "her şeyi bitireceğim"
                // alone is what a student says about coursework.
                'dayanamiyorum~bitirec', 'dayanamiyorum~bitirece',
                'her seyi bitirecegim~kimse', 'kimse aramasin',
                'veda etme vakti', 'son kez yaziyorum',
                'cant do this anymore~tonight', 'do not look for me',
                'dont look for me', 'this is my last post',
                // Announcing a settled decision without naming it. Held-out
                // testing found this register reaching nobody: the semantic
                // layer scored it -0.0199 and no phrase matched. Listing it
                // costs little — the outcome is an offer of help, not a
                // refusal — and a jokey "don't try to stop me" is answered
                // with a counselling contact, which is a tolerable failure.
                'beni durdurmaya calisma', 'durdurmaya calismayin',
                'nasil yapacagima karar verdim', 'karar verdim~durdur',
                'do not try to stop me', 'dont try to stop me',
                'ive decided how', 'i have decided how',
                'son mesajim', 'herkes beni unutsun',
                'kendimi oldur', 'kendimi asa', 'canima kiy',
                'artik yasamak istemiyorum', 'yasamak istemiyorum',
                'yasamak istemiyor', 'olmek istiyorum', 'olsem daha iyi',
                'kendime zarar ver', 'bileklerimi kes', 'bilek kes',
                'hayatima son ver', 'yasamanin anlami yok',
                'kill myself', 'killing myself', 'end my life', 'ending my life',
                'end it all', 'want to die', 'wanna die',
                'dont want to live', 'do not want to live', 'better off dead',
                // First person, like the Turkish entries above. The bare
                // noun "self harm" was listed and held the counselling
                // service's own awareness seminar — the post whose entire
                // purpose is to reach students in crisis. An announcement
                // about self harm is not a disclosure of it.
                'i self harm', 'self harming', 'self harmed',
                'harming myself', 'hurting myself', 'hurt myself',
                'cut myself', 'cutting myself', 'suicidal',
                'take my own life',
                'покончить с собой', 'покончу с собой', 'убить себя',
                'не хочу жить', 'режу себя', 'причиняю себе вред',
                // Absence framed as a fact about tomorrow, plus a
                // leave-taking formula — how this is actually written:
                // "herkese hakkımı helal ediyorum, yarın burada
                // olmayacağım", "я всё решил, завтра меня уже не будет, не
                // ищите меня".
                //
                // The absence half is NOT listed on its own. "Yarın burada
                // olmayacağım, izinliyim, notları biri tutabilir mi?" is a
                // student taking a day off, and listing the bare phrase held
                // it — a held-out case caught exactly that. What carries the
                // meaning is the leave-taking beside it, so the two are
                // anchored together.
                'hakkimi helal', 'hakkinizi helal edin', 'helallesmek istiyorum',
                'veda ediyorum herkese', 'helal edin~olmayacagim',
                'i am tired of waking up', 'tired of waking up',
                'do not want to be here anymore', 'dont want to be here anymore',
                'not want to be here anymore', 'i am done with everything',
                'не ищите меня', 'я всё решил~не ищите', 'я все решил~не ищите',
                'меня уже не будет~не ищите', 'устал просыпаться',
                'хочу умереть', 'свести счёты с жизнью',
            ],
        ],
        [
            // Contract cheating, leaked papers, proxy exam-sitting.
            'kind' => 'academic_dishonesty',
            'labels' => ['ACAD'],
            'severity' => 'S3',
            'phrases' => [
                'sinav sorulari sizdi', 'sinav sorularini satiyorum', 'sinav sorulari elimde',
                'cevap anahtari satiyorum', 'cevap anahtari elimde',
                'odevini yaparim para', 'odev yaparim ucret', 'tez yazarim ucret',
                'yerine sinava girerim', 'sinavina girerim', 'senin yerine girerim',
                'proje yaparim ucret', 'para karsiligi odev',
                'selling exam answers', 'exam answers for sale', 'leaked exam',
                'answer key for sale', 'write your essay for', 'write your thesis for',
                'do your homework for money', 'take your exam for you',
                'sit your exam for you', 'essay for hire',
                'продам ответы на экзамен', 'ответы на экзамен есть',
                'напишу диплом за', 'сдам экзамен за тебя', 'решу за деньги',
            ],
        ],
        [
            // Student-targeted fraud: fake scholarships, deposit-first
            // housing scams, "guaranteed" investment pitches.
            'kind' => 'scam_fraud',
            'labels' => ['SCAM'],
            'severity' => 'S3',
            'phrases' => [
                // Credential phishing. The existing list covered
                // investment and deposit fraud; phishing is a different
                // shape and was published in a live probe. What makes it
                // recognisable is the pairing — a request for a secret
                // plus somewhere to type it — not any single word, so the
                // gap operator anchors both halves. "Şifreni kimseyle
                // paylaşma" (advice) has no destination and stays clean.
                'kart bilgilerini~gir', 'kart bilgileri~link', 'kart bilgilerini~yaz',
                'sifreni~gir', 'sifreni~linke', 'sifreni~dogrula',
                'hesabin kapatilacak~gir', 'hesabiniz kapatilacak',
                'verify your password', 'confirm your password here',
                'account will be closed', 'account will be suspended~verify',
                'enter your card details', 'card details~link',
                'подтвердите пароль', 'введите данные карты',
                'kolay para kazan', 'garanti kazanc', 'garantili kazanc',
                'yatirim firsati kacirmayin', 'kripto ile zengin', 'paranizi katliyorum',
                'burs garantisi veriyorum', 'kesin burs alirsin', 'once kapora gonder',
                'depozito gonder sonra', 'iban gonderiyorum acil', 'kapora yatirin',
                'guaranteed returns', 'guaranteed profit', 'easy money fast',
                'double your money', 'send the deposit first', 'pay the deposit first',
                'guaranteed scholarship', 'wire the money first',
                'гарантированный доход', 'лёгкие деньги', 'удвою ваши деньги',
                'сначала переведи задаток', 'гарантирую стипендию',

                // The "your account/payment is on hold, confirm your
                // details" shape. Held-out testing found the English and
                // Russian versions of this published while the Turkish one
                // was refused — the list had grown one language at a time.
                'payment is on hold~bank', 'grant payment~confirm your bank',
                'confirm your bank details', 'verify your bank details',
                'update your bank details~link', 'account is suspended~restore',
                'restore it with your', 'library account is suspended',
                'confirm your details to avoid', 'to avoid your account being',
                'kaydin silinmemesi icin', 'kaydiniz silinecek~gir',
                'ogrenci bilgilerini~adrese', 'bilgilerini~su adrese gir',
                // Coercion by deadline: type your details in, or lose your
                // registration. The threat is what marks it as phishing.
                'kimlik bilgilerini~gir', 'bilgilerini bu forma gir',
                'forma gir~yoksa', 'gir~yoksa kaydin', 'yoksa kaydin silinir',
                'yoksa kaydiniz silinir', 'aksi halde kaydiniz',
                // NOT 'burs odemesi~banka': the university's own warning
                // about scholarship-payment scams names both halves, and
                // listing the pair refused the warning. A scam gives an
                // instruction; a warning gives a caution.
                'odemeniz beklemede', 'burs odemesi icin~iban gir',
                'kesin kazanc', 'ayda iki kati', 'iki katina cikariyorum',
                'kazanc~kapora', 'kar~once kapora',
                'ваш счёт заблокирован~подтвердите', 'счет заблокирован~подтвердите',
                'подтвердите банковские данные', 'восстановите~данные карты',
                'выплата приостановлена', 'отправь залог первым',
                'удвою сумму~залог',
            ],
        ],
        [
            'kind' => 'drug_sale',
            'labels' => ['DRUG'],
            'severity' => 'S3',
            'phrases' => [
                'ot satiyorum', 'esrar satiyorum', 'esrar var', 'hap satiyorum',
                'madde satiyorum', 'mal var kampuste', 'satilik ot',
                'selling weed', 'weed for sale', 'selling molly', 'selling pills',
                'buy pills dm', 'got weed for sale', 'selling coke',
                'продам травку', 'продаю закладки', 'продам таблетки',
            ],
        ],
        [
            'kind' => 'weapon',
            'labels' => ['THR', 'VIO'],
            'severity' => 'S4',
            'phrases' => [
                'silahim var~getirecegim', 'okula silah getir', 'bicak getirecegim',
                'silahla gelecegim', 'kampuse silah',
                'i have a gun~coming tomorrow', 'bring a gun to', 'bringing a knife to', 'shoot up the',
                'gun to campus',
                'принесу нож в', 'приду с оружием в',
            ],
        ],
        [
            // Threatening to publish intimate images to coerce someone.
            'kind' => 'sextortion',
            'labels' => ['SEX', 'THR', 'PRIV'],
            'severity' => 'S4',
            'phrases' => [
                'ciplak fotograflarini yayarim', 'fotograflarini yayarim',
                'ifsa edecegim fotograflarini', 'resimlerini atarim herkese',
                'leak your nudes', 'send nudes or', 'post your photos unless',
                'ill leak your photos', 'share your nudes',
                'разошлю твои фото', 'сольют твои фото', 'выложу твои фото',
                // The words between the photo and the threat are free:
                // "fotoğraflarını HERKESE yayarım" missed a listed phrase by
                // one inserted word, which is the whole reason for the gap
                // operator.
                'fotograflarini~yayar', 'fotograflari~herkese yayar',
                'resimlerini~yayar', 'videolarini~yayar',
                'cevap vermezsen~fotograf', 'konusursan~fotograf',
                'photos~unless you', 'pictures~unless you',
                'фото~если не', 'фотографии~если не',
                // "What I have on you" — the material named obliquely,
                // which is how this is usually written.
                'sende olanlari~gonderirim', 'elimdekileri~gonderirim',
                'sende olanlari herkese', 'elimde olanlari herkese',
                'konusmazsan~herkese gonder', 'konusmazsan~yayinlarim',
                'what i have on you~send', 'send everyone what i have',
                'то что у меня есть на тебя', 'разошлю всем то что',
            ],
        ],
        [
            // Publishing someone else's contact details to set them up for
            // harassment. Phrase-based; the numeric forms are caught by the
            // engine's PII pass instead.
            'kind' => 'doxxing',
            'labels' => ['PRIV', 'HAR'],
            'severity' => 'S3',
            'phrases' => [
                'numarasi su', 'iste numarasi', 'adresi su', 'iste adresi',
                'numarasini paylasiyorum', 'adresini paylasiyorum', 'nerede oturdugu',
                'here is his number', 'here is her number', 'here is their number',
                'here is his address', 'here is her address', 'this is their address',
                'вот его номер', 'вот её номер', 'вот его адрес', 'вот их адрес',
                // Publishing an address and pointing people at it. The
                // second anchor is what separates this from a friend
                // passing on a flat number.
                'her home address~find her', 'his home address~find him',
                'home address~go and find', 'adresi~gidin bulun',
                'adresi elimde~gidin', 'адрес у меня есть~приедем',
                'знаю где он живёт', 'знаю где он живет',
                'знаю где она живёт', 'знаю где она живет',

                // An address plus a directive. Anchored on both halves so a
                // friend passing on a flat number stays clean — the safe set
                // has exactly that, and it must keep publishing.
                'adreste oturuyor~gidin', 'adreste oturuyor~gidip',
                'oturdugu adres~gidin', 'su adreste~gidin',
                'lives at~go and', 'lives at~go there', 'his address~go get',
                'живёт по адресу~сходите', 'живет по адресу~сходите',
                'живёт по адресу~приходите', 'адрес~сходите к нему',

                // Student records. A number plus an institutional email is
                // somebody's file, not a contact detail, and it identifies
                // them to anyone with access to the registry.
                'ogrenci numarasi~mail', 'ogrenci numarasi~eposta',
                'student id is~email', 'student number is~email',
                'his student id', 'her student id',
                'номер студенческого~почта', 'студенческий номер~почта',
            ],
        ],
        [
            // An adult steering a minor towards private contact. The most
            // serious thing on this list, so it escalates rather than
            // merely blocking — a moderator needs to see it, not a counter.
            'kind' => 'minor_safety',
            'labels' => ['MINOR', 'SEX'],
            'severity' => 'S4',
            'phrases' => [
                '13 yasindaki~numaram', '14 yasindaki~numaram',
                '15 yasindaki~numaram', '16 yasindaki~numaram',
                'lise ogrencisi~ozelden yaz', 'kucuk kiz ariyorum',
                'yasin kac tatlim~ailene soyleme', 'gizli tutalim~ailene soyleme',
                '13 year old~message me', '14 year old~message me',
                '15 year old~message me', 'underage~our little secret',
                'young girl looking~contact me',
                'несовершеннолет~пиши в личку', 'не говори родителям~наш секрет',

                // Ages written as words. The rule held digits only, so
                // "On üç yaşındaki kızlarla görüşmek istiyorum" — the same
                // sentence spelled out — matched nothing. Of every category
                // here this is the one that must not depend on how a
                // number was typed.
                'on iki yasindaki~numaram', 'on uc yasindaki~numaram',
                'on dort yasindaki~numaram', 'on bes yasindaki~numaram',
                'on alti yasindaki~numaram', 'on yedi yasindaki~numaram',
                'on iki yasindaki~gorusmek', 'on uc yasindaki~gorusmek',
                'on dort yasindaki~gorusmek', 'on bes yasindaki~gorusmek',
                'on alti yasindaki~gorusmek', 'on yedi yasindaki~gorusmek',
                '13 yasindaki~gorusmek istiyorum', '14 yasindaki~gorusmek istiyorum',
                '15 yasindaki~gorusmek istiyorum', '16 yasindaki~gorusmek istiyorum',
                'twelve year old~message me', 'thirteen year old~message me',
                'fourteen year old~message me', 'fifteen year old~message me',
                'sixteen year old~message me', 'seventeen year old~message me',
                'thirteen year old~meet', 'fourteen year old~meet',
                'fifteen year old~meet', 'sixteen year old~meet',
                'двенадцатилет~пиши', 'тринадцатилет~пиши',
                'четырнадцатилет~пиши', 'пятнадцатилет~пиши',
                'тринадцатилет~встретить', 'пятнадцатилет~встретить',
            ],
        ],
        [
            'kind' => 'terrorism',
            'labels' => ['TERROR', 'VIO'],
            'severity' => 'S4',
            'phrases' => [
                'isid icin', 'isid e katil', 'deas icin', 'orgute katil',
                'cihada gidiyorum', 'bombali eylem', 'bomba koyacagim',
                'kampuse bomba', 'okula bomba', 'patlayici yerlestir',
                'joining isis', 'fight for isis', 'jihad against',
                'plant a bomb', 'blow up the', 'suicide bombing',
                'вступить в игил', 'заложу бомбу', 'взорву',
                // Recruitment that names no organisation. This was carried
                // by the semantic layer alone until ordinary invitations
                // ("anyone want to join?") were added to the benign
                // exemplars, at which point "join the cell" read as one.
                // A signal that rests on a single layer disappears the
                // moment that layer is retuned.
                'join the cell', 'joining the cell', 'bring the fight here',
                'stop asking questions~join', 'take up arms',
                'hucreye katil', 'orgute katilmak', 'silahlanmamiz gerek',
                'burada da savasmaliyiz', 'soru sormayi birak~katil',
                'вступай в ячейку', 'пора взяться за оружие',
                'перенести борьбу сюда',
            ],
        ],
        [
            // Telling someone how to make a weapon, not merely mentioning
            // one — the instruction is the harm.
            'kind' => 'criminal_instructions',
            'labels' => ['CRIME', 'VIO'],
            'severity' => 'S4',
            'phrases' => [
                'bomba nasil yapilir', 'patlayici nasil yapilir',
                'molotof nasil yapilir', 'silah nasil yapilir',
                'zehir nasil yapilir', 'kilit nasil kirilir',
                'how to make a bomb', 'how to build a bomb',
                'how to make explosives', 'how to make napalm',
                'how to pick a lock', 'untraceable poison',
                'как сделать бомбу', 'как сделать взрывчатку',
            ],
        ],
        [
            // Credential theft aimed at students: the campus portal is the
            // obvious target and the request is always the same shape.
            'kind' => 'cybercrime',
            'labels' => ['CRIME', 'SCAM'],
            'severity' => 'S3',
            'phrases' => [
                'sifreni bana yolla', 'sifreni bana gonder', 'sifreni yaz bana',
                'sifreni paylas', 'hesabini bana ver', 'obs sifreni',
                'portal sifreni', 'kullanici adi ve sifreni',
                'dogrulama kodunu gonder', 'sms kodunu bana', 'otp kodunu gonder',
                'linke tikla ve sifreni', 'hesabini calarim', 'hesap hackle',
                'send me your password', 'give me your password',
                'send me the verification code', 'send the otp',
                'enter your password here', 'hack the account', 'steal the account',
                'пришли мне пароль', 'отправь код подтверждения',
                // "пришли мне свой пароль от портала" — one inserted word
                // ("свой") past a listed phrase. Same shape in English.
                'пришли~свой пароль', 'пришли мне свой пароль',
                'пароль от портала', 'скинь мне пароль', 'дай мне свой пароль',
                'я сам тебя запишу~пароль',
                'send me your portal password', 'your portal password',
                'send me your student password', 'password~i will register for you',
                'ogrenci portali sifreni', 'portal sifresini bana',
                // A credential is not always called a password. The list
                // covered `password`/`şifre`/`пароль` only, so "give me
                // your university login" asked for the same thing and
                // matched nothing.
                'give me your login', 'give me your university login',
                'your uni login', 'your student login', 'send me your login',
                'give me your credentials', 'share your credentials',
                'your account details~i will grab',
                'kullanici adini bana', 'kullanici adini ver',
                'hesap bilgilerini bana ver', 'giris bilgilerini bana',
                'дай мне свой логин', 'пришли свой логин',
                'университетский логин~дай', 'дай свои данные для входа',
            ],
        ],
        [
            // Claiming to be staff while asking for money or credentials.
            // The claim alone is not the offence — the combination is.
            'kind' => 'impersonation',
            'labels' => ['SCAM'],
            'severity' => 'S3',
            // The second anchor is the demand, not the subject.
            //
            // Anchoring on the noun caught the warning as well as the scam:
            // "öğrenci işleri asla şifre istemez" — the exact advice a
            // university needs to be able to send — matched "öğrenci
            // işleri" near "şifre" and was refused. Requiring the imperative
            // ("şifreni gönder", "para yatır") separates a demand from a
            // caution without trying to parse negation.
            'phrases' => [
                'ben rektor~para yatir', 'ben rektor~havale', 'ben rektor~gonder',
                'ben dekan~para yatir', 'ben dekan~gonder',
                'ben ogretim gorevlisi~para yatir',
                'universite yonetimi~havale yap', 'universite yonetimi~para yatir',
                'ogrenci isleri~sifreni gonder', 'ogrenci isleri~sifreni yaz',
                'rektorluk adina~gonder', 'idare adina~para yatir',
                'i am the rector~send money', 'i am the dean~transfer',
                'university administration~send payment',
            ],
        ],
        [
            // Deliberately narrow harmful medical misinformation. General
            // disagreement and harmless false trivia are absent.
            'kind' => 'misinformation',
            'labels' => ['MISINFO'],
            'severity' => 'S2',
            'phrases' => [
                'asilar cip iceriyor~olmayin', 'asi otizm yapar~asi olmayin',
                'covid yalan~tedavi olmayin', 'virus yok~ilac kullanmayin',
                'vaccines contain chips~do not get vaccinated',
                'vaccines cause autism~do not vaccinate',
                'covid is a hoax~refuse treatment',
                'вакцины чипы~не вакцинируйтесь', 'ковид обман~не лечитесь',
            ],
        ],
        [
            'kind' => 'piracy',
            'labels' => ['COPY'],
            'severity' => 'S2',
            'phrases' => [
                'crackini satiyorum', 'crack satiyorum', 'korsan pdf satiyorum',
                'ders kitaplarinin pdf', 'kitap pdf satiyorum', 'lisans keygen',
                'kirik surum satiyorum', 'bedava netflix hesabi satiyorum',
                'selling cracked', 'cracked version for sale', 'pirated pdf',
                'keygen for sale', 'selling textbook pdfs',
                'продам взломанную', 'пиратская версия',
            ],
        ],
        [
            'kind' => 'animal_abuse',
            'labels' => ['VIO'],
            'severity' => 'S3',
            'phrases' => [
                'kedileri zehirle', 'kopekleri zehirle', 'hayvanlari zehirle',
                'kediyi olduren', 'kopegi olduren', 'kediyi tekmele',
                'hayvana iskence', 'kedi olduru', 'kopek olduru',
                'poison the cats', 'poison the dogs', 'kill the strays',
                'torture animals', 'kick the dog',
                'отравлю кошек', 'убью собаку',
            ],
        ],
        [
            'kind' => 'spam_solicitation',
            'labels' => ['SPAM'],
            'severity' => 'S1',
            'phrases' => [
                'takipci satin al', 'bedava takipci', 'link tikla kazan',
                'hemen tikla kazan', 'dm at kazanc',
                'buy followers', 'free followers', 'click this link to win',
                'dm me for money', 'follow for follow back',
                'накрутка подписчиков', 'бесплатные подписчики', 'переходи по ссылке',
            ],
        ],
    ];

    /**
     * Context rules that stop the engine before scoring. Quoting a slur to
     * report, teach about, condemn or narrate it is not the same act as
     * using it — the old flat blocklist punished all four.
     *
     * @var array<string, list<string>>
     */
    public const EXEMPTIONS = [
        'counterspeech' => [
            'kullanmayi birak', 'hakaret olarak kullanma', 'demeyi birak', 'kullanmayin',
            'stop using', 'dont use', 'do not use', 'as an insult', 'is homophobic',
            'is racist', 'is a slur',
            'перестаньте использовать', 'не используйте', 'как ругательство', 'гомофобное',
        ],
        'reporting_abuse' => [
            'dediler', 'denildi', 'sikayet etmek', 'sikayetci', 'bana yazdilar', 'hakaret etti',
            'called me', 'they called me', 'want to report', 'reporting this', 'i want to report',
            'назвали', 'пожаловаться', 'хочу пожаловаться', 'меня оскорбили',
        ],
        'educational' => [
            'kelimesi', 'sozcugu', 'kelime olarak', 'terimi',
            'the word', 'the term', 'when directed at',
            'слово', 'термин', 'если направлено',
        ],
        'fictional_description' => [
            'filmdeki', 'romandaki', 'dizideki', 'karakter', 'sahnede',
            'movie character', 'the character', 'in the film', 'in the book', 'slams the door',
            'в фильме', 'герой кричит', 'персонаж', 'хлопает дверью',
        ],
        'self_directed' => [
            'kendime', 'kendim', 'kendimi',
            'at myself', 'myself', 'im such an idiot', 'i am such an idiot',
            'на себя', 'сам себя', 'себе',
        ],
        'gameplay' => [
            'bu oyunda', 'oyunda', 'maçta', 'turnuvada',
            'in the next round', 'next round', 'in this game', 'in the game',
            'в следующем раунде', 'раунде', 'в игре',
        ],
    ];

    /** Ambiguity markers that route to a human instead of an auto-action. */
    public const AMBIGUITY = [
        'banter_address' => [
            'arkadasim', 'kanka', 'dostum', 'abi', 'kardesim',
            'bro', 'dude', 'mate', 'bruh', 'buddy',
            'брат', 'братан', 'бро', 'дружище',
        ],
        'sarcasm_praise' => [
            'zeki', 'dahi', 'harika', 'muhtesem',
            'genius', 'brilliant', 'smart', 'clever',
            'гениальность', 'гений', 'умник', 'молодец',
        ],
        'implied_insult' => [
            'kufur etmiyorum', 'hakaret etmiyorum', 'ne oldugunu zaten herkes biliyor',
            'im not insulting you', 'i am not insulting you', 'everyone already knows what you are',
            'не оскорбляю', 'все и так знают кто ты',
        ],
        'vague_reference' => [
            'adini vermeyeyim', 'isim vermeyeyim', 'bazilari',
            'not naming names', 'someone here', 'a person could not be',
            'не буду называть', 'кое кто', 'невозможно быть настолько',
        ],
    ];
}
