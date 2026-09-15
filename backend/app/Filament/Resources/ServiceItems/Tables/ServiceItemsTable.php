<?php

namespace App\Filament\Resources\ServiceItems\Tables;

use App\Filament\Resources\ServiceItems\ServiceItemResource;
use App\Models\ServiceItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServiceItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('category')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('building')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ServiceItem $record) => collect([
                        $record->floor,
                        $record->room,
                    ])->filter()->join(' · ')),

                TextColumn::make('contact')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => ServiceItem::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                ...ServiceItemResource::contentFilters(),
            ])
            ->recordActions(ServiceItemResource::contentRecordActions())
            ->toolbarActions(ServiceItemResource::contentToolbarActions());
    }
}
