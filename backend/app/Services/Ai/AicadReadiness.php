<?php

namespace App\Services\Ai;

use App\Models\AcademicYear;
use App\Models\AiEntityAlias;
use App\Services\Ai\Facts\SupportedFactsRollout;
use App\Services\Knowledge\KnowledgeBase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Can this environment run AICAD's SupportedFacts path?" — one list of
 * checks, each ok | warn | fail with a short detail, built from what
 * AicadHealth already measures. Used by `php artisan ask:readiness` and the
 * AICAD Health page. Read-only; never renders a credential or a URL secret.
 */
final class AicadReadiness
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** A crawl older than this marks the corpus stale. */
    private const STALE_DAYS = 14;

    public function __construct(private readonly AicadHealth $health) {}

    /**
     * @param  bool  $live  also probe the model, the embedder and a retrieval query (network calls)
     * @return list<array{check: string, status: string, detail: string}>
     */
    public function checks(bool $live = false): array
    {
        $out = [];
        $add = function (string $check, string $status, string $detail) use (&$out): void {
            $out[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
        };

        $mode = SupportedFactsRollout::mode();
        $add('supportedfacts_mode', self::OK, strtoupper($mode).' (AICAD_SUPPORTED_FACT_GENERATION_ENABLED)');
        // Rollback is a config value: off restores Phase 3B with no migration or data change.
        $add('feature_rollback', self::OK, 'available: set AICAD_SUPPORTED_FACT_GENERATION_ENABLED=off, then php artisan config:cache if config is cached');

        $timezone = (string) config('ai.campus_timezone');
        try {
            $local = Carbon::now($timezone);
            $add('campus_timezone', self::OK, $timezone.' (now '.$local->format('D H:i').')');
        } catch (Throwable) {
            $add('campus_timezone', self::FAIL, 'invalid timezone: '.$timezone);
        }

        $queue = $this->health->queue();
        $add('queue', match (true) {
            ! ($queue['inspectable'] ?? false) => self::WARN,
            (bool) ($queue['stalled'] ?? false) => self::FAIL,
            default => self::OK,
        }, ($queue['inspectable'] ?? false)
            ? ($queue['stalled'] ? 'jobs waiting over 5 minutes: no worker is running' : $queue['pending'].' pending, '.$queue['running'].' running')
            : 'connection '.$queue['connection'].' cannot be inspected from here');

        $aliases = Schema::hasTable('ai_entity_aliases') ? AiEntityAlias::query()->where('entity_type', AiEntityAlias::TYPE_PROGRAMME)->count() : 0;
        $add('programme_aliases', $aliases > 0 ? self::OK : self::FAIL,
            $aliases > 0 ? "seeded ({$aliases})" : 'missing: php artisan db:seed --class=AiProgrammeAliasSeeder');

        $campus = Schema::hasTable('ai_entity_aliases')
            ? AiEntityAlias::query()->whereIn('entity_type', [AiEntityAlias::TYPE_SERVICE, AiEntityAlias::TYPE_CLUB])->where('created_by', 'seeder')->count() : 0;
        $add('campus_aliases', $campus > 0 ? self::OK : self::WARN,
            $campus > 0 ? "seeded ({$campus} office/club aliases)" : 'missing: php artisan db:seed --class=AiCampusAliasSeeder');

        // Term dates come from the official calendar; this record decides event
        // year tagging and evidence staleness, so an ended "active" year is a warning.
        $year = AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->first();
        $add('academic_year', match (true) {
            $year === null => self::WARN,
            $year->ends_on !== null && now()->greaterThan($year->ends_on) => self::WARN,
            default => self::OK,
        }, $year === null ? 'no active academic year: Admin → Academic years'
            : "active {$year->label} until ".$year->ends_on?->toDateString().($year->ends_on !== null && now()->greaterThan($year->ends_on) ? ' — ended: add the current year in Admin → Academic years' : ''));

        $index = $this->health->index();
        $lastCrawl = $index['last_crawl'] ? Carbon::parse($index['last_crawl']) : null;
        $add('knowledge_corpus', match (true) {
            $index['pages'] === 0 => self::FAIL,
            $lastCrawl === null || $lastCrawl->lt(now()->subDays(self::STALE_DAYS)) => self::WARN,
            default => self::OK,
        }, $index['pages'] === 0 ? 'missing: no indexed pages'
            : $index['pages'].' pages, '.$index['embedded'].'/'.$index['passages'].' passages embedded, last crawl '.($lastCrawl?->diffForHumans() ?? 'never')
                .($lastCrawl !== null && $lastCrawl->lt(now()->subDays(self::STALE_DAYS)) ? ' (stale)' : ''));
        $add('programme_facts', $index['programme_facts'] > 0 ? self::OK : self::WARN, $index['programme_facts'].' programme facts');

        $run = $this->health->lastRun();
        $add('latest_evaluation', match (true) {
            $run === null => self::WARN,
            (int) $run->failed > 0 => self::WARN,
            default => self::OK,
        }, $run === null ? 'no run yet' : '#'.$run->id.' '.$run->mode.': '.$run->passed.' passed, '.$run->failed.' failed, '.$run->created_at?->toDateTimeString());

        $warnings = $this->health->dataWarnings();
        $add('known_data_gaps', $warnings === [] ? self::OK : self::WARN,
            $warnings === [] ? 'none' : implode('; ', array_map(fn ($w) => $w['area'].' → '.implode(', ', $w['affects'] ?? []), $warnings)));

        if ($live) {
            foreach ($this->health->probes() as $name => $probe) {
                $add($name === 'model' ? 'ollama_model' : 'embedder', $probe['ok'] ? self::OK : self::FAIL, $probe['message']);
            }
            try {
                $started = microtime(true);
                $hits = app(KnowledgeBase::class)->relevant('ARUCAD burs başvurusu', 3);
                $add('retrieval', $hits !== [] ? self::OK : self::FAIL,
                    count($hits).' hit(s) for a fixed probe query in '.round((microtime(true) - $started) * 1000).' ms');
            } catch (Throwable $e) {
                $add('retrieval', self::FAIL, 'probe failed: '.class_basename($e));
            }
        } else {
            $add('ollama_model', self::WARN, 'not probed (run with --live)');
            $add('embedder', self::WARN, 'not probed (run with --live)');
            $add('retrieval', self::WARN, 'not probed (run with --live)');
        }

        return $out;
    }

    /** ready | degraded | not_ready, from the checks. */
    public static function verdict(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAIL, $statuses, true) => 'not_ready',
            in_array(self::WARN, $statuses, true) => 'degraded',
            default => 'ready',
        };
    }
}
