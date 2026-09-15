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

    protected $fillable = ['id', 'name', 'hours', 'menu_text', 'menu_file_url'];

    public function dailyMenus()
    {
        return $this->hasMany(FoodDailyMenu::class);
    }
}
