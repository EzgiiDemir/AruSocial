<?php

namespace App\Support;

use App\Models\MediaItem;
use App\Models\User;

/**
 * Resolves a social attachment to an ARUCAD-owned, approved media item.
 *
 * Social endpoints intentionally do not accept arbitrary remote image URLs:
 * those files would bypass the local moderation chain.  The API stores the
 * canonical media URL, not the client-provided spelling of it.
 */
class SocialMediaAttachment
{
    /**
     * @return array{item: ?MediaItem, url: ?string, code: ?string, message: ?string}
     */
    public static function resolve(mixed $value, User $actor, bool $mustOwn = true): array
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return ['item' => null, 'url' => null, 'code' => null, 'message' => null];
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path)
            || ! preg_match('#(?:^|/)api/v1/media/([^/]+)/file$#', $path, $match)) {
            return self::failure(
                'SOCIAL_MEDIA_UPLOAD_REQUIRED',
                'Sosyal paylaşımlarda yalnızca ARUCAD medya kütüphanesine yüklenen dosyalar kullanılabilir.'
            );
        }

        $item = MediaItem::find(rawurldecode($match[1]));
        if (! $item) {
            return self::failure('MEDIA_NOT_FOUND', 'Seçilen medya bulunamadı.');
        }
        if (($item->moderation_status ?? 'approved') === 'pending') {
            return self::failure(
                'MEDIA_PENDING_REVIEW',
                'Medya inceleme kuyruğunda. Onaylanmadan sosyal paylaşımda görünmez.'
            );
        }
        if (($item->moderation_status ?? 'approved') !== 'approved') {
            return self::failure('MEDIA_REJECTED', 'Bu medya yayın için onaylanmadı.');
        }
        if ($mustOwn && (int) $item->user_id !== (int) $actor->id) {
            return self::failure('MEDIA_NOT_OWNED', 'Yalnızca kendi onaylı medyanı paylaşabilirsin.');
        }

        return ['item' => $item, 'url' => $item->url(), 'code' => null, 'message' => null];
    }

    /** @return array{item: null, url: null, code: string, message: string} */
    private static function failure(string $code, string $message): array
    {
        return ['item' => null, 'url' => null, 'code' => $code, 'message' => $message];
    }
}
