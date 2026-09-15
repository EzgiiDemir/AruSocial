<?php

namespace App\Filament\Resources;

use Filament\Resources\Resource;
use Illuminate\Support\Facades\Lang;
use UnitEnum;

abstract class TranslatedResource extends Resource
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = parent::getNavigationGroup();

        return is_string($group) && Lang::has($group) ? __($group) : $group;
    }

    public static function getNavigationLabel(): string
    {
        $label = parent::getNavigationLabel();

        return Lang::has($label) ? __($label) : $label;
    }
}
