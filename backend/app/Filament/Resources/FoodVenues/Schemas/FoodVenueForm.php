<?php

namespace App\Filament\Resources\FoodVenues\Schemas;

use App\Models\Place;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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

                    Select::make('place_id')
                        ->label(__('panel.food.place'))
                        ->helperText(__('panel.food.place_help'))
                        ->options(fn () => Place::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),

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

            Section::make(__('panel.food.weekly_hours'))
                ->description(__('panel.food.weekly_hours_help'))
                ->schema([
                    Repeater::make('openingHours')
                        ->hiddenLabel()
                        ->relationship()
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['subject_type' => 'food_venue'])
                        ->schema([
                            Select::make('day_of_week')
                                ->label(__('panel.food.day'))
                                ->options(fn () => __('panel.food.days'))
                                ->required(),
                            TimePicker::make('opens')->label(__('panel.food.opens'))->seconds(false)->required(),
                            TimePicker::make('closes')->label(__('panel.food.closes'))->seconds(false)->required()->after('opens'),
                            DatePicker::make('valid_from')->label(__('panel.food.valid_from')),
                            DatePicker::make('valid_until')->label(__('panel.food.valid_until'))->afterOrEqual('valid_from'),
                        ])
                        ->columns(5)
                        ->defaultItems(0),
                ]),
        ]);
    }
}
