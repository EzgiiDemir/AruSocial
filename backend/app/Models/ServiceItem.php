<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceItem extends Model
{
    use SoftDeletes;

    protected $table = 'services';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** A phone number as staff type it: digits, spaces, + ( ) - . only. */
    public const PHONE_RULE = 'regex:/^\+?[0-9][0-9 ().\-]{5,38}$/';

    protected $fillable = [
        'id', 'title', 'category', 'description', 'contact', 'building',
        'floor', 'room', 'contact_person', 'topics', 'hours', 'body',
        'responsible_staff_id', 'phone',
    ];

    protected function casts(): array
    {
        return ['topics' => 'array', 'body' => 'array'];
    }
}
