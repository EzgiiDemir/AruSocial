<?php

namespace App\Support;

use App\Models\CrawlSource;

/**
 * Turns a pasted list or an uploaded CSV into crawl sources.
 *
 * `crawl_sources` is keyed by HOST, not by page: the crawler is given a domain
 * and follows links from there. The lists people actually have are lists of
 * pages — four hundred arucad.edu.tr URLs — so pasting one in must collapse to
 * the handful of hosts it covers rather than producing four hundred rows that
 * all say the same thing.
 *
 * Accepts, in one parser because an operator should not have to know which
 * shape they have:
 *   - a bare list, one domain or URL per line
 *   - a CSV with a `domain` (or `url`) column, plus optional `label`, `keys`,
 *     `access` and `enabled`
 */
class CrawlSourceImport
{
    /** Columns understood in a CSV header, in the order they are looked for. */
    private const HOST_COLUMNS = ['domain', 'url', 'host', 'site', 'adres', 'alan'];

    /**
     * Parse text into one row per host, later rows filling gaps in earlier
     * ones rather than replacing them: a list usually names a host many
     * times and only some of those lines carry a label.
     *
     * @return array<string, array{domain: string, label: ?string, keys: ?string, access: ?string, enabled: ?bool}>
     */
    public function parse(string $text): array
    {
        $rows = $this->rows($text);
        if ($rows === []) {
            return [];
        }

        $header = $this->header($rows[0]);
        if ($header !== null) {
            array_shift($rows);
        }

        $out = [];
        foreach ($rows as $row) {
            $parsed = $header === null
                ? ['domain' => $row[0] ?? '']
                : $this->byHeader($header, $row);

            $host = $this->host((string) ($parsed['domain'] ?? ''));
            if ($host === null) {
                continue;
            }

            $existing = $out[$host] ?? ['domain' => $host, 'label' => null, 'keys' => null, 'access' => null, 'enabled' => null];
            foreach (['label', 'keys', 'access', 'enabled'] as $field) {
                if (($existing[$field] ?? null) === null && ($parsed[$field] ?? null) !== null) {
                    $existing[$field] = $parsed[$field];
                }
            }

            $out[$host] = $existing;
        }

        return $out;
    }

    /**
     * Write the parsed rows.
     *
     * A blank cell means "leave this alone", not "clear it". Someone
     * re-importing a list of domains to add two new ones must not thereby
     * erase the routing keys an operator wrote by hand on the others.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function apply(string $text): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($this->parse($text) as $host => $row) {
            $source = CrawlSource::find($host);

            if ($source === null) {
                CrawlSource::create([
                    'domain' => $host,
                    'label' => $row['label'],
                    'keys' => $row['keys'],
                    'access' => $row['access'] ?? CrawlSource::ACCESS_GLOBAL,
                    'enabled' => $row['enabled'] ?? true,
                ]);
                $created++;

                continue;
            }

            $changes = [];
            foreach (['label', 'keys', 'access', 'enabled'] as $field) {
                if ($row[$field] !== null && $row[$field] !== $source->{$field}) {
                    $changes[$field] = $row[$field];
                }
            }

            if ($changes === []) {
                $unchanged++;

                continue;
            }

            $source->update($changes);
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
    }

    /**
     * The host a line names, or null when the line names none.
     *
     * "https://arucad.edu.tr/en/library/", "arucad.edu.tr" and
     * "www.arucad.edu.tr/" are the same source, and a line that is a comment,
     * a heading or an e-mail address is not a source at all.
     *
     * @return non-empty-string|null
     */
    public function host(string $line): ?string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_contains($line, '@')) {
            return null;
        }

        // parse_url only finds a host when there is a scheme.
        $host = str_contains($line, '://')
            ? (string) parse_url($line, PHP_URL_HOST)
            : (string) strtok($line, '/');

        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        // Strip a port, and anything a stray quote or bullet left behind.
        $host = (string) preg_replace('/[^a-z0-9.\-].*$/', '', $host);

        // A host has at least one dot and a plausible TLD. This is what
        // rejects a heading line like "Programs" or "Bölümler".
        return preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)*\.[a-z]{2,}$/', $host) === 1
            ? $host
            : null;
    }

    /**
     * Split into cells. Commas separate CSV columns, but a pasted URL list is
     * one value per line and may legitimately contain none.
     *
     * @return list<list<string>>
     */
    private function rows(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $cells = str_getcsv($line, ',', '"', '\\');
            $rows[] = array_map(static fn ($c) => trim((string) $c), $cells);
        }

        return $rows;
    }

    /**
     * The column positions, or null when the first line is already data.
     *
     * @param  list<string>  $first
     * @return array<string, int>|null
     */
    private function header(array $first): ?array
    {
        $names = array_map(static fn (string $c) => strtolower(trim($c)), $first);

        // A header names a host column and is not itself a host: a file that
        // starts straight in with arucad.edu.tr has no header.
        $hostAt = null;
        foreach (self::HOST_COLUMNS as $candidate) {
            $at = array_search($candidate, $names, true);
            if ($at !== false) {
                $hostAt = (int) $at;
                break;
            }
        }

        if ($hostAt === null || $this->host($first[$hostAt] ?? '') !== null) {
            return null;
        }

        $map = ['domain' => $hostAt];
        foreach (['label', 'keys', 'access', 'enabled'] as $field) {
            $at = array_search($field, $names, true);
            if ($at !== false) {
                $map[$field] = (int) $at;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $header
     * @param  list<string>  $row
     * @return array<string, string|bool|null>
     */
    private function byHeader(array $header, array $row): array
    {
        $value = static function (?int $at) use ($row): ?string {
            if ($at === null) {
                return null;
            }
            $cell = trim((string) ($row[$at] ?? ''));

            return $cell === '' ? null : $cell;
        };

        $access = $value($header['access'] ?? null);
        $enabled = $value($header['enabled'] ?? null);

        return [
            'domain' => $value($header['domain'] ?? null) ?? '',
            'label' => $value($header['label'] ?? null),
            'keys' => $value($header['keys'] ?? null),
            'access' => $access === null
                ? null
                : (str_starts_with(strtolower($access), 'l') ? CrawlSource::ACCESS_LOCAL : CrawlSource::ACCESS_GLOBAL),
            'enabled' => $enabled === null
                ? null
                : filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
