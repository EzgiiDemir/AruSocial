<?php

namespace App\Filament\Resources\Sports\Schemas;

use App\Models\StaffProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.sports.section'))
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(160),

                    TextInput::make('facility')
                        ->required()
                        ->maxLength(160)
                        ->helperText(__('panel.sports.facility_help')),

                    TextInput::make('contact')
                        ->maxLength(160)
                        ->helperText(__('panel.sports.contact_help')),

                    Select::make('responsible_staff_id')
                        ->label(__('panel.common.responsible_staff'))
                        ->options(fn () => StaffProfile::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable(),
                ])
                ->columns(2),
        ]);
    }
}
