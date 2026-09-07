<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkshopEquipmentItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['id', 'place_id', 'name', 'available', 'sort_order', 'updated_by'];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function place()
    {
        return $this->belongsTo(Place::class);
    }
}
