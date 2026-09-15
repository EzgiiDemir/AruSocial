<?php

namespace App\Filament\Resources\Clubs\Schemas;

use App\Models\Club;
use App\Models\StaffProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClubForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.clubs.section'))
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(160)
                        ->columnSpanFull(),

                    TextInput::make('category')
                        ->required()
                        ->maxLength(60)
                        ->datalist(fn () => Club::query()
                            ->whereNotNull('category')
                            ->distinct()
                            ->orderBy('category')
                            ->pluck('category')
                            ->all()),

                    Select::make('responsible_staff_id')
                        ->label(__('panel.common.responsible_staff'))
                        ->options(fn () => StaffProfile::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->helperText(__('panel.common.responsible_staff_help')),

                    // NOT NULL with an empty-string default: a column default
                    // only applies when the column is left out of the INSERT,
                    // and an empty field sends the null Filament defaults to.
                    Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull()
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),
                ])
                ->columns(2),
        ]);
    }
}
