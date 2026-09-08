<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes locally stored uploads readable by the moderation provider.
 *
 * Student uploads sit on this server's public disk, which in development is
 * localhost and in production may sit behind a private network — either way
 * OpenAI cannot fetch them by URL. Passing an unreachable URL would mean the
 * image is quietly never inspected, which is the exact failure this system
 * exists to prevent, so local files are read and inlined as data URIs.
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
            if (! Storage::disk('public')->exists($path)) {
                return null;
            }
            if (Storage::disk('public')->size($path) > self::MAX_BYTES) {
                return null;
            }
            $mime = Storage::disk('public')->mimeType($path) ?: '';
            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                // Videos and documents are not sent to the image endpoint;
                // VideoModerator handles those by extracting frames first.
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
        } catch (\Throwable $e) {
            Log::warning('moderation.inline_failed', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Absolute path on the public disk, or null when it is not ours. */
    public static function localPathFor(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim((string) $path, '/');

        foreach (['storage/', 'api/v1/media/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
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
