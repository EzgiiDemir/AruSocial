<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaItem extends Model
{
    // Soft-deleted because a file removed here is referenced by posts,
    // place covers and avatars: the blank frame appears everywhere at
    // once and there is no way back. The bytes stay on disk until
    // somebody purges them deliberately, which is what makes restore
    // able to put something back.
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'user_id', 'file_path', 'file_name', 'mime_type', 'size_bytes',
        'uploaded_at', 'uploaded_by', 'used_in', 'moderation_status',
    ];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'used_in' => 'array'];
    }

    public function url(): string
    {
        // Served through the API so Flutter web gets CORS headers and
        // phones can rewrite the host to the LAN API they actually use.
        return url('/api/v1/media/'.$this->id.'/file');
    }

    public static function disk(): string
    {
        // User uploads must never land on Laravel's public disk before a
        // moderation decision. Configure a private disk in production.
        return (string) config('filesystems.media_disk', 'local');
    }
}
