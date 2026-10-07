<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'name', 'category', 'description', 'body', 'responsible_staff_id',
        'email', 'website', 'instagram_url', 'place_id'];

    protected function casts(): array
    {
        return ['body' => 'array'];
    }

    public function members()
    {
        return $this->hasMany(ClubMember::class, 'club_id');
    }
}
