<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAchievement extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'achievement_id', 'unlocked_at'];

    protected function casts(): array
    {
        return ['unlocked_at' => 'datetime'];
    }

    public function definition()
    {
        return $this->belongsTo(AchievementDefinition::class, 'achievement_id');
    }
}
