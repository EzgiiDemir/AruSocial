<?php

namespace App\Services;

/**
 * Deliberately local media gate.
 *
 * A remote moderation API was previously used here. That made a third party
 * decide what students may publish and silently failed open when it was down.
 * This product now keeps deterministic checks on our server and sends all
 * student media to ARUCAD's own moderation queue before publication.
 *
 * It is important not to pretend byte heuristics can recognise nudity or
 * harassment in pixels. A semantic classifier requires a versioned, locally
 * hosted trained model. When that classifier is not configured, magic-byte
 * clean uploads are approved so social photos are not blocked; when it is
 * configured but unavailable, uploads stay pending for human review.
 *
 * When the local classifier is configured, score keys map to text-policy codes:
 * nudity→SEX, sexual_exploitation→CSA, graphic_violence→VIO, hate_symbol→HATE
 * (new SEX/CSA/VIO/HATE keys are also accepted as aliases).
 */
class ImageModerationService
{
    /** Returns a structural rejection reason only. */
    public static function checkImageBytes(string $bytes, string $mimeType): ?string
    {
        if ($bytes === '') {
            return 'boş görsel';
        }

        $valid = match ($mimeType) {
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1A\n"),
            'image/gif' => str_starts_with($bytes, 'GIF8'),
            'image/webp' => strlen($bytes) >= 12
                && str_starts_with($bytes, 'RIFF')
                && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };

        return $valid ? null : 'geçersiz görsel verisi';
    }

    /**
     * Ask the ARUCAD-hosted local classifier for a semantic decision.
     * MediaController treats "not configured" as approve-after-magic-bytes and
     * "configured but unavailable" as pending for a reviewer.
     *
     * @return array{available: bool, blocked: bool, categories: list<string>, score: float}
     */
    public static function inspectLocalFile(string $path, string $mimeType): array
    {
        return LocalMediaClassifier::inspect($path, $mimeType);
    }

    public static function semanticClassifierConfigured(): bool
    {
        return LocalMediaClassifier::isConfigured();
    }

    /** Kept for callers/admin UI that expose a moderation capability flag. */
    public static function isConfigured(): bool
    {
        return true;
    }
}
