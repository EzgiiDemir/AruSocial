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
        'korkak', 'tavuk', 'cabuk kizan',
        'coward', 'chicken', 'crybaby', 'clown',
        'трус', 'нытик', 'клоун',
    ];

    /** Coarse language that is not, by itself, aimed at a person. */
    public const PROFANITY = [
        'lanet', 'bok', 'kahretsin', 'hasiktir',
        'damn', 'shit', 'crap', 'bloody hell', 'xscat',
        'чёрт', 'черт', 'дерьм', 'блин',
    ];

    /** Strong obscenity: still allowed untargeted, removed when targeted. */
    public const PROFANITY_STRONG = [
        'siktir', 'sikeyim', 'yarrak', 'amcik',
        'fuck', 'fucking', 'motherfucker', 'cunt',
        'хуй', 'хуя', 'хуё', 'пизд', 'ебат', 'ебан', 'блядь', 'бляд', 'сука',
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
    public const PHRASE_RULES = [
        [
            'kind' => 'threat',
            'labels' => ['THR', 'VIO'],
            'severity' => 'S4',
            'phrases' => [
                'yuzunu dagitirim', 'seni oldur', 'seni geberte', 'gebertirim', 'seni vurac',
                'canini yakarim', 'parcalarim seni', 'seni bulup', 'seni bulacagim',
                'hesabini soracagiz', 'kemiklerini kirarim',
                'smash your face', 'i will kill you', 'ill kill you', 'i will hurt you',
                'beat you up', 'we will find you', 'well find you', 'make you pay',
                'break your legs', 'come after you',
                'лицо разобью', 'я тебя убью', 'тебя найдём', 'тебя найдем',
                'заставим заплатить', 'тебе конец', 'я тебя урою',
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
            'kind' => 'worthlessness',
            'labels' => ['HAR'],
            'severity' => 'S2',
            'phrases' => [
                'oksijene yazik', 'dunyadaki oksijene', 'nefes almana yazik',
                'bos ve gereksiz',
                'room improves when you leave', 'youre useless', 'you are useless',
                'world would be better without you',
                'без тебя всем лучше', 'ты бесполезный', 'от тебя никакого толку',
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
            // Party-political campaigning. Deliberately named parties and
            // candidate titles only — bare words like "seçim" would flag the
            // student council elections this platform exists to host.
            'kind' => 'political',
            'labels' => ['POL'],
            'severity' => 'S2',
            'phrases' => [
                'akp', 'chp', 'mhp', 'hdp', 'iyi parti', 'cumhurbaskani adayi',
                'milletvekili adayi', 'genel secimlerde oy',
                'republican party', 'democratic party', 'presidential candidate',
                'senate race', 'prime minister candidate',
                'единая россия', 'государственная дума', 'выборы президента',
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
            'phrases' => [
                'intihar edecegim', 'intihar etmek istiyorum', 'kendimi oldurecegim',
                'kendimi olduren', 'canima kiyacagim', 'artik yasamak istemiyorum',
                'yasamak istemiyorum', 'kendime zarar veriyorum', 'bileklerimi kestim',
                'kill myself', 'killing myself', 'end my life', 'ending my life',
                'want to die', 'dont want to live', 'do not want to live',
                'self harm', 'cut myself', 'cutting myself', 'suicidal',
                'покончить с собой', 'покончу с собой', 'убить себя',
                'не хочу жить', 'режу себя', 'причиняю себе вред',
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
                'kolay para kazan', 'garanti kazanc', 'garantili kazanc',
                'yatirim firsati kacirmayin', 'kripto ile zengin', 'paranizi katliyorum',
                'burs garantisi veriyorum', 'kesin burs alirsin', 'once kapora gonder',
                'depozito gonder sonra', 'iban gonderiyorum acil', 'kapora yatirin',
                'guaranteed returns', 'guaranteed profit', 'easy money fast',
                'double your money', 'send the deposit first', 'pay the deposit first',
                'guaranteed scholarship', 'wire the money first',
                'гарантированный доход', 'лёгкие деньги', 'удвою ваши деньги',
                'сначала переведи задаток', 'гарантирую стипендию',
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
                'silahim var', 'okula silah getir', 'bicak getirecegim',
                'silahla gelecegim', 'kampuse silah',
                'i have a gun', 'bring a gun', 'bringing a knife', 'shoot up the',
                'bring a knife to', 'gun to campus',
                'у меня есть оружие', 'принесу нож', 'приду с оружием',
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
