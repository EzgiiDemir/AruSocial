<?php

namespace App\Filament\Resources\ShuttleRoutes\Schemas;

use App\Models\ShuttleRoute;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShuttleRouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.shuttle.section'))
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(160),

                    Select::make('color_key')
                        ->label(__('panel.shuttle.colour'))
                        ->options(array_combine(
                            ShuttleRoute::COLOR_KEYS,
                            ShuttleRoute::COLOR_KEYS,
                        ))
                        ->default('blue')
                        ->required()
                        ->helperText(__('panel.shuttle.colour_help')),

                    TextInput::make('sort_order')
                        ->label(__('panel.shuttle.order'))
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->helperText(__('panel.shuttle.order_help')),
                ])
                ->columns(3),

            Section::make(__('panel.shuttle.stops'))
                ->description(__('panel.shuttle.stops_help'))
                ->schema([
                    Repeater::make('stops')
                        ->simple(TextInput::make('stop')->required()->maxLength(120))
                        ->default([])
                        ->reorderable()
                        ->addActionLabel(__('panel.shuttle.add_stop'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('panel.shuttle.times'))
                ->description(__('panel.shuttle.times_help'))
                ->schema([
                    Repeater::make('departures')
                        ->label(__('panel.shuttle.departures'))
                        ->simple(TextInput::make('time')->required()->maxLength(20))
                        ->default([])
                        ->addActionLabel(__('panel.shuttle.add_departure')),

                    Repeater::make('returns')
                        ->label(__('panel.shuttle.returns'))
                        ->simple(TextInput::make('time')->maxLength(20))
                        ->default([])
                        ->addActionLabel(__('panel.shuttle.add_return')),
                ])
                ->columns(2),
        ]);
    }
}
