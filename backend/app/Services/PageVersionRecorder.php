<?php

namespace App\Services;

use App\Models\AdminPage;
use App\Models\ContentRevision;
use Illuminate\Support\Str;

class PageVersionRecorder
{
    public static function record(AdminPage $page): void
    {
        ContentRevision::create([
            'id' => 'revision-'.Str::uuid(),
            'content_key' => 'page:'.$page->id,
            'editor_name' => auth()->user()?->email ?? $page->updated_by ?? 'system',
            'snapshot' => $page->only([
                'title', 'slug', 'translations', 'blocks', 'status',
                'audiences', 'publish_at', 'expires_at',
            ]),
            'saved_at' => now(),
        ]);
    }
}
