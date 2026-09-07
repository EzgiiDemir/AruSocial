<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'name', 'category', 'lat', 'lng', 'description', 'distance',
        'density', 'street', 'tour_url', 'tour_target', 'accessible', 'photos', 'rating',
        'cover_url',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'rating' => 'float',
            'accessible' => 'boolean',
        ];
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
