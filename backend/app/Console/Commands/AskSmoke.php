<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\User;
use App\Services\Ai\AcademicCalendar;
use App\Services\Ai\Facts\ClaimVerifier;
use App\Support\TextFold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The staging controller smoke pack: real HTTP requests to /ai/query on the
 * deployed API — auth, middleware, throttling and the controller included —
 * as a staff or test account. Run it ON the staging host so expectations are
 * derived from that server's own canonical data (a phone is expected only
 * where one is recorded), which keeps the checks honest as staff fill data.
 *
 *   php artisan ask:smoke --base=https://<staging-api>/api/v1 --as=<test account e-mail>
 *
 * Nothing is stored by this command: answers are printed truncated (or not at
 * all with --no-answers). A short-lived token is created and revoked. Refuses
 * to run in production.
 */
class AskSmoke extends Command
{
    protected $signature = 'ask:smoke
        {--base= : API base URL, e.g. https://staging-api.example/api/v1}
        {--as= : E-mail of the staff/test account to ask as}
        {--pace=3.5 : Seconds between requests (the ai route is rate limited)}
        {--no-answers : Do not print answer text}
        {--json : Print the results as JSON}';

    protected $description = 'Run the AICAD controller smoke pack against a deployed API (staging only)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }
        $base = rtrim((string) $this->option('base'), '/');
        $user = User::query()->where('email', (string) $this->option('as'))->first();
        if ($base === '' || $user === null) {
            $this->error('--base and --as=<existing account e-mail> are required.');

            return self::FAILURE;
        }

        $token = $user->createToken('ask-smoke');
        $results = [];
        try {
            foreach ($this->cases() as $i => $case) {
                if ($i > 0) {
                    usleep((int) ((float) $this->option('pace') * 1_000_000));
                }
                $results[] = $this->sendCase($base, $token->plainTextToken, $case);
            }
        } finally {
            $token->accessToken->delete();
        }

        $failed = array_filter($results, fn ($r) => ! $r['ok']);
        if ($this->option('json')) {
            $this->line(json_encode(['passed' => count($results) - count($failed), 'failed' => count($failed), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['', 'case', 'mode', 'ms', 'check'], array_map(fn ($r) => [$r['ok'] ? 'PASS' : 'FAIL', $r['name'], $r['ai_mode'], $r['ms'], $r['detail']], $results));
            if (! $this->option('no-answers')) {
                foreach ($results as $r) {
                    $this->line('· '.$r['name'].': '.$r['answer']);
                }
            }
            $ms = array_column($results, 'ms');
            sort($ms);
            $this->info(sprintf('%d passed, %d failed; latency median %d ms, max %d ms', count($results) - count($failed), count($failed),
                $ms[intdiv(count($ms), 2)] ?? 0, end($ms) ?: 0));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function sendCase(string $base, string $token, array $case): array
    {
        $body = ['prompt' => $case['q']];
        if (isset($case['history'])) {
            $body['messages'] = [...array_map(fn ($h) => ['role' => 'user', 'content' => $h], $case['history']), ['role' => 'user', 'content' => $case['q']]];
        }
        if (isset($case['location'])) {
            $body['currentLocation'] = $case['location'];
        }
        $started = microtime(true);
        $response = Http::withToken($token)->acceptJson()->timeout(120)->post($base.'/ai/query', $body);
        $ms = (int) round((microtime(true) - $started) * 1000);
        $answer = (string) $response->json('data.answer');
        $mode = (string) $response->json('data.aiMode');

        [$ok, $detail] = match (true) {
            ! $response->successful() => [false, 'HTTP '.$response->status()],
            trim($answer) === '' => [false, 'empty answer'],
            in_array($mode, ['none', 'refused_injection'], true) => [false, 'aiMode '.$mode],
            default => ($case['check'])($answer, $mode),
        };

        return ['name' => $case['name'], 'ok' => $ok, 'detail' => $detail, 'ai_mode' => $mode, 'ms' => $ms,
            'answer' => mb_strimwidth(str_replace("\n", ' ', $answer), 0, 160, '…')];
    }

    /** @return list<array{name: string, q: string, check: callable, history?: list<string>, location?: array}> */
    private function cases(): array
    {
        $ok = fn (string $why = 'answered') => fn () => [true, $why];
        $has = fn (string $answer, string $needle) => mb_stripos($answer, $needle) !== false;
        $phonePattern = '/\+?\d[\d\s().-]{6,}\d/u';

        // Expectations from this server's canonical data.
        $affairs = ServiceItem::query()->find('student-affairs');
        $affairsPhone = $affairs !== null && trim((string) $affairs->phone) !== '';
        $language = KnowledgeFact::query()->where('attribute', KnowledgeFact::LANGUAGE)->where('subject_folded', 'mimarlik')->value('value');
        $duration = KnowledgeFact::query()->where('attribute', KnowledgeFact::DURATION)->where('subject_folded', 'gorsel iletisim tasarimi')->value('value');
        $calendar = app(AcademicCalendar::class)->current();
        $nextStart = $calendar === null || $calendar['stale'] ? null : (app(AcademicCalendar::class)->ofKind($calendar, 'classes_start')['next']['start'] ?? null);
        $club = Club::query()->find('club-photography');
        $clubInstagram = trim((string) $club?->instagram_url);
        $venueHours = FoodVenue::query()->get()->contains(fn ($v) => trim((string) $v->hours) !== '' || OpeningHour::query()->for('food_venue', (string) $v->id)->exists());
        $library = ['lat' => 35.3361, 'lng' => 33.3201];

        $phoneCheck = fn (string $a) => $affairsPhone
            ? [$has($a, trim((string) $affairs->phone)), 'recorded phone stated verbatim']
            : [! preg_match($phonePattern, $a), 'no phone recorded → none stated'];
        // A named person is fine only when an indexed official page names them.
        $noTitledPerson = function (string $a): array {
            if (! preg_match('/(?:Prof\.|Doç\.|Dr\.)\s*(?:Dr\.\s*)?(\p{Lu}\p{Ll}+(?:\s+\p{Lu}\p{Ll}+)?)/u', $a, $m)) {
                return [true, 'no person named'];
            }
            $grounded = KnowledgeDocument::query()->where('document_status', 'indexed')->where('content_folded', 'like', '%'.TextFold::fold($m[1]).'%')->exists();

            return [$grounded, $grounded ? "named person '{$m[1]}' is on an official page" : "named person '{$m[1]}' is on no indexed page"];
        };
        $clubIg = fn (string $a) => $clubInstagram === ''
            ? [! $has($a, 'instagram.com'), 'no Instagram recorded → none stated']
            : [$has($a, $clubInstagram), 'recorded Instagram stated'];
        $calendarCheck = fn (string $year) => fn (string $a) => $nextStart === null ? [true, 'no current calendar indexed'] : [$has($a, substr($nextStart, 0, 4)), "next start {$nextStart} ({$year})"];
        // Sentences that report a gap ("şu an açık olan yerler bulunamadı") are not claims.
        $verifier = app(ClaimVerifier::class);
        $claims = fn (string $a) => implode(' ', array_filter($verifier->sentences($a), fn ($s) => ! $verifier->reportsGap($s)));
        $openFood = fn (string $a) => $venueHours ? [true, 'venue hours recorded']
            : [! preg_match('/şu an açık|open now|сейчас открыт|^\s*(evet|yes|да)\b|açık (bir )?(yemek )?yer(i)? (var|vardır)|there (is|are) (an? )?open/iu', $claims($a)),
                'no venue hours → never claims an open place'];
        $placeNames = Place::query()->pluck('name')->map(fn ($n) => TextFold::fold((string) $n))->filter(fn ($n) => mb_strlen($n) >= 4)->all();
        $clubRoom = function (string $a) use ($club, $placeNames): array {
            if ($club?->place_id !== null) {
                return [true, 'room recorded'];
            }
            $folded = TextFold::fold($a);
            $named = collect($placeNames)->first(fn ($n) => str_contains($folded, $n));

            return [$named === null && ! preg_match('/\d{2}\.\d{4,}/', $a),
                $named === null ? 'no room recorded → no place or coordinates stated' : "no room recorded, but names place '{$named}'"];
        };

        return [
            ['name' => 'fast: daily menu (tr)', 'q' => 'bugün yemekte ne var', 'check' => $ok()],
            ['name' => 'fast: events (en)', 'q' => 'what events are on today', 'check' => $ok()],
            ['name' => 'fact: office phone (tr)', 'q' => 'öğrenci işlerinin telefonu ne', 'check' => $phoneCheck],
            ['name' => 'fact: office phone (ru)', 'q' => 'какой телефон у студенческого офиса', 'check' => $phoneCheck],
            ['name' => 'fact: programme language (tr, no diacritics)', 'q' => 'mimarlik hangi dilde', 'check' => fn ($a) => $language === null ? [true, 'no language fact']
                : [$has($a, $language) || $has($a, $language === 'İngilizce' ? 'English' : 'Turkish') || $has($a, 'ingilizce'), "states {$language}"]],
            ['name' => 'fact: programme duration (tr)', 'q' => 'Görsel İletişim Tasarımı kaç yıl?', 'check' => fn ($a) => $duration === null ? [true, 'no duration fact'] : [(bool) preg_match('/\d/u', $a) && $has($a, preg_replace('/\D.*/u', '', (string) $duration)), "states {$duration}"]],
            ['name' => 'fact: academic dates (tr)', 'q' => 'dersler ne zaman başlıyor', 'check' => $calendarCheck('tr')],
            ['name' => 'fact: academic dates (en)', 'q' => 'when does the semester start', 'check' => $calendarCheck('en')],
            ['name' => 'fact: academic dates (ru)', 'q' => 'когда начинаются занятия', 'check' => $calendarCheck('ru')],
            ['name' => 'plan: office hours + documents', 'q' => 'Öğrenci işleri bugün açık mı, hangi belgeleri götürmeliyim?', 'check' => $ok()],
            ['name' => 'plan: club follow-up', 'q' => 'kulübün instagramı ne ve odası nerede?', 'history' => ['Fotoğraf kulübü hakkında bilgi ver'], 'check' => $clubIg],
            ['name' => 'plan: open food place', 'q' => 'açık yemek yeri var mı', 'check' => $openFood],
            ['name' => 'nav: route with location', 'q' => 'buradan kütüphaneye nasıl giderim', 'location' => $library, 'check' => $ok()],
            ['name' => 'nav: route without location', 'q' => 'kütüphaneye nasıl giderim', 'check' => $ok()],
            ['name' => 'temporal: open now (en)', 'q' => 'is the library open now', 'check' => $ok()],
            ['name' => 'temporal: tomorrow (tr)', 'q' => 'kütüphane yarın açık mı', 'check' => $ok()],
            ['name' => 'temporal: weekend (tr)', 'q' => 'kütüphane hafta sonu açık mı', 'check' => $ok()],
            ['name' => 'gap: club Instagram', 'q' => 'fotoğraf kulübünün instagramı ne ve maili ne', 'check' => $clubIg],
            ['name' => 'gap: club room (en)', 'q' => "where is the photography club's room and what is its instagram", 'check' => fn ($a) => ($r = $clubRoom($a))[0] ? $clubIg($a) : $r],
            ['name' => 'gap: staff person', 'q' => 'mimarlık bölüm başkanı kim ve maili ne', 'check' => $noTitledPerson],
        ];
    }
}
