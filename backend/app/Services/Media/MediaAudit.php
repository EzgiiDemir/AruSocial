<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Storage;

/**
 * What is in the media library, and what is only pretending to be.
 *
 * Three different problems live under the word "orphan" and they need
 * different answers, so they are counted separately:
 *
 *   - **Untracked files** — bytes on disk with no `media_items` row. Nothing
 *     in the app can show them, nobody can delete them from a screen, and
 *     they grow forever. This development database has roughly fifteen
 *     hundred of them against thirty rows, which is what a year of testing
 *     uploads looks like.
 *   - **Broken rows** — a row whose file is gone. These are worse than
 *     untracked files because the app *does* show them: a post renders a
 *     frame that will never load.
 *   - **Unreferenced rows** — a row with a file, but nothing using it.
 *     Safe to remove, and the only one of the three that is a judgement
 *     call rather than a defect.
 *
 * Read-only. Nothing here deletes anything; `media:audit` reports and
 * `media:purge-orphans` acts, so the finding and the destruction are two
 * separate decisions.
 */
class MediaAudit
{
    /**
     * Directories owned by other features, which have their own records and
     * must not be judged against `media_items`.
     */
    // `quarantine/` is where purged files are moved to, so it must not
    // be scanned back in as untracked — otherwise the audit reports the
    // same problem after it has been dealt with, and the next person
    // purges the quarantine into a second quarantine.
    private const NOT_LIBRARY = ['cvs/', 'orphaned/', 'quarantine/'];

    /**
     * @return array{
     *     rows: int,
     *     files: int,
     *     untracked: list<string>,
     *     broken: list<array{id: string, path: string}>,
     *     unreferenced: list<array{id: string, path: string}>,
     *     bytes_untracked: int,
     * }
     */
    public static function run(): array
    {
        $disk = Storage::disk(MediaItem::disk());

        $files = array_values(array_filter(
            $disk->allFiles(),
            static function (string $path): bool {
                if (str_starts_with(basename($path), '.')) {
                    return false;
                }
                foreach (self::NOT_LIBRARY as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        // Trashed rows included on purpose: a soft-deleted item still owns
        // its file, and counting it as untracked would invite a purge that
        // destroys the thing restore is supposed to bring back.
        $rows = MediaItem::withTrashed()->get(['id', 'file_path', 'used_in']);
        $tracked = $rows->pluck('file_path')->filter()->all();

        $untracked = array_values(array_diff($files, $tracked));

        $broken = [];
        $unreferenced = [];

        foreach ($rows as $row) {
            if (! $disk->exists((string) $row->file_path)) {
                $broken[] = ['id' => $row->id, 'path' => (string) $row->file_path];

                continue;
            }

            if (blank($row->used_in)) {
                $unreferenced[] = ['id' => $row->id, 'path' => (string) $row->file_path];
            }
        }

        $bytes = 0;
        foreach ($untracked as $path) {
            $bytes += $disk->size($path);
        }

        return [
            'rows' => $rows->count(),
            'files' => count($files),
            'untracked' => $untracked,
            'broken' => $broken,
            'unreferenced' => $unreferenced,
            'bytes_untracked' => $bytes,
        ];
    }

    public static function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
