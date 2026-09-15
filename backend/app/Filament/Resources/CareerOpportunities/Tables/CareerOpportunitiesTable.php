<?php

namespace App\Filament\Resources\CareerOpportunities\Tables;

use App\Filament\Resources\CareerOpportunities\CareerOpportunityResource;
use App\Models\CareerOpportunity;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CareerOpportunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (CareerOpportunity $record) => $record->organization),

                TextColumn::make('kind')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('location')
                    ->searchable()
                    ->toggleable(),

                // Coloured because an expired listing that still looks live is
                // the failure students actually notice.
                TextColumn::make('deadline')
                    ->date()
                    ->sortable()
                    ->color(fn (CareerOpportunity $record) => $record->deadline?->isPast() ? 'danger' : null),

                IconColumn::make('published')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->options(fn () => CareerOpportunity::query()
                        ->whereNotNull('kind')
                        ->distinct()
                        ->orderBy('kind')
                        ->pluck('kind', 'kind')
                        ->all()),

                TernaryFilter::make('published'),

                Filter::make('expired')
                    ->label(__('panel.career.expired'))
                    ->query(fn (Builder $query) => $query
                        ->whereNotNull('deadline')
                        ->whereDate('deadline', '<', now()))
                    ->toggle(),

                ...CareerOpportunityResource::contentFilters(),
            ])
            ->recordActions(CareerOpportunityResource::contentRecordActions())
            ->toolbarActions(CareerOpportunityResource::contentToolbarActions());
    }
}
