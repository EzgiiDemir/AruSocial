<?php

namespace App\Support;

use App\Models\FeedPostMedia;
use App\Models\User;

/**
 * Turns whatever a client sent for a post's pictures into rows we trust.
 *
 * Accepts both shapes on purpose. A client that knows about carousels
 * sends `media: [...]`; every build already installed sends a single
 * `imageUrl`, and must keep working unchanged. Both come out of here as
 * the same ordered list, so nothing downstream has to care which was used.
 *
 * Every item goes through {@see SocialMediaAttachment}, so a carousel
 * cannot be used to smuggle in an unmoderated or someone else's file —
 * ten items means ten ownership and moderation checks, not one.
 *
 * Nothing here writes. It resolves and validates only, so that the caller
 * can refuse the whole post before a single row exists: a carousel that
 * fails on item seven must not leave six pictures published.
 */
class PostMediaPayload
{
    /**
     * @param  list<array{url: string, mime: ?string, style: ?array, alt: ?string, width: ?int, height: ?int, aspect: ?float}>  $items
     */
    private function __construct(
        public readonly array $items,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    public function failed(): bool
    {
        return $this->code !== null;
    }

    /** The URL that goes in `feed_posts.image_url`, keeping old clients working. */
    public function primaryUrl(): ?string
    {
        return $this->items[0]['url'] ?? null;
    }

    public function primaryMime(): ?string
    {
        return $this->items[0]['mime'] ?? null;
    }

    /** Every image, for the moderation gate to judge in one pass. */
    public function urls(): array
    {
        return array_column($this->items, 'url');
    }

    /** True when the post carries more than one picture. */
    public function isCarousel(): bool
    {
        return count($this->items) > 1;
    }

    public static function resolve(mixed $media, mixed $singleUrl, User $actor, bool $mustOwn = true): self
    {
        $raw = self::normalise($media, $singleUrl);

        if ($raw === null) {
            return new self([], 'VALIDATION', 'media must be a list of attachments.');
        }
        if (count($raw) > FeedPostMedia::MAX_ITEMS) {
            return new self([], 'TOO_MANY_MEDIA', sprintf(
                'A post can carry at most %d images.', FeedPostMedia::MAX_ITEMS,
            ));
        }

        $items = [];
        $seen = [];

        foreach ($raw as $entry) {
            $attachment = SocialMediaAttachment::resolve($entry['url'], $actor, $mustOwn);
            if ($attachment['code'] !== null) {
                return new self([], $attachment['code'], $attachment['message']);
            }
            if ($attachment['url'] === null) {
                continue;
            }

            // The same picture twice is a duplicate submission, not a
            // carousel — and it would break the (post_id, sort_order)
            // ordering people expect when they reorder.
            if (isset($seen[$attachment['url']])) {
                return new self([], 'DUPLICATE_MEDIA', 'The same image cannot appear twice in one post.');
            }
            $seen[$attachment['url']] = true;

            $items[] = [
                'url' => $attachment['url'],
                'mime' => $attachment['item']?->mime_type,
                'style' => MediaFraming::sanitize($entry['style']),
                'alt' => self::text($entry['alt'], 1000),
                'width' => self::dimension($entry['width']),
                'height' => self::dimension($entry['height']),
                'aspect' => self::aspect($entry['width'], $entry['height'], $entry['aspect']),
            ];
        }

        return new self($items);
    }

    /**
     * Both request shapes to one list, in the order the author arranged.
     *
     * @return ?list<array{url: mixed, style: mixed, alt: mixed, width: mixed, height: mixed, aspect: mixed}>
     */
    private static function normalise(mixed $media, mixed $singleUrl): ?array
    {
        if ($media === null || $media === '') {
            $url = is_string($singleUrl) ? trim($singleUrl) : '';

            return $url === '' ? [] : [[
                'url' => $url, 'style' => null, 'alt' => null,
                'width' => null, 'height' => null, 'aspect' => null,
            ]];
        }

        if (! is_array($media)) {
            return null;
        }

        $out = [];
        foreach ($media as $entry) {
            if (is_string($entry)) {
                $entry = ['imageUrl' => $entry];
            }
            if (! is_array($entry)) {
                return null;
            }

            $url = $entry['imageUrl'] ?? $entry['url'] ?? null;
            if (! is_string($url) || trim($url) === '') {
                return null;
            }

            $out[] = [
                'url' => trim($url),
                'style' => $entry['styleJson'] ?? $entry['style'] ?? null,
                'alt' => $entry['altText'] ?? $entry['alt'] ?? null,
                'width' => $entry['width'] ?? null,
                'height' => $entry['height'] ?? null,
                'aspect' => $entry['aspectRatio'] ?? $entry['aspect'] ?? null,
            ];
        }

        return $out;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Pixel dimensions are advisory; a nonsensical one is simply forgotten. */
    private static function dimension(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $n = (int) $value;

        // 65535 is well past any real photo and short of anything that
        // would overflow the unsigned column.
        return ($n > 0 && $n <= 65535) ? $n : null;
    }

    private static function aspect(mixed $width, mixed $height, mixed $given): ?float
    {
        $w = self::dimension($width);
        $h = self::dimension($height);

        // Derived from the pixels when we have them: a client-sent ratio
        // that disagrees with its own image is the client's bug to lose.
        if ($w !== null && $h !== null) {
            return round($w / $h, 6);
        }

        if (is_numeric($given)) {
            $n = (float) $given;

            // Between a tall panorama and a wide one. Anything outside is
            // either a bug or an attempt to make a post a mile high.
            if (is_finite($n) && $n >= 0.05 && $n <= 20.0) {
                return round($n, 6);
            }
        }

        return null;
    }
}
