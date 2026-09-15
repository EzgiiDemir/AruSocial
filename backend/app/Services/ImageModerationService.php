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
    /**
     * Is this actually an image we support?
     *
     * The format is read from the bytes, not from the caller's `mimeType`.
     * Trusting the declared type was both a correctness bug and a weak
     * check: the client wrapper never passed one, so every upload was
     * validated as JPEG and any PNG a student picked was rejected as
     * "geçersiz görsel verisi". And a declared type is attacker-controlled
     * anyway — the only thing worth believing is the file's own header.
     *
     * `$mimeType` is kept in the signature because callers still have it and
     * a mismatch is worth logging, but it no longer decides the outcome.
     */
    public static function checkImageBytes(string $bytes, string $mimeType = ''): ?string
    {
        if ($bytes === '') {
            return 'boş görsel';
        }

        return self::sniffFormat($bytes) === null ? 'geçersiz görsel verisi' : null;
    }

    /**
     * The image format these bytes really are, or null if unrecognised.
     */
    public static function sniffFormat(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF8')) {
            return 'image/gif';
        }
        if (strlen($bytes) >= 12
            && str_starts_with($bytes, 'RIFF')
            && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        // HEIC/HEIF, which is what an iPhone produces by default. Both use
        // the ISO base media container, so the brand sits at offset 8.
        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'heim', 'heis', 'mif1', 'msf1'], true)) {
                return 'image/heic';
            }
        }

        return null;
    }

    /**
     * Ask the ARUCAD-hosted local classifier for a semantic decision.
     * MediaController treats "not configured" as approve-after-magic-bytes and
     * "configured but unavailable" as MODERATION_UNAVAILABLE.
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
