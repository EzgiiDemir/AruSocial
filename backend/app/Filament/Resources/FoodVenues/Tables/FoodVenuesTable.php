<?php

namespace App\Filament\Resources\FoodVenues\Tables;

use App\Filament\Resources\FoodVenues\FoodVenueResource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FoodVenuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('dailyMenus'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('hours')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('daily_menus_count')
                    ->label(__('panel.food.daily_menus'))
                    ->sortable(),

                IconColumn::make('menu_file_url')
                    ->label(__('panel.food.has_menu_file'))
                    ->boolean()
                    ->state(fn ($record) => filled($record->menu_file_url)),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(FoodVenueResource::contentFilters())
            ->recordActions(FoodVenueResource::contentRecordActions())
            ->toolbarActions(FoodVenueResource::contentToolbarActions());
    }
}
