<?php

namespace App\Filament\Resources\ShuttleRoutes\Tables;

use App\Filament\Resources\ShuttleRoutes\ShuttleRouteResource;
use App\Models\ShuttleRoute;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShuttleRoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('color_key')
                    ->label(__('panel.shuttle.colour'))
                    ->badge(),

                TextColumn::make('stops')
                    ->label(__('panel.shuttle.stops'))
                    ->state(fn (ShuttleRoute $record) => count($record->stops ?? []))
                    ->sortable(false),

                TextColumn::make('departures')
                    ->label(__('panel.shuttle.departures'))
                    ->state(fn (ShuttleRoute $record) => count($record->departures ?? []))
                    ->sortable(false),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(ShuttleRouteResource::contentFilters())
            ->recordActions(ShuttleRouteResource::contentRecordActions())
            ->toolbarActions(ShuttleRouteResource::contentToolbarActions());
    }
}
