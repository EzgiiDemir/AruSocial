<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One crawled public ARUCAD web page. See the migration for why it holds only
 * public page text and no personal data.
 */
class KnowledgeDocument extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'url', 'content_type', 'document_status', 'authority', 'domain', 'title', 'content', 'content_clean', 'content_folded', 'content_hash',
        'content_length', 'http_status', 'language', 'fetched_at',
        'last_modified_at', 'page_count', 'fail_count', 'last_error', 'is_stale', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'last_modified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'authority' => 'integer',
            'page_count' => 'integer',
            'content_length' => 'integer',
            'http_status' => 'integer',
            'fail_count' => 'integer',
            'is_stale' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class)->orderBy('position');
    }

    /** Stable row id for a URL, so a re-crawl updates rather than duplicates. */
    public static function idForUrl(string $url): string
    {
        return sha1($url);
    }
}
