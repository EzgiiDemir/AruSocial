<?php

namespace App\Services\Ai;

use App\Jobs\CrawlKnowledgeUrlJob;
use App\Jobs\EmbedKnowledgeDocumentJob;
use App\Jobs\RunAiEvaluationJob;
use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Models\AiEvaluationRun;
use App\Models\Club;
use App\Models\FoodVenue;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Models\OpeningHour;
use App\Models\Place;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Services\Ai\Planning\TaskExecutor;
use App\Services\Knowledge\EmbeddingClient;
use App\Support\TextFold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What staff need to see to know whether AICAD can work, without a shell:
 * is the queue moving, are the model and embedder up, how fresh is the
 * index, when did the evaluation suite last run — and which DATA gaps
 * explain answers no code change can fix. Read-only; no credentials.
 */
final class AicadHealth
{
    /** Queued AICAD work: crawl now, re-embed, evaluation runs. */
    private const JOBS = [CrawlKnowledgeUrlJob::class, EmbedKnowledgeDocumentJob::class, RunAiEvaluationJob::class];

    /**
     * Words that name a role or a unit rather than a person. Measured on the
     * live directory: all 58 staff rows are of this kind ("Mimarlık
     * Danışmanı", "Spor Koordinatörü", "Öğrenci İşleri").
     */
    private const ROLE_WORDS = '/(Başkanlığı|Dekanlığı|Müdürlüğü|Ofis|Rektör|Danışman|Koordinatör|Birim|Destek|Merkez|İşleri|Kaynakları'
        .'|Güvenlik|Kampüs|Sorumlu|Office|Directorate|Dean|Coordinator|Advisor|Unit|Cent(er|re)|Support|Affairs)/u';

    /** Whether a directory name is an office or role, not a person. */
    public static function isUnitName(string $name): bool
    {
        return (bool) preg_match(self::ROLE_WORDS, $name);
    }

    /** A pending job older than this means no worker is taking jobs. */
    private const STALL_SECONDS = 300;

    /** @return array<string, mixed> */
    public function queue(): array
    {
        $connection = (string) config('queue.default');
        if ($connection !== 'database' || ! Schema::hasTable('jobs')) {
            return ['connection' => $connection, 'inspectable' => false];
        }
        $aicad = fn ($q) => $q->where(function ($w): void {
            foreach (self::JOBS as $job) {
                $w->orWhere('payload', 'like', '%'.addslashes(class_basename($job)).'%');
            }
        });
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $age = $oldest === null ? null : max(0, now()->timestamp - (int) $oldest);

        return [
            'connection' => $connection,
            'inspectable' => true,
            'pending' => DB::table('jobs')->whereNull('reserved_at')->count(),
            'pending_aicad' => $aicad(DB::table('jobs')->whereNull('reserved_at'))->count(),
            'running' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
            'oldest_pending_seconds' => $age,
            'stalled' => $age !== null && $age > self::STALL_SECONDS,
            'failed_aicad_7d' => Schema::hasTable('failed_jobs')
                ? $aicad(DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7)))->count()
                : null,
        ];
    }

    /**
     * Live probes of the model and the embedder. Network calls, so only on
     * request from the page, never on every render.
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    public function probes(): array
    {
        $primary = app(AiProviderManager::class)->primary();
        $model = $primary === null
            ? ['ok' => false, 'message' => 'no primary provider configured']
            : (function () use ($primary): array {
                $health = $primary->healthCheck();

                return ['ok' => $health->ok, 'message' => $primary->label().': '.$health->message];
            })();

        $embeddings = app(EmbeddingClient::class);
        $vector = $embeddings->isEnabled() ? $embeddings->embedOne('ARUCAD') : null;
        $embedder = ! $embeddings->isEnabled()
            ? ['ok' => false, 'message' => 'disabled (knowledge.embeddings.enabled)']
            : ($vector === null
                ? ['ok' => false, 'message' => 'not responding']
                : ['ok' => true, 'message' => count($vector).'-dimension vectors ('.config('knowledge.embeddings.model_label').')']);

        return ['model' => $model, 'embedder' => $embedder];
    }

    /** @return array<string, mixed> */
    public function index(): array
    {
        return [
            'pages' => KnowledgeDocument::query()->where('document_status', 'indexed')->count(),
            'passages' => KnowledgeChunk::query()->count(),
            'embedded' => KnowledgeChunk::query()->whereNotNull('embedding')->count(),
            'last_crawl' => KnowledgeDocument::query()->max('fetched_at'),
            'last_embedding' => KnowledgeChunk::query()->whereNotNull('embedding')->max('updated_at'),
            'programme_facts' => Schema::hasTable('knowledge_facts') ? KnowledgeFact::query()->count() : 0,
            'aliases' => Schema::hasTable('ai_entity_aliases') ? AiEntityAlias::query()->count() : 0,
        ];
    }

    public function lastRun(): ?AiEvaluationRun
    {
        return Schema::hasTable('ai_evaluation_runs')
            ? AiEvaluationRun::query()->orderByDesc('id')->first()
            : null;
    }

    /**
     * Data gaps that explain unanswerable questions. Diagnostics for
     * staff, never shown to students; nothing here is invented to fill them.
     * `affects` names the fact/task types the gap leaves UNAVAILABLE, so a
     * content gap is not mistaken for an AI defect.
     *
     * @return list<array{area: string, message: string, affects: list<string>}>
     */
    public function dataWarnings(): array
    {
        $warnings = [];

        // Term dates come from the official calendar page (AcademicCalendar);
        // the academic_years record still drives temporal staleness checks.
        $calendar = app(AcademicCalendar::class)->current();
        $latest = AcademicYear::query()->orderByDesc('ends_on')->first();
        $calendarProblems = [];
        $affects = [];
        if ($calendar === null || $calendar['stale']) {
            $calendarProblems[] = $calendar === null ? 'the official academic calendar page is not indexed, so term dates cannot be answered'
                : "the official academic calendar ({$calendar['academic_year']}) has ended and the next one is not published";
            $affects[] = 'academic_date';
        }
        if ($latest === null) {
            $calendarProblems[] = 'no academic-year record exists';
            $affects[] = 'academic_year';
        } elseif ($latest->ends_on !== null && now()->greaterThan($latest->ends_on)) {
            $calendarProblems[] = "the latest academic-year record ({$latest->label}) ended on ".substr((string) $latest->ends_on, 0, 10)
                .($latest->is_active ? ' and is still marked active' : '')
                .' (still used by: academic-year tagging of new student events, the event form’s year list and evidence staleness checks;'
                .' not by AICAD term dates). Add the current year in Admin → Academic years and make it active';
            $affects[] = 'academic_year (stale: event tagging, temporal checks)';
        }
        if ($calendarProblems !== []) {
            $warnings[] = ['area' => 'calendar', 'message' => ucfirst(implode('; ', $calendarProblems)).'.', 'affects' => $affects];
        }

        $venues = FoodVenue::query()->get(['id', 'name', 'hours']);
        if ($venues->isNotEmpty()) {
            $noHours = $venues->filter(fn ($v) => trim((string) $v->hours) === '' && OpeningHour::query()->for('food_venue', (string) $v->id)->doesntExist())->count();
            if ($noHours > 0) {
                $warnings[] = ['area' => 'food', 'message' => "{$noHours} of {$venues->count()} food venues have no opening hours; \"which food place is open now\" cannot be answered.",
                    'affects' => ['food.current_opening_hours', 'food.is_open_now', 'task: filter_open_now']];
            }
            $unplaced = $venues->filter(fn ($v) => TaskExecutor::venuePlace($v)?->lat === null)->count();
            if ($unplaced > 0) {
                $warnings[] = ['area' => 'food', 'message' => "{$unplaced} of {$venues->count()} food venues are not linked to a campus place with coordinates; no nearest-venue ranking or route.",
                    'affects' => ['food.place_coordinates', 'task: rank_by_distance', 'task: route']];
            }
        }

        $clubs = Club::query()->get(['instagram_url', 'place_id']);
        if ($clubs->isNotEmpty()) {
            $noSocial = $clubs->filter(fn ($c) => trim((string) $c->instagram_url) === '')->count();
            $noRoom = $clubs->whereNull('place_id')->count();
            if ($noSocial > 0 || $noRoom > 0) {
                $warnings[] = ['area' => 'clubs', 'message' => "{$noSocial} of {$clubs->count()} clubs have no official Instagram URL and {$noRoom} have no room / place.",
                    'affects' => array_values(array_filter([$noSocial > 0 ? 'club.social_profile' : null, $noRoom > 0 ? 'club.place_coordinates' : null]))];
            }
        }

        $warnings[] = ['area' => 'announcements', 'message' => 'No announcements or hour-exception source is connected; temporary closures and changed hours cannot reach AICAD.',
            'affects' => ['current_opening_hours exceptions', 'official_announcement evidence']];

        $places = Place::query()->pluck('name')->map(fn ($n) => TextFold::fold((string) $n))->all();
        $missing = Sport::query()->pluck('facility')->filter()->unique()
            ->reject(fn ($facility) => collect($places)->contains(fn ($p) => $p !== '' && str_contains($p, TextFold::fold((string) $facility))))
            ->values()->all();
        if ($missing !== []) {
            $warnings[] = ['area' => 'sports', 'message' => 'Sports facilities with no place record (no card, no route): '.implode(', ', $missing).'.',
                'affects' => ['sport.place_coordinates', 'task: route']];
        }

        $staff = StaffProfile::query()->pluck('name');
        if ($staff->isNotEmpty()) {
            $offices = $staff->filter(fn ($name) => self::isUnitName((string) $name))->count();
            if ($offices / $staff->count() >= 0.8) {
                $warnings[] = ['area' => 'directory', 'message' => "{$offices} of {$staff->count()} staff records are offices, not people; \"who is the head of X\" cannot be answered with a name.",
                    'affects' => ['staff.person', 'staff.room']];
            }
        }

        if (Schema::hasTable('knowledge_facts') && KnowledgeFact::query()->count() === 0) {
            $warnings[] = ['area' => 'programmes', 'message' => 'No programme facts extracted yet; run php artisan knowledge:extract-facts or wait for the next crawl.',
                'affects' => ['program_language']];
        }
        if (Schema::hasTable('ai_entity_aliases') && AiEntityAlias::query()->where('entity_type', AiEntityAlias::TYPE_PROGRAMME)->doesntExist()) {
            $warnings[] = ['area' => 'programmes', 'message' => 'No programme name aliases: English/Russian programme names will not resolve. Run php artisan db:seed --class=AiProgrammeAliasSeeder.',
                'affects' => ['program_language (en/ru names)']];
        }

        return $warnings;
    }
}
