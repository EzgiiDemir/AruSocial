<?php

namespace App\Filament\Resources\Events\Tables;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('event_date', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Event $record) => $record->place_name),

                TextColumn::make('category')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('event_date')
                    ->label(__('panel.events.date'))
                    ->date()
                    ->sortable()
                    ->description(fn (Event $record) => $record->time),

                TextColumn::make('workflow_status')
                    ->label(__('panel.events.status'))
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                // The question someone actually opens this table to answer.
                IconColumn::make('live')
                    ->label('On the calendar')
                    ->boolean()
                    ->state(fn (Event $record) => $record->isPubliclyListed()),

                TextColumn::make('deleted_at')
                    ->label(__('panel.common.deleted_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('workflow_status')
                    ->label(__('panel.events.status'))
                    ->options([
                        'draft' => __('panel.events.workflow.draft'),
                        'pending' => __('panel.events.workflow.pending'),
                        'published' => __('panel.events.workflow.published'),
                        'rejected' => __('panel.events.workflow.rejected'),
                    ]),

                SelectFilter::make('category')
                    ->options(fn () => Event::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                Filter::make('publicly_listed')
                    ->label(__('panel.events.on_calendar'))
                    ->query(fn (Builder $query) => $query->publiclyListed())
                    ->toggle(),

                Filter::make('upcoming')
                    ->label('Upcoming')
                    ->query(fn (Builder $query) => $query->whereDate('event_date', '>=', today()))
                    ->toggle(),

                ...EventResource::contentFilters(),
            ])
            ->recordActions(EventResource::contentRecordActions())
            ->toolbarActions(EventResource::contentToolbarActions());
    }
}
