<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Local content-sniff so a renamed .exe/.php cannot ride in as image/pdf.
 * Complements the local ARUCAD moderation queue.
 */
class UploadMagic
{
    public static function isImage(UploadedFile $file): bool
    {
        $bytes = self::head($file);
        $mime = (string) $file->getMimeType();

        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => str_starts_with($bytes, "\xFF\xD8\xFF"),
            str_contains($mime, 'png') => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            str_contains($mime, 'gif') => str_starts_with($bytes, 'GIF8'),
            str_contains($mime, 'webp') => strlen($bytes) >= 12
                && str_starts_with($bytes, 'RIFF')
                && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
    }

    /** Validate the container signature before accepting a video upload. */
    public static function isVideo(UploadedFile $file): bool
    {
        $bytes = self::head($file, 16);
        $mime = (string) $file->getMimeType();

        return match ($mime) {
            'video/mp4', 'video/quicktime' => strlen($bytes) >= 8
                && substr($bytes, 4, 4) === 'ftyp',
            'video/webm' => str_starts_with($bytes, "\x1A\x45\xDF\xA3"),
            default => false,
        };
    }

    public static function isDocument(UploadedFile $file): bool
    {
        $bytes = self::head($file, 8);
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $mime = (string) $file->getMimeType();

        if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
            return str_starts_with($bytes, '%PDF');
        }
        if ($ext === 'docx' || str_contains($mime, 'wordprocessingml')) {
            return str_starts_with($bytes, 'PK');
        }
        if ($ext === 'doc' || $mime === 'application/msword') {
            return str_starts_with($bytes, "\xD0\xCF\x11\xE0");
        }

        return false;
    }

    private static function head(UploadedFile $file, int $length = 16): string
    {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        $chunk = fread($handle, $length) ?: '';
        fclose($handle);

        return $chunk;
    }
}
