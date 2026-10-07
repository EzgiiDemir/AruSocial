<?php

namespace App\Services\Ai;

use App\Services\Sis\SisGateway;
use App\Support\TextFold;

/**
 * What personal data AICAD can actually reach, and what it must say when it
 * cannot.
 *
 * WHY THIS REPLACED A LIST OF PHRASES
 *
 * The behaviour this implements already existed, as a hard-coded array in
 * AskOperations containing entries like '2035', '2027 guz' and
 * 'disiplin yonetmeliginin 7'. Those are not descriptions of a capability;
 * they are the evaluation set copied into the source. They passed the eval
 * and told a student nothing useful, and the moment a student phrased the
 * same question differently they did nothing at all.
 *
 * The real distinction is not which words were typed. It is: WHICH SYSTEM
 * would have to answer this, and is that system connected? "Bugün hangi
 * derslerim var?" needs a student information system. We do not have one
 * wired up. That is a fact about our infrastructure, it is the same fact in
 * all three languages, and it is knowable without guessing.
 *
 * SO THAT THIS STOPS BEING TRUE LATER
 *
 * Each capability knows what would have to be connected for it to work.
 * Anything the existing `SisProvider` boundary serves asks that boundary
 * directly — bind a real provider in AppServiceProvider and those capabilities
 * light up here with no flag to remember and no second switch to disagree with
 * it. The rest read a config flag, and all of them default to false.
 *
 * The capabilities that ARE connected today (profile, appointments, clubs,
 * event attendance, served from our own tables by PersonalContext) are listed
 * too, because a registry that only knows about the missing half cannot tell
 * anyone what the assistant can do.
 *
 * WHAT THIS IS NOT
 *
 * It is not a refusal engine for anything awkward. A question we simply do
 * not have a page about is a retrieval miss, not a capability gap, and it is
 * the model's job to say so. This fires only when a specific integration is
 * the only thing that could possibly answer correctly.
 */
final class PersonalDataCapabilities
{
    // Capabilities backed by a student information system we have not
    // integrated. Each is a real product promise waiting on a real
    // integration, not a category of question we dislike.
    public const TIMETABLE = 'timetable';

    public const GRADES = 'grades';

    public const EXAMS = 'exams';

    public const ATTENDANCE = 'attendance';

    public const ADVISOR = 'advisor';

    public const TRANSCRIPT = 'transcript';

    public const BALANCE = 'balance';

    public const ENROLMENT = 'enrolment';

    public const DISCIPLINE = 'discipline';

    /** Live library circulation — a different system again, also not wired up. */
    public const LIBRARY_LOAN = 'library_loan';

    // Capabilities that ARE connected: these are served from our own tables
    // by PersonalContext and must never be reported as unavailable.
    public const PROFILE = 'profile';

    public const APPOINTMENTS = 'appointments';

    public const CLUBS = 'clubs';

    public const EVENTS = 'events';

    /**
     * Phrases that mean "this question needs that system", in all three
     * languages, folded at match time.
     *
     * Longer and more specific phrasings come first within a row so that
     * "my exam results" resolves to GRADES rather than EXAMS.
     *
     * @var array<string, list<string>>
     */
    private const INTENTS = [
        self::GRADES => [
            'notlarim', 'not ortalamam', 'ortalamam kac', 'gano', 'agno',
            'vize notum', 'final notum', 'sinav sonuclarim', 'sinav sonucum',
            'my grades', 'my marks', 'my gpa', 'my exam results', 'my results',
            'my transcript grades',
            'мои оценки', 'мой средний балл', 'результаты моих экзаменов',
        ],
        self::TIMETABLE => [
            'bugun hangi ders', 'yarin hangi ders', 'ders programim',
            'ders programima', 'derslerim ne zaman', 'kayitli derslerim',
            'hangi derslerim var', 'ders saatlerim', 'bugunku derslerim',
            'what class do i have', 'what classes do i have', 'my timetable',
            'my class timetable', 'my class schedule', 'my schedule',
            'my classes today', 'classes today', 'my lectures',
            'мое расписание', 'моё расписание', 'какие у меня занятия',
            'какие у меня сегодня занятия', 'мои занятия', 'мои пары',
        ],
        self::EXAMS => [
            'sinavim ne zaman', 'sinavlarim', 'sinav takvimim', 'vize ne zaman',
            'finalim ne zaman', 'my exams', 'my exam schedule', 'when is my exam',
            'мои экзамены', 'когда мой экзамен', 'расписание моих экзаменов',
        ],
        self::ATTENDANCE => [
            'devamsizligim', 'devamsizlik durumum', 'kac gun devamsizlik',
            'my attendance', 'my absences', 'моя посещаемость', 'мои пропуски',
        ],
        self::ADVISOR => [
            'akademik danismanim', 'danismanim kim', 'danismanim kimdir',
            'my academic advisor', 'my advisor', 'my adviser', 'who is my advisor',
            'who is my academic advisor', 'my supervisor',
            'мой научный руководитель', 'мой куратор', 'кто мой научный руководитель',
        ],
        self::TRANSCRIPT => [
            'transkriptim', 'transkriptimi', 'my transcript', 'мой транскрипт',
        ],
        self::BALANCE => [
            'borcum', 'odemem', 'ne kadar borcum', 'harc borcum', 'taksitlerim',
            'my balance', 'my tuition balance', 'my payments', 'how much do i owe',
            'моя задолженность', 'мой баланс', 'сколько я должен',
        ],
        self::ENROLMENT => [
            'ogrenci numaram', 'kayit durumum', 'my student number',
            'my student id', 'my enrolment status', 'my enrollment status',
            'мой студенческий номер', 'мой статус',
        ],
        self::DISCIPLINE => [
            'disiplin kaydim', 'disiplin dosyam', 'my disciplinary record',
            'моё дисциплинарное дело',
        ],
        self::LIBRARY_LOAN => [
            'su an bu kitap', 'bu kitap var mi', 'kitap rafta mi',
            'odunc aldigim kitap', 'kitabim ne zaman', 'iade tarihim',
            'is this book available', 'this book available', 'book on loan',
            'my borrowed books', 'my library loans',
            'эта книга доступна', 'мои книги в библиотеке',
        ],
    ];

    /**
     * Which capability this question needs, or null when none is required.
     *
     * Matched on the folded question, longest phrase first, so a specific
     * intent is never shadowed by a shorter one in another row.
     */
    public function detect(string $query): ?string
    {
        $folded = TextFold::fold($query);
        if ($folded === '') {
            return null;
        }

        $best = null;
        $bestLength = 0;
        foreach (self::INTENTS as $capability => $phrases) {
            foreach ($phrases as $phrase) {
                $needle = TextFold::fold($phrase);
                if (mb_strlen($needle) > $bestLength && str_contains($folded, $needle)) {
                    $best = $capability;
                    $bestLength = mb_strlen($needle);
                }
            }
        }

        return $best;
    }

    /**
     * The capabilities the existing SIS boundary can actually serve.
     *
     * `SisProvider` exposes profile, registeredCourses and timetable, and
     * nothing else. Grades, attendance, advisor, balance, transcript and
     * disciplinary record have no method behind them, so they stay false even
     * with a live SIS bound — the interface has to grow first. Listing them
     * here as "connected" the moment a provider appeared would be the same
     * lie this class exists to prevent, just later.
     *
     * @var list<string>
     */
    private const SIS_SERVED = [self::TIMETABLE, self::ENROLMENT];

    /**
     * Is the system behind this capability actually wired up?
     *
     * For anything the SIS boundary serves, this asks the boundary rather than
     * a config flag. `SisProvider` is bound to `UnavailableSisProvider` today
     * and `isAvailable()` returns false; the day a real provider is bound in
     * AppServiceProvider, these capabilities light up with no further change
     * here and no flag to remember. One switch, in the place that already
     * owned the decision.
     *
     * Everything else reads config, and defaults to false — claiming a
     * connection we do not have is the one failure mode this whole class
     * exists to prevent.
     */
    public function isConnected(string $capability): bool
    {
        if (in_array($capability, self::SIS_SERVED, true)) {
            return app(SisGateway::class)->isAvailable();
        }

        return (bool) config('ai.capabilities.'.$capability, false);
    }

    /**
     * The honest answer when the system that owns this data is not connected.
     *
     * Three things, in the student's language: what is missing, that we will
     * not guess it, and where they can get it today. The third is what stops
     * this being a dead end — a student who asks for their timetable still
     * needs their timetable.
     */
    public function unavailableAnswer(string $capability, ?string $language): string
    {
        [$what, $where] = $this->describe($capability, $language);

        return match ($language) {
            'en' => "I cannot see {$what} — ARUCAD's student information system (SIS) is not "
                .'connected to this assistant yet, and I will not guess data about you. '
                ."You can get it from {$where}.",
            'ru' => "Я не вижу {$what} — студенческая информационная система ARUCAD (SIS) пока "
                .'не подключена к ассистенту, и я не буду угадывать ваши данные. '
                ."Это можно узнать здесь: {$where}.",
            default => "{$what} göremiyorum — ARUCAD öğrenci bilgi sistemi (SIS) bu asistana "
                .'henüz bağlı değil ve senin verilerini tahmin etmem. '
                ."Bu bilgiye {$where} ulaşabilirsin.",
        };
    }

    /**
     * What the capability holds, and where a student gets it today.
     *
     * @return array{0: string, 1: string}
     */
    private function describe(string $capability, ?string $language): array
    {
        $en = [
            self::TIMETABLE => ['your class timetable', 'the ARUCAD student portal, or Student Affairs in Titan'],
            self::GRADES => ['your grades', 'the ARUCAD student portal, or Student Affairs in Titan'],
            self::EXAMS => ['your exam schedule', 'the ARUCAD student portal, or your faculty office'],
            self::ATTENDANCE => ['your attendance record', 'the ARUCAD student portal, or your course instructor'],
            self::ADVISOR => ['who your academic advisor is', 'the ARUCAD student portal, or your department secretariat'],
            self::TRANSCRIPT => ['your transcript', 'Student Affairs in Titan'],
            self::BALANCE => ['your tuition balance', 'the Finance Office, or the ARUCAD student portal'],
            self::ENROLMENT => ['your enrolment details', 'the ARUCAD student portal, or Student Affairs in Titan'],
            self::DISCIPLINE => ['your disciplinary record', 'Student Affairs in Titan, in person'],
            self::LIBRARY_LOAN => ['the library\'s live catalogue', 'the library catalogue, or the library desk'],
        ];
        $tr = [
            self::TIMETABLE => ['ders programını', 'ARUCAD öğrenci portalından veya Titan\'daki Öğrenci İşleri\'nden'],
            self::GRADES => ['notlarını', 'ARUCAD öğrenci portalından veya Titan\'daki Öğrenci İşleri\'nden'],
            self::EXAMS => ['sınav takvimini', 'ARUCAD öğrenci portalından veya fakülte sekreterliğinden'],
            self::ATTENDANCE => ['devamsızlık durumunu', 'ARUCAD öğrenci portalından veya ders hocandan'],
            self::ADVISOR => ['akademik danışmanının kim olduğunu', 'ARUCAD öğrenci portalından veya bölüm sekreterliğinden'],
            self::TRANSCRIPT => ['transkriptini', 'Titan\'daki Öğrenci İşleri\'nden'],
            self::BALANCE => ['harç/ödeme durumunu', 'Mali İşler\'den veya ARUCAD öğrenci portalından'],
            self::ENROLMENT => ['kayıt bilgilerini', 'ARUCAD öğrenci portalından veya Titan\'daki Öğrenci İşleri\'nden'],
            self::DISCIPLINE => ['disiplin kaydını', 'Titan\'daki Öğrenci İşleri\'nden bizzat'],
            self::LIBRARY_LOAN => ['kütüphanenin anlık katalog durumunu', 'kütüphane kataloğundan veya kütüphane danışmasından'],
        ];
        $ru = [
            self::TIMETABLE => ['ваше расписание занятий', 'студенческий портал ARUCAD или отдел по работе со студентами в Titan'],
            self::GRADES => ['ваши оценки', 'студенческий портал ARUCAD или отдел по работе со студентами в Titan'],
            self::EXAMS => ['расписание ваших экзаменов', 'студенческий портал ARUCAD или деканат'],
            self::ATTENDANCE => ['вашу посещаемость', 'студенческий портал ARUCAD или преподавателя курса'],
            self::ADVISOR => ['кто ваш научный руководитель', 'студенческий портал ARUCAD или секретариат факультета'],
            self::TRANSCRIPT => ['ваш транскрипт', 'отдел по работе со студентами в Titan'],
            self::BALANCE => ['вашу задолженность по оплате', 'финансовый отдел или студенческий портал ARUCAD'],
            self::ENROLMENT => ['данные о вашем зачислении', 'студенческий портал ARUCAD или отдел по работе со студентами'],
            self::DISCIPLINE => ['ваше дисциплинарное дело', 'отдел по работе со студентами в Titan, лично'],
            self::LIBRARY_LOAN => ['актуальный каталог библиотеки', 'каталог библиотеки или стойку выдачи'],
        ];

        $table = match ($language) {
            'en' => $en,
            'ru' => $ru,
            default => $tr,
        };

        return $table[$capability] ?? $table[self::ENROLMENT];
    }

    /**
     * The warning code for an unavailable capability.
     *
     * `SIS_UNAVAILABLE` is kept for everything a student information system
     * would own, because that is the code this API already emitted and
     * changing it would break any consumer reading it — the registry is new,
     * the contract is not. Capabilities owned by some other system get the
     * generic code, and both carry a `capability` key saying precisely which
     * one is missing.
     */
    public function warningCode(string $capability): string
    {
        return in_array($capability, [
            self::TIMETABLE, self::GRADES, self::EXAMS, self::ATTENDANCE,
            self::ADVISOR, self::TRANSCRIPT, self::BALANCE, self::ENROLMENT,
            self::DISCIPLINE,
        ], true) ? 'SIS_UNAVAILABLE' : 'CAPABILITY_NOT_CONNECTED';
    }

    /**
     * The whole registry, for the health endpoint and the admin panel.
     *
     * Someone asking "why did the assistant say it cannot see my timetable"
     * should be able to read the answer off a status page rather than out of
     * this file.
     *
     * @return array<string, bool>
     */
    public function snapshot(): array
    {
        $out = [];
        foreach ([
            self::TIMETABLE, self::GRADES, self::EXAMS, self::ATTENDANCE,
            self::ADVISOR, self::TRANSCRIPT, self::BALANCE, self::ENROLMENT,
            self::DISCIPLINE, self::LIBRARY_LOAN,
            self::PROFILE, self::APPOINTMENTS, self::CLUBS, self::EVENTS,
        ] as $capability) {
            $out[$capability] = $this->isConnected($capability);
        }

        return $out;
    }
}
