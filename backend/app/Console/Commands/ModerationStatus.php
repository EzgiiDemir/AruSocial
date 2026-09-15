<?php

namespace App\Console\Commands;

use App\Models\ChatGroupMessage;
use App\Models\ChatMessage;
use App\Models\CollaborationPost;
use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationAppeal;
use App\Models\ModerationEvent;
use App\Models\ModerationReport;
use App\Models\PostComment;
use App\Models\Review;
use App\Models\Story;
use App\Services\Moderation\AccountEnforcement;
use App\Services\Moderation\Image\FastApiImageModerationProvider;
use App\Services\Moderation\SelfHostedTextClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;

/**
 * Whether the moderation pipeline is actually working right now.
 *
 * Every failure this reports is one that looks like nothing from the
 * outside, which is why it exists. A stopped classifier makes every
 * upload 503 and reads to a student as "my photo was rejected" — that
 * exact confusion cost this project a debugging session. Held content
 * with no moderator draining the queue is worse: it looks like a
 * successful upload that silently never appears.
 *
 * Exits non-zero when something needs a human, so it can be a cron check
 * rather than something someone has to remember to run.
 */
class ModerationStatus extends Command
{
    protected $signature = 'moderation:status
                            {--json : Machine-readable output for monitoring}
                            {--stale-hours=24 : Age at which held content counts as stuck}';

    protected $description = 'Report moderation pipeline health: classifier, queue depth, stuck content';

    /** Content types that can sit in `pending`, and where their clock starts. */
    private const HELD = [
        'media' => [MediaItem::class, 'uploaded_at'],
        'post' => [FeedPost::class, 'created_at'],
        'story' => [Story::class, 'created_at'],
        'comment' => [PostComment::class, 'created_at'],
        'review' => [Review::class, 'created_at'],
        'workshop post' => [CollaborationPost::class, 'created_at'],
        'chat message' => [ChatMessage::class, 'created_at'],
        'group message' => [ChatGroupMessage::class, 'created_at'],
    ];

    public function handle(): int
    {
        $staleHours = max(1, (int) $this->option('stale-hours'));

        $report = [
            'enforcement' => AccountEnforcement::enabled() ? 'on' : 'off',
            'classifier' => $this->classifier(),
            'text' => $this->textLayer(),
            'held' => $this->heldContent(),
            'decisions_24h' => $this->recentDecisions(),
            'reports' => $this->reports(),
            'appeals' => $this->appeals(),
            'policy' => $this->policyVersions(),
        ];

        $problems = $this->problemsIn($report, $staleHours);
        $report['problems'] = $problems;

        $this->raise($report, $problems);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($report, $problems, $staleHours);

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Make a problem visible somewhere a person will actually see it.
     *
     * Until this existed the command computed the problem list, printed it
     * to a terminal nobody was watching and returned a non-zero exit code.
     * Run from the scheduler that exit code goes nowhere: the classifier
     * could be down for a week, every upload failing closed with a 503,
     * and the only trace would be students saying their photos "don't
     * work".
     *
     * Two destinations on purpose. The log line is greppable and always
     * written, which is what the daily check in the runbook reads; Sentry
     * is what wakes somebody up, and no-ops when no DSN is configured.
     *
     * @param  array<string, mixed>  $report
     * @param  list<string>  $problems
     */
    private function raise(array $report, array $problems): void
    {
        if ($problems === []) {
            // Written at debug so "the check ran and was happy" is
            // distinguishable from "the check never ran" — the two look
            // identical in a log that only records failures.
            Log::debug('moderation.status.ok', [
                'held' => $this->heldTotal($report),
                'enforcement' => $report['enforcement'],
            ]);

            return;
        }

        $context = [
            'problems' => $problems,
            'enforcement' => $report['enforcement'],
            'classifier' => $report['classifier']['status'] ?? null,
            'text' => $report['text']['status'] ?? null,
            'held_total' => $this->heldTotal($report),
            'reports_unresolved' => $report['reports']['unresolved'] ?? null,
            'appeals_open' => $report['appeals']['open'] ?? null,
        ];

        Log::error('moderation.status.problems', $context);

        if (! function_exists('\Sentry\captureMessage')) {
            return;
        }

        try {
            \Sentry\withScope(function (Scope $scope) use ($context, $problems): void {
                $scope->setContext('moderation', $context);
                \Sentry\captureMessage(
                    'Moderation pipeline needs attention: '.$problems[0],
                    Severity::error(),
                );
            });
        } catch (\Throwable $e) {
            // Alerting must never be the reason the check itself fails.
            Log::warning('moderation.status.alert_failed', ['message' => $e->getMessage()]);
        }
    }

    /**
     * `held` is keyed by surface, not totalled — an alert that says
     * `held_total: null` tells the person reading it nothing.
     *
     * @param  array<string, mixed>  $report
     */
    private function heldTotal(array $report): int
    {
        return array_sum(array_map(
            fn (array $row): int => (int) ($row['count'] ?? 0),
            $report['held'] ?? [],
        ));
    }

    /** @return array<string, mixed> */
    private function classifier(): array
    {
        if (! app(FastApiImageModerationProvider::class)->isConfigured()) {
            return ['status' => 'disabled'];
        }

        $base = rtrim((string) config('moderation.image.base_url'), '/');

        try {
            $response = Http::timeout(5)->get($base.'/health');
        } catch (\Throwable) {
            return ['status' => 'unreachable', 'url' => $base];
        }

        if (! $response->successful()) {
            return ['status' => $response->status() === 503 ? 'model_not_loaded' : 'unhealthy',
                'url' => $base];
        }

        // Which signals actually answered. The service reports each one
        // it loaded; a missing entry means that half of the pipeline is
        // not running even though the process is up. CLIP is what catches
        // the nudity the NSFW model measurably misses, so "classifier ok"
        // without it is a materially weaker system wearing the same word.
        $signals = $response->json('signals');

        return [
            'status' => 'ok',
            'model' => $response->json('model'),
            'model_version' => $response->json('model_version'),
            'policy_version' => (string) config('moderation.image.policy_version'),
            'signals' => is_array($signals) ? array_filter($signals) : [],
        ];
    }

    /**
     * The semantic text layer, which lives on the same service but is
     * reached through its own endpoint and can fail independently.
     *
     * @return array<string, mixed>
     */
    private function textLayer(): array
    {
        if (! app(SelfHostedTextClient::class)->isConfigured()) {
            // Not an error. An install can run on the deterministic
            // lexicon alone — it simply catches far less paraphrase.
            return ['status' => 'disabled'];
        }

        $result = app(SelfHostedTextClient::class)
            ->inspect('kutuphanede bulusalim');

        if (! $result->available) {
            return ['status' => 'unavailable', 'reason' => $result->unavailableReason];
        }

        return ['status' => 'ok', 'model' => $result->model];
    }

    /**
     * Held content, and — the number that actually matters — how long the
     * oldest piece has been waiting. A depth of 40 is fine if the oldest
     * is twenty minutes old and a failure if it is three weeks.
     *
     * @return array<string, array{count: int, oldest_hours: ?float}>
     */
    private function heldContent(): array
    {
        $out = [];

        foreach (self::HELD as $label => [$model, $timeColumn]) {
            $query = $model::query();
            // These models hide unapproved rows by default, which is the
            // point of the scope and exactly wrong here: this command
            // exists to count the rows nobody else can see.
            if (method_exists($model, 'scopeIncludingUnmoderated')) {
                $query->includingUnmoderated();
            }

            $rows = (clone $query)->where('moderation_status', 'pending');
            $count = (int) $rows->count();
            if ($count === 0) {
                continue;
            }

            $oldest = (clone $rows)->min($timeColumn);
            $out[$label] = [
                'count' => $count,
                'oldest_hours' => $oldest === null
                    ? null
                    : round(Carbon::parse($oldest)->diffInMinutes(now()) / 60, 1),
            ];
        }

        return $out;
    }

    /**
     * What the pipeline decided in the last day.
     *
     * Read as a ratio, not a total: an allow rate that jumps to 100% is
     * how a silently broken classifier presents, and it is invisible in
     * any single decision.
     *
     * @return array<string, int>
     */
    private function recentDecisions(): array
    {
        $counts = ModerationEvent::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('action, count(*) as n')
            ->groupBy('action')
            ->pluck('n', 'action');

        return [
            'allowed' => (int) ($counts[ModerationEvent::ACTION_ALLOWED] ?? 0),
            'warned' => (int) ($counts[ModerationEvent::ACTION_WARNED] ?? 0),
            'review' => (int) ($counts[ModerationEvent::ACTION_REVIEW] ?? 0),
            'rejected' => (int) ($counts[ModerationEvent::ACTION_REJECTED] ?? 0),
        ];
    }

    /** @return array{unresolved: int, oldest_hours: ?float} */
    private function reports(): array
    {
        // `reported_at`, not `created_at`. The model sets `$timestamps =
        // false` and fills `reported_at` itself, so reading `created_at`
        // returned null for every row — the command cheerfully printed
        // "6 unresolved, oldest -h", which is exactly the number the
        // 24-hour response commitment depends on.
        $unresolved = ModerationReport::query()->whereNull('action');
        $count = (int) (clone $unresolved)->count();
        $oldest = $count === 0 ? null : (clone $unresolved)->min('reported_at');

        return [
            'unresolved' => $count,
            'oldest_hours' => $oldest === null
                ? null
                : round(Carbon::parse($oldest)->diffInMinutes(now()) / 60, 1),
        ];
    }

    /**
     * Appeal outcomes, which are the closest thing to a false-positive
     * rate this system can measure in production.
     *
     * Every overturned appeal is a decision that should not have been
     * made. A rising overturn rate means the thresholds or the lexicon
     * are refusing legitimate content, and nothing else in this command
     * would show it — the pipeline looks healthy while it is wrong.
     *
     * @return array{open: int, oldest_hours: ?float, overturned_30d: int, upheld_30d: int, overturn_rate: ?float}
     */
    private function appeals(): array
    {
        $open = ModerationAppeal::query()->where('status', 'open');
        $openCount = (int) (clone $open)->count();
        $oldest = $openCount === 0 ? null : (clone $open)->min('created_at');

        $recent = ModerationAppeal::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('status', ['overturned', 'upheld'])
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $overturned = (int) ($recent['overturned'] ?? 0);
        $upheld = (int) ($recent['upheld'] ?? 0);
        $decided = $overturned + $upheld;

        return [
            'open' => $openCount,
            'oldest_hours' => $oldest === null
                ? null
                : round(Carbon::parse($oldest)->diffInMinutes(now()) / 60, 1),
            'overturned_30d' => $overturned,
            'upheld_30d' => $upheld,
            'overturn_rate' => $decided === 0 ? null : round($overturned / $decided, 2),
        ];
    }

    /**
     * The versions every current decision is being made under.
     *
     * Recorded on each event too, but surfaced here because "which policy
     * is live right now" is the first question after someone reports a
     * wrong decision, and reading it from a config file on a server is
     * slower than asking the thing that is running.
     *
     * @return array<string, mixed>
     */
    private function policyVersions(): array
    {
        $imageThresholds = (array) config('moderation.image.thresholds', []);
        $textThresholds = (array) config('moderation.text.thresholds', []);

        return [
            'image_policy' => (string) config('moderation.image.policy_version'),
            'image_categories' => array_keys($imageThresholds),
            'text_categories' => array_keys($textThresholds),
            'text_model' => (string) config('moderation.text.base_url') !== ''
                ? 'self_hosted'
                : 'none',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function problemsIn(array $report, int $staleHours): array
    {
        $problems = [];

        $classifier = $report['classifier']['status'];
        if (! in_array($classifier, ['ok', 'disabled'], true)) {
            $problems[] = "Image classifier is {$classifier} — every photo upload is failing closed with a 503.";
        }

        // A running service with a missing signal is the dangerous case:
        // everything looks healthy and uploads are judged on the NSFW
        // score alone, which measurably published a nude at 0.0090.
        if ($classifier === 'ok'
            && ! array_key_exists('clip', $report['classifier']['signals'] ?? [])) {
            $problems[] = 'The CLIP signal is not loaded — images are being judged on the NSFW score alone, '
                .'which is the configuration that published a nude photograph at 0.0090.';
        }

        if ($report['text']['status'] === 'unavailable') {
            $problems[] = 'The semantic text layer is unreachable ('
                .($report['text']['reason'] ?? 'unknown').') — text moderation has silently '
                .'degraded to the phrase list, which publishes paraphrase.';
        }

        if ($report['enforcement'] === 'off') {
            // Not phrased as an outage: this is switched off deliberately
            // during testing. It is reported because the only way it goes
            // wrong is by being forgotten.
            $problems[] = 'Account enforcement is OFF — violations carry no consequence. Intended only during testing.';
        }

        foreach ($report['held'] as $label => $held) {
            if ($held['oldest_hours'] !== null && $held['oldest_hours'] >= $staleHours) {
                $problems[] = sprintf(
                    '%d held %s, oldest waiting %.1fh — nobody is draining the review queue.',
                    $held['count'], $label, $held['oldest_hours'],
                );
            }
        }

        // Appeals have their own, longer target: a person waiting on a
        // second opinion is waiting on a person, not a queue.
        if ($report['appeals']['oldest_hours'] !== null
            && $report['appeals']['oldest_hours'] >= 72) {
            $problems[] = sprintf(
                '%d open appeals, oldest %.1fh — a student is waiting on a second opinion.',
                $report['appeals']['open'], $report['appeals']['oldest_hours'],
            );
        }

        // The production false-positive signal. Every overturn is a
        // decision that should not have been made, and a high rate means
        // the pipeline is refusing legitimate content while looking
        // perfectly healthy in every other line of this report.
        $rate = $report['appeals']['overturn_rate'];
        $decided = $report['appeals']['overturned_30d'] + $report['appeals']['upheld_30d'];
        if ($rate !== null && $decided >= 5 && $rate >= 0.3) {
            $problems[] = sprintf(
                '%.0f%% of appeals overturned in 30 days (%d of %d) — moderation is '
                .'refusing legitimate content. Re-measure before changing a threshold.',
                $rate * 100, $report['appeals']['overturned_30d'], $decided,
            );
        }

        if ($report['reports']['oldest_hours'] !== null
            && $report['reports']['oldest_hours'] >= $staleHours) {
            $problems[] = sprintf(
                '%d unresolved user reports, oldest %.1fh old.',
                $report['reports']['unresolved'], $report['reports']['oldest_hours'],
            );
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $problems
     */
    private function render(array $report, array $problems, int $staleHours): void
    {
        $c = $report['classifier'];
        $this->newLine();
        $this->line('<options=bold>Moderation pipeline</>');
        $this->line('  classifier   '.$c['status']
            .($c['status'] === 'ok' ? '  (policy '.$c['policy_version'].')' : ''));

        // Each visual signal on its own line. "classifier ok" hid the
        // fact that CLIP — the signal that catches the nudity the NSFW
        // model measurably misses — could be absent.
        foreach (($c['signals'] ?? []) as $name => $model) {
            $this->line(sprintf('    %-9s %s', $name, $model));
        }

        $t = $report['text'];
        $this->line('  text         '.$t['status']
            .($t['status'] === 'ok' ? '  ('.($t['model'] ?? '?').')' : '')
            .($t['status'] === 'unavailable' ? '  ('.($t['reason'] ?? '?').')' : ''));
        $this->line('  enforcement  '.$report['enforcement']);

        $this->newLine();
        $this->line('<options=bold>Held for review</>');
        if ($report['held'] === []) {
            $this->line('  nothing waiting');
        } else {
            foreach ($report['held'] as $label => $held) {
                $this->line(sprintf('  %-15s %4d   oldest %sh',
                    $label, $held['count'], $held['oldest_hours'] ?? '?'));
            }
        }

        $d = $report['decisions_24h'];
        $total = array_sum($d);
        $this->newLine();
        $this->line('<options=bold>Decisions, last 24h</> ('.$total.')');
        $this->line(sprintf('  allowed %d   warned %d   review %d   rejected %d',
            $d['allowed'], $d['warned'], $d['review'], $d['rejected']));

        $this->newLine();
        $this->line('<options=bold>User reports</>');
        $this->line('  unresolved '.$report['reports']['unresolved']
            .'   oldest '.($report['reports']['oldest_hours'] ?? '-').'h');

        $a = $report['appeals'];
        $this->newLine();
        $this->line('<options=bold>Appeals</>');
        $this->line('  open '.$a['open'].'   oldest '.($a['oldest_hours'] ?? '-').'h');
        $this->line('  last 30d: '.$a['overturned_30d'].' overturned, '
            .$a['upheld_30d'].' upheld'
            .($a['overturn_rate'] === null
                ? '' : sprintf('   (%.0f%% overturned)', $a['overturn_rate'] * 100)));

        $p = $report['policy'];
        $this->newLine();
        $this->line('<options=bold>Live policy</>');
        $this->line('  image  '.$p['image_policy']
            .'  ['.implode(' ', $p['image_categories']).']');
        $this->line('  text   '.$p['text_model']
            .'  ['.implode(' ', $p['text_categories']).']');

        $this->newLine();
        if ($problems === []) {
            $this->info('No problems (stale threshold '.$staleHours.'h).');

            return;
        }

        foreach ($problems as $problem) {
            $this->warn('! '.$problem);
        }
    }
}
