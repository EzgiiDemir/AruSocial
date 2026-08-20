<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodDailyMenu extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['id', 'food_venue_id', 'menu_date', 'items', 'price', 'hours'];

    protected function casts(): array
    {
        return ['items' => 'array', 'menu_date' => 'date'];
    }

    public function venue()
    {
        return $this->belongsTo(FoodVenue::class, 'food_venue_id');
    }
}
