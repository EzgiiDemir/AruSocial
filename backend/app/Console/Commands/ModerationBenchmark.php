<?php

namespace App\Console\Commands;

use App\Services\Moderation\BenchmarkRunner;
use App\Services\Moderation\SelfHostedTextClient;
use App\Services\Moderation\TextPolicyEngine;
use Illuminate\Console\Command;

/**
 * Prints how the text policy engine scores against a labelled set.
 *
 * The same numbers the test asserts, in a form someone can read while
 * working on the lexicon — per language and per category, with the actual
 * misses and false positives listed rather than summarised into a
 * percentage that says nothing about what to fix.
 */
class ModerationBenchmark extends Command
{
    protected $signature = 'moderation:benchmark
                            {set=eval_v4 : Which set in tests/Fixtures/Moderation}
                            {--semantic : Also run the self-hosted classifier, i.e. the whole pipeline}
                            {--json : Print the raw report}';

    protected $description = 'Measure text moderation recall and precision per language and category';

    public function handle(BenchmarkRunner $runner): int
    {
        if ($this->option('semantic')) {
            if (! (new SelfHostedTextClient)->isConfigured()) {
                $this->error('The semantic classifier is not configured; nothing to add.');

                return self::FAILURE;
            }
            $runner = new BenchmarkRunner(new TextPolicyEngine, new SelfHostedTextClient);
        }

        $name = (string) $this->argument('set');
        $path = base_path("tests/Fixtures/Moderation/{$name}.json");

        if (! is_file($path)) {
            $this->error("No such set: {$path}");

            return self::FAILURE;
        }

        $set = json_decode((string) file_get_contents($path), true);
        if (! is_array($set)) {
            $this->error('That file is not valid JSON.');

            return self::FAILURE;
        }

        $report = $runner->run($set);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line("Set: {$name}");
        $this->line('Layers: rule engine'
            .($this->option('semantic') ? ' + semantic classifier' : ' only'));
        if (isset($set['_note'])) {
            $this->line(wordwrap((string) $set['_note'], 76, "\n  "));
        }
        $this->newLine();
        $this->line($runner->format($report));

        return self::SUCCESS;
    }
}
