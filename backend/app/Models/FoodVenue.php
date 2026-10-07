<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FoodVenue extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'name', 'place_id', 'hours', 'menu_text', 'menu_file_url'];

    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    /** Structured weekly hours (OpeningHour rows of subject type food_venue). */
    public function openingHours()
    {
        return $this->hasMany(OpeningHour::class, 'subject_id')->where('subject_type', 'food_venue');
    }

    public function dailyMenus()
    {
        return $this->hasMany(FoodDailyMenu::class);
    }
}
