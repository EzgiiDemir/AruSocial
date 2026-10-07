<?php

namespace App\Console\Commands;

use App\Services\Knowledge\SiteKnowledgeCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrawlSiteKnowledge extends Command
{
    protected $signature = 'knowledge:crawl
        {--force : Re-fetch every page, ignoring the freshness window}
        {--max= : Override the configured page cap for this bounded run}
        {--delay= : Override politeness delay in milliseconds (minimum 100)}
        {--relabel : Only re-derive the language of stored documents; no fetching}';

    protected $description = 'Crawl the public ARUCAD websites into the local knowledge base for Ask ARUVERSE.';

    public function handle(SiteKnowledgeCrawler $crawler): int
    {
        /*
        |--------------------------------------------------------------------------
        | Crawler enabled?
        |--------------------------------------------------------------------------
        */

        if (! config('knowledge.enabled')) {
            $this->warn(
                'Knowledge crawler is disabled (KNOWLEDGE_CRAWLER_ENABLED=false).'
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Relabel only
        |--------------------------------------------------------------------------
        */

        if ($this->option('relabel')) {
            $this->info('Re-deriving document languages...');

            try {
                $changes = $crawler->relabelLanguages();
            } catch (Throwable $e) {
                $this->error('Language relabel failed.');
                $this->error($e->getMessage());

                Log::error('Knowledge language relabel failed', [
                    'exception' => $e,
                ]);

                return self::FAILURE;
            }

            if ($changes === []) {
                $this->info('Languages already correct; nothing changed.');

                return self::SUCCESS;
            }

            $this->table(
                ['Change', 'Documents'],
                collect($changes)
                    ->map(fn ($n, $k) => [$k, $n])
                    ->values()
                    ->all(),
            );

            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | Runtime configuration
        |--------------------------------------------------------------------------
        */

        if ($this->option('max') !== null) {
            $maxPages = max(1, (int) $this->option('max'));

            config([
                'knowledge.max_pages' => $maxPages,
            ]);
        }

        if ($this->option('delay') !== null) {
            $delay = max(100, (int) $this->option('delay'));

            config([
                'knowledge.delay_ms' => $delay,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Display configuration
        |--------------------------------------------------------------------------
        */

        $force = (bool) $this->option('force');

        $this->newLine();
        $this->info('ARUCAD knowledge crawl starting...');
        $this->line(
            'Max pages : '.config('knowledge.max_pages', 'default')
        );
        $this->line(
            'Delay     : '.config('knowledge.delay_ms', 'default').' ms'
        );
        $this->line(
            'Force     : '.($force ? 'yes' : 'no')
        );
        $this->newLine();

        /*
        |--------------------------------------------------------------------------
        | Run crawler
        |--------------------------------------------------------------------------
        */

        try {
            $summary = $crawler->crawl(
                force: $force
            );
        } catch (Throwable $e) {
            $this->newLine();

            $this->error('Knowledge crawl crashed.');
            $this->error($e->getMessage());

            if ($this->output->isVerbose()) {
                $this->newLine();
                $this->line($e->getTraceAsString());
            }

            Log::error('Knowledge crawl crashed', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        */

        $this->newLine();
        $this->info('Knowledge crawl completed.');

        $this->table(
            [
                'Fetched',
                'Updated',
                'Skipped',
                'Failed',
                'Documents',
            ],
            [[
                $summary['fetched'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['skipped'] ?? 0,
                $summary['failed'] ?? 0,
                $summary['documents'] ?? 0,
            ]],
        );

        return self::SUCCESS;
    }
}
