<?php

namespace App\Support;

/**
 * Packing and comparing embedding vectors.
 *
 * Stored as base64-encoded little-endian float32 rather than JSON: 384
 * dimensions are 1.5 KB packed against roughly 4 KB as text, and the
 * corpus is read in full on every semantic query. Base64 rather than raw
 * bytes so the column is an ordinary string on both SQLite and Postgres
 * and survives any driver that would mangle a binary blob.
 *
 * Similarity is a plain dot product because the classifier returns
 * L2-normalised vectors — for unit vectors the dot product IS the cosine.
 * Normalising again here would be wasted work, and doing it wrong would
 * be an invisible ranking bug.
 */
final class Vector
{
    /** @param list<float> $vector */
    public static function pack(array $vector): string
    {
        return base64_encode(pack('g*', ...array_map(static fn ($v): float => (float) $v, $vector)));
    }

    /** @return list<float>|null Null when the value is absent or corrupt. */
    public static function unpack(?string $packed): ?array
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        $raw = base64_decode($packed, true);
        if ($raw === false || $raw === '' || strlen($raw) % 4 !== 0) {
            return null;
        }

        $values = unpack('g*', $raw);

        return $values === false ? null : array_values($values);
    }

    /**
     * Cosine similarity of two unit vectors.
     *
     * Returns 0.0 for mismatched dimensions rather than throwing: a
     * vector written by a previous model is not an error condition, it is
     * simply not comparable, and ranking it last is the correct outcome.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $count = count($a);
        if ($count === 0 || $count !== count($b)) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
