<?php

namespace App\Support;

/**
 * Cleans the author's framing choices before they are stored.
 *
 * Framing is the one piece of a post the client composes freely, and it is
 * read back by every other client to position an image. A NaN scale, an
 * infinite offset or a fit nobody has heard of does not fail loudly on the
 * way in — it produces an unrenderable layout on somebody else's phone,
 * long after the post was written, with no way to correct it.
 *
 * So the server decides what framing can say. Anything unrecognised is
 * dropped rather than corrected to a guess, every number is clamped to the
 * range the renderer can actually draw, and the result is either a clean
 * map or null. Null means "frame it the default way", which is exactly
 * what a post written before framing existed already does.
 *
 * Deliberately mirrors `frontend/lib/core/models/story_framing.dart`. The
 * two have to agree, and the ranges are asserted on both sides.
 */
class MediaFraming
{
    public const MIN_SCALE = 1.0;

    public const MAX_SCALE = 4.0;

    /** The frames a post can be shown in. */
    public const ASPECTS = ['original', 'square', 'portrait'];

    private const FITS = ['fill', 'fit'];

    /**
     * @return ?array{fit?: string, scale?: float, offsetX?: float, offsetY?: float, bg?: int, aspect?: string}
     */
    public static function sanitize(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($value)) {
            return null;
        }

        // Accept it nested under `framing` (how stories store it) or flat.
        $raw = is_array($value['framing'] ?? null) ? $value['framing'] : $value;

        $out = [];

        if (in_array($raw['fit'] ?? null, self::FITS, true)) {
            $out['fit'] = $raw['fit'];
        }
        if (in_array($raw['aspect'] ?? null, self::ASPECTS, true)) {
            $out['aspect'] = $raw['aspect'];
        }

        $scale = self::number($raw['scale'] ?? null, self::MIN_SCALE, self::MAX_SCALE);
        if ($scale !== null) {
            $out['scale'] = $scale;
        }

        // Offsets are fractions of the frame, so they are device
        // independent — a post composed on a tablet frames the same way on
        // a phone.
        foreach (['offsetX', 'offsetY'] as $key) {
            $offset = self::number($raw[$key] ?? null, -1.0, 1.0);
            if ($offset !== null) {
                $out[$key] = $offset;
            }
        }

        if (is_int($raw['bg'] ?? null)) {
            // Mask to ARGB rather than reject: a colour outside the range is
            // a client packing bits wrong, and the low 32 are still a colour.
            $out['bg'] = $raw['bg'] & 0xFFFFFFFF;
        }

        return $out === [] ? null : $out;
    }

    /**
     * A finite number inside [$min, $max], or null.
     *
     * `is_finite` is the whole point: NAN and INF are floats, they survive
     * json_decode, and they pass every naive range check because every
     * comparison against NAN is false.
     */
    private static function number(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $n = (float) $value;
        if (! is_finite($n)) {
            return null;
        }

        return round(max($min, min($max, $n)), 6);
    }
}
