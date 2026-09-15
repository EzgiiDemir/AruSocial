<?php

namespace App\Filament\Resources\FoodVenues\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FoodVenueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.food.section'))
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(160),

                    TextInput::make('hours')
                        ->maxLength(120)
                        ->label(__('panel.food.hours')),

                    // Daily menus are their own rows with their own dates and
                    // are edited through the food endpoints; this is the
                    // standing menu that does not change day to day.
                    Textarea::make('menu_text')
                        ->label(__('panel.food.standing_menu'))
                        ->rows(6)
                        ->columnSpanFull(),

                    TextInput::make('menu_file_url')
                        ->label(__('panel.food.menu_file'))
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
