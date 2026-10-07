<?php

namespace App\Console\Commands;

use App\Models\CrawlSource;
use App\Support\CrawlSourceImport;
use Illuminate\Console\Command;

/**
 * The same bulk import as the admin panel, from the command line.
 *
 * The panel is the right place for it, but a browser upload has parts that
 * can fail where nobody can see them — the file picker filtering the file out
 * by MIME type, an upload that never completes. This path has none of those,
 * so it is also what to reach for when the panel appears to do nothing: if
 * this works, the parsing is fine and the problem is the upload.
 */
class ImportCrawlSources extends Command
{
    protected $signature = 'knowledge:sources:import
        {file : A CSV, or a plain list of domains/URLs, one per line}
        {--dry-run : Show what would change and write nothing}';

    protected $description = 'Bulk-add AICAD crawl sources from a CSV or a URL list';

    public function handle(CrawlSourceImport $import): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $text = (string) file_get_contents($path);
        $parsed = $import->parse($text);

        if ($parsed === []) {
            $this->warn('No domain found. Lines need a domain or a full address;');
            $this->warn('headings and e-mail addresses are skipped.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Domain', 'Label', 'Keys', 'Exists'],
                collect($parsed)->map(fn (array $r) => [
                    $r['domain'],
                    $r['label'] ?? '—',
                    $r['keys'] ?? '—',
                    CrawlSource::whereKey($r['domain'])->exists() ? 'yes' : 'no',
                ])->values()->all(),
            );
            $this->info(count($parsed).' domain(s) found. Nothing written (--dry-run).');

            return self::SUCCESS;
        }

        $result = $import->apply($text);

        $this->info(sprintf(
            '%d added, %d updated, %d unchanged.',
            $result['created'], $result['updated'], $result['unchanged'],
        ));
        $this->line('Run `php artisan knowledge:crawl` to index the new sites.');

        return self::SUCCESS;
    }
}
