<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceItem extends Model
{
    protected $table = 'services';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'title', 'category', 'description', 'contact', 'building',
        'floor', 'room', 'contact_person', 'topics', 'hours', 'body',
        'responsible_staff_id',
    ];

    protected function casts(): array
    {
        return ['topics' => 'array', 'body' => 'array'];
    }
}
