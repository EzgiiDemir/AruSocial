<?php

namespace App\Filament\Resources\Places\Tables;

use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PlacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Place $record) => $record->street),

                TextColumn::make('category')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                IconColumn::make('accessible')
                    ->label(__('panel.places.accessible'))
                    ->boolean()
                    ->sortable(),

                IconColumn::make('tour_url')
                    ->label('360°')
                    ->boolean()
                    ->state(fn (Place $record) => filled($record->tour_url)),

                TextColumn::make('rating')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => Place::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                TernaryFilter::make('accessible')->label(__('panel.places.accessible')),

                TernaryFilter::make('tour_url')
                    ->label(__('panel.places.has_tour'))
                    ->nullable()
                    ->attribute('tour_url'),

                ...PlaceResource::contentFilters(),
            ])
            ->recordActions(PlaceResource::contentRecordActions())
            ->toolbarActions(PlaceResource::contentToolbarActions());
    }
}
