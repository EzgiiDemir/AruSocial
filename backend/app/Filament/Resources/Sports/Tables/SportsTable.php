<?php

namespace App\Filament\Resources\Sports\Tables;

use App\Filament\Resources\Sports\SportResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('facility')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(SportResource::contentFilters())
            ->recordActions(SportResource::contentRecordActions())
            ->toolbarActions(SportResource::contentToolbarActions());
    }
}
