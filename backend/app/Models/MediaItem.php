<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MediaItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'file_path', 'file_name', 'mime_type', 'size_bytes',
        'uploaded_at', 'uploaded_by', 'used_in',
    ];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'used_in' => 'array'];
    }

    public function url(): string
    {
        return Storage::disk(self::disk())->url($this->file_path);
    }

    public static function disk(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }
}
