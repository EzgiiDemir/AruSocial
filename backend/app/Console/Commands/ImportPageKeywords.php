<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Models\PageKeyword;
use Illuminate\Console\Command;

/**
 * Load authored per-page keywords from the JSON or CSV produced alongside the
 * URL inventory.
 *
 * Accepts the JSON shape
 *   [{ "url", "title", "language", "category", "keywords": [...], "summary" }]
 * and the CSV with the Turkish headers the same tool writes
 *   URL, URL (okunur), Başlık, Dil, Kategori, Anahtar Kelimeler, Özet
 *
 * Both are supported because the pair is generated together and whichever is
 * to hand should work; the JSON is preferred since its keyword list is
 * already an array rather than a string that has to be re-split.
 */
class ImportPageKeywords extends Command
{
    protected $signature = 'knowledge:keywords:import
        {file : A .json or .csv of per-page keywords}
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Import curated per-page keywords used to rank ARUCAD pages';

    /** CSV header => model field. */
    private const CSV_MAP = [
        'url' => 'url',
        'başlık' => 'title',
        'baslik' => 'title',
        'title' => 'title',
        'dil' => 'language',
        'language' => 'language',
        'kategori' => 'category',
        'category' => 'category',
        'anahtar kelimeler' => 'keywords',
        'keywords' => 'keywords',
        'özet' => 'summary',
        'ozet' => 'summary',
        'summary' => 'summary',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $rows = str_ends_with(strtolower($path), '.csv')
            ? $this->fromCsv($path)
            : $this->fromJson($path);

        if ($rows === []) {
            $this->error('No usable rows. Expected a url column/field with keywords beside it.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info(count($rows).' page(s) found. Nothing written (--dry-run).');
            $this->table(
                ['URL', 'Keywords'],
                collect($rows)->take(5)->map(fn (array $r) => [
                    mb_substr($r['url'], 0, 60),
                    mb_substr((string) $r['keywords'], 0, 60),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $written = 0;
        foreach (array_chunk($rows, 200) as $chunk) {
            foreach ($chunk as $row) {
                PageKeyword::updateOrCreate(['url' => $row['url']], $row);
                $written++;
            }
        }

        PageKeyword::forget();

        $this->info("Stored keywords for {$written} page(s).");
        // Says plainly how much of it can bite today, because the gap is
        // large on a first run and looks like a failure otherwise.
        $indexed = KnowledgeDocument::whereIn('url', array_column($rows, 'url'))->count();
        $this->line("{$indexed} of them are currently indexed; the rest take effect once those pages are crawled.");

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function fromJson(string $path): array
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! is_string($entry['url'] ?? null)) {
                continue;
            }

            $keywords = $entry['keywords'] ?? '';
            $out[] = $this->row(
                $entry['url'],
                (string) ($entry['title'] ?? ''),
                (string) ($entry['language'] ?? ''),
                (string) ($entry['category'] ?? ''),
                is_array($keywords) ? implode(', ', $keywords) : (string) $keywords,
                (string) ($entry['summary'] ?? ''),
            );
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private function fromCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($handle);

            return [];
        }

        // The exporter writes a UTF-8 BOM, which otherwise becomes part of
        // the first header's name and loses the url column.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]) ?? $header[0];

        $at = [];
        foreach ($header as $i => $name) {
            $key = mb_strtolower(trim((string) $name));
            if (isset(self::CSV_MAP[$key])) {
                $at[self::CSV_MAP[$key]] = $i;
            }
        }

        if (! isset($at['url'])) {
            fclose($handle);

            return [];
        }

        $cell = static fn (array $r, ?int $i): string => $i === null ? '' : trim((string) ($r[$i] ?? ''));

        $out = [];
        while (($record = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $url = $cell($record, $at['url']);
            if ($url === '') {
                continue;
            }

            $out[] = $this->row(
                $url,
                $cell($record, $at['title'] ?? null),
                $cell($record, $at['language'] ?? null),
                $cell($record, $at['category'] ?? null),
                $cell($record, $at['keywords'] ?? null),
                $cell($record, $at['summary'] ?? null),
            );
        }

        fclose($handle);

        return $out;
    }

    /** @return array<string, string> */
    private function row(
        string $url,
        string $title,
        string $language,
        string $category,
        string $keywords,
        string $summary,
    ): array {
        return [
            'url' => trim($url),
            'title' => mb_substr($title, 0, 500),
            // The export writes "TR"/"EN"; the rest of the system uses "tr".
            'language' => $language === '' ? null : mb_strtolower(mb_substr($language, 0, 8)),
            'category' => $category === '' ? null : mb_substr($category, 0, 190),
            'keywords' => $keywords,
            'summary' => $summary === '' ? null : $summary,
        ];
    }
}
