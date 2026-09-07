<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaItem extends Model
{
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
        return (string) config('filesystems.media_disk', 'public');
    }
}
