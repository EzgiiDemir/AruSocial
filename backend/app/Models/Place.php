<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'name', 'category', 'lat', 'lng', 'coordinate_confidence', 'description', 'distance',
        'density', 'street', 'tour_url', 'accessible', 'photos', 'rating',
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
