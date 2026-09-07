<?php

namespace App\Support;

use App\Models\MediaItem;

class MediaPublicUrl
{
    public static function rewrite(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        if (str_contains($url, '/api/v1/media/')) {
            return $url;
        }
        if (preg_match('#/storage/(media(?:/video)?/[^/?]+)#', $url, $m)) {
            $item = MediaItem::query()->where('file_path', $m[1])->first();
            if ($item) {
                return $item->url();
            }
            $base = basename($m[1]);

            return url('/api/v1/media/file/'.$base);
        }

        return $url;
    }
}
