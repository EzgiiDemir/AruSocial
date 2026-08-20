<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $primaryKey = 'key';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key): ?string
    {
        return static::find($key)?->value;
    }

    public static function setValue(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            static::where('key', $key)->delete();

            return;
        }
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
