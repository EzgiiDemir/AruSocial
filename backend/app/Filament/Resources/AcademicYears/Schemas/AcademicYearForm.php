<?php

namespace App\Filament\Resources\AcademicYears\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AcademicYearForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.academic_years.section'))
                ->description(__('panel.academic_years.help'))
                ->schema([
                    TextInput::make('label')
                        ->label(__('panel.academic_years.label'))
                        ->required()
                        ->maxLength(20)
                        ->regex('/^20\d\d[-–]20\d\d$/')
                        ->placeholder('2026-2027'),

                    Toggle::make('is_active')
                        ->label(__('panel.academic_years.is_active'))
                        ->helperText(__('panel.academic_years.is_active_help')),

                    DatePicker::make('starts_on')
                        ->label(__('panel.academic_years.starts_on'))
                        ->required(),

                    DatePicker::make('ends_on')
                        ->label(__('panel.academic_years.ends_on'))
                        ->required()
                        ->after('starts_on'),
                ])
                ->columns(2),
        ]);
    }
}
