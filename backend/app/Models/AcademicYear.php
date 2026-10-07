<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'label', 'starts_on', 'ends_on', 'is_active'];

    /**
     * Only one academic year is active at a time: when this one is active,
     * every other is deactivated (the rule the API's upsert applies too).
     */
    public static function keepOnlyActive(self $year): void
    {
        if ($year->is_active) {
            self::query()->whereKeyNot($year->getKey())->where('is_active', true)->update(['is_active' => false]);
        }
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }
}
