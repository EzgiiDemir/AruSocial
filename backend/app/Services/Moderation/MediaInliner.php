<?php

namespace App\Services\Moderation;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes locally stored uploads readable by the moderation provider.
 *
 * Student uploads sit on this server's media disk, which in development is
 * localhost and in production is deliberately private — either way the
 * provider cannot fetch them by URL. Passing an unreachable URL would mean
 * the image is quietly never inspected, which is the exact failure this
 * system exists to prevent, so local files are read and inlined as data URIs.
 *
 * That failure had in fact happened. This class read from `disk('public')`
 * while uploads moved to the private media disk, so `exists()` was false for
 * every upload and `toDataUri` returned null — and the caller drops nulls
 * silently, so every image posted to the app went to the provider as an
 * empty list. Nothing logged, nothing failed, no image was ever checked.
 * Hence both the disk being read from `MediaItem::disk()` rather than named
 * here, and the warning on the miss: the next time this breaks it will say so.
 */
class MediaInliner
{
    /** Formats the moderation endpoint accepts as images. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Refuse to inline anything large enough to blow the request budget. */
    private const MAX_BYTES = 8_000_000;

    public static function toDataUri(string $url): ?string
    {
        // Already inline, or already reachable by the provider.
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        $path = self::localPathFor($url);
        if ($path === null) {
            return self::isPubliclyFetchable($url) ? $url : null;
        }

        try {
            $disk = Storage::disk(MediaItem::disk());

            if (! $disk->exists($path)) {
                // An upload we resolved to a key but cannot read is the
                // silent-bypass case, not an ordinary miss.
                Log::warning('moderation.inline_missing', ['url' => $url, 'path' => $path]);

                return null;
            }
            if ($disk->size($path) > self::MAX_BYTES) {
                Log::warning('moderation.inline_too_large', ['url' => $url, 'bytes' => $disk->size($path)]);

                return null;
            }
            $mime = $disk->mimeType($path) ?: '';
            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                // Videos and documents are not sent to the image endpoint;
                // VideoModerator handles those by extracting frames first.
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
        } catch (\Throwable $e) {
            Log::warning('moderation.inline_failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Storage key on the media disk, or null when the URL is not ours. */
    public static function localPathFor(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim((string) $path, '/');

        // `/api/v1/media/{id}/file` addresses a database row, not a file.
        // Trimming the prefix yields the row's id, which is not a storage
        // key and never existed on any disk — the stored key lives on the
        // row, so it has to be looked up. Soft-deleted rows are included:
        // media withheld pending review is exactly what needs inspecting.
        if (preg_match('#^api/v1/media/([^/]+)/file$#', $path, $m)) {
            return MediaItem::withTrashed()->whereKey($m[1])->value('file_path');
        }

        if (str_starts_with($path, 'storage/')) {
            return substr($path, strlen('storage/'));
        }

        // Anything else carrying a host belongs to somebody else, and its
        // path is not a key on our disk. Returning one here sent every
        // remote image down the local branch, where it missed and was
        // dropped — so a hosted image was never inspected either, and the
        // pass-through below could not be reached at all.
        if (is_string(parse_url($url, PHP_URL_HOST))) {
            return null;
        }

        // A bare relative key such as "media/abc.jpg".
        return str_contains($path, '://') ? null : $path;
    }

    private static function isPubliclyFetchable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && ! str_starts_with($host, '192.168.')
            && ! str_starts_with($host, '10.');
    }
}
