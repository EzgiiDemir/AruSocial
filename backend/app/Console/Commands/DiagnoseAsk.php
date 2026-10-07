<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\AskDiagnostics;
use Illuminate\Console\Command;

/**
 * The Search Playground on the command line.
 *
 *   php artisan ask:diagnose "spor salonu nerede"
 *   php artisan ask:diagnose "oraya nasıl giderim" --previous="Kütüphane nerede?"
 *   php artisan ask:diagnose "burs var mı" --full --as=admin@arucad.edu.tr
 *   php artisan ask:diagnose "gym nerede" --json
 *
 * Retrieval mode (the default) never calls the model or web search.
 */
class DiagnoseAsk extends Command
{
    protected $signature = 'ask:diagnose
        {question : The question, as a student would type it}
        {--previous=* : Earlier user turns, oldest first, for follow-up questions}
        {--full : Run the real answer path, including the model}
        {--as= : E-mail of the user to run --full as}
        {--no-web : Do not allow web research in --full mode}
        {--json : Print the whole trace as JSON}';

    protected $description = 'Show how AICAD routes, retrieves and (with --full) answers one question';

    public function handle(AskDiagnostics $diagnostics): int
    {
        $user = null;
        if ($this->option('full')) {
            $email = (string) $this->option('as');
            $user = $email === '' ? null : User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->error('--full needs --as=<email> of an existing user (the answer path is authenticated).');

                return self::FAILURE;
            }
        }

        $history = array_map(
            fn ($turn) => ['role' => 'user', 'content' => (string) $turn],
            (array) $this->option('previous'),
        );

        $report = $diagnostics->run(
            (string) $this->argument('question'),
            $this->option('full') ? AskDiagnostics::MODE_FULL : AskDiagnostics::MODE_RETRIEVAL,
            $history,
            ! $this->option('no-web'),
            $user,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Trace {$report['trace_id']} ({$report['mode']})");
        foreach ($report['stages'] as $stage) {
            $this->newLine();
            $this->line("<comment>[{$stage['ms']} ms] {$stage['stage']}</comment>");
            $this->printStage($stage['stage'], $stage['data']);
        }

        $this->newLine();
        $this->line('<comment>result</comment>');
        $this->printStage('result', $report['result']);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function printStage(string $stage, array $data): void
    {
        if ($stage === 'knowledge.candidates') {
            $rows = array_map(fn (array $c) => [
                $c['rank'].($c['selected'] ? '*' : ''),
                $c['score'],
                $this->parts($c['parts']),
                mb_strimwidth((string) $c['title'], 0, 50, '…'),
                (string) $c['url'],
            ], $data['candidates'] ?? []);
            $this->table(['#', 'score', 'components', 'title', 'url'], $rows);

            return;
        }

        if (str_starts_with($stage, 'tool.')) {
            foreach (array_slice($data['rows'] ?? [], 0, 15) as $row) {
                $this->line('  '.mb_strimwidth((string) $row, 0, 160, '…'));
            }

            return;
        }

        foreach ($data as $key => $value) {
            $text = is_scalar($value) || $value === null
                ? var_export($value, true)
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->line("  {$key}: ".mb_strimwidth((string) $text, 0, 400, '…'));
        }
    }

    /** @param array<string, float> $parts */
    private function parts(array $parts): string
    {
        return implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($parts), $parts));
    }
}
