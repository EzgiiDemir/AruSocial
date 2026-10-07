<?php

namespace App\Support;

/**
 * Make text safe to encode as JSON.
 *
 * Crawled pages are not reliably valid UTF-8: a page served as UTF-8 that
 * actually contains a stray Windows-1254 byte produces a string PHP will
 * hold happily and `json_encode` will refuse. That refusal was measured
 * taking down a whole indexing run — one bad page failed its batch, and
 * everything after it was skipped.
 *
 * So anything derived from fetched HTML passes through here before it is
 * stored or sent anywhere. Invalid sequences are dropped rather than
 * substituted: a replacement character in the middle of a word is noise
 * the embedding model would have to account for, and nothing downstream
 * benefits from knowing a byte was broken.
 */
final class Utf8
{
    public static function clean(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // //IGNORE drops the invalid bytes. It can emit a notice on some
        // builds, hence the @: the return value is checked instead.
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
            return $converted;
        }

        // mbstring's substitution path, as a fallback for builds where
        // iconv is unhelpful.
        $previous = mb_substitute_character();
        mb_substitute_character('none');
        try {
            return (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }
}
