<?php

namespace App\Filament\Resources\Clubs\Tables;

use App\Filament\Resources\Clubs\ClubResource;
use App\Models\Club;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClubsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // Without this the member count is one query per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('members'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Club $record) => str($record->description)->limit(70)),

                TextColumn::make('category')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('members_count')
                    ->label(__('panel.clubs.members'))
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(fn () => Club::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                ...ClubResource::contentFilters(),
            ])
            ->recordActions(ClubResource::contentRecordActions())
            ->toolbarActions(ClubResource::contentToolbarActions());
    }
}
