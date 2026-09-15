<?php

namespace App\Filament\Trainer\Resources\Events\Tables;

use App\Models\Event;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The trainer's event list.
 *
 * Same columns and same filters as the admin table, because a department
 * head reading their own events should not have to learn a second screen.
 * What is absent is deliberate: no permanent delete. Purging a row is
 * irreversible and belongs with the people who can also restore anything
 * else in the university.
 */
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

                TextColumn::make('category')->badge()->searchable()->sortable(),

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

                IconColumn::make('live')
                    ->label(__('panel.events.on_calendar'))
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

                Filter::make('upcoming')
                    ->label(__('panel.events.upcoming'))
                    ->query(fn (Builder $query) => $query->whereDate('event_date', '>=', today()))
                    ->toggle(),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ExportBulkAction::make(),
                ]),
            ]);
    }
}
