<?php

namespace App\Filament\Resources\CrawlSources\Tables;

use App\Models\CrawlSource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CrawlSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')
                    ->label(__('panel.crawl_sources.domain'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('label')
                    ->label(__('panel.crawl_sources.label'))
                    ->toggleable(),

                // Searchable so an operator can answer "which source owns
                // 'burs'?" from the table itself.
                TextColumn::make('keys')
                    ->label(__('panel.crawl_sources.keys'))
                    ->badge()
                    ->separator(',')
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->searchable()
                    ->placeholder(__('panel.crawl_sources.keys_empty'))
                    ->toggleable(),

                TextColumn::make('access')
                    ->label(__('panel.crawl_sources.access'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === CrawlSource::ACCESS_LOCAL
                        ? __('panel.crawl_sources.access_local')
                        : __('panel.crawl_sources.access_global'))
                    ->color(fn (string $state) => $state === CrawlSource::ACCESS_LOCAL ? 'warning' : 'success'),

                IconColumn::make('enabled')
                    ->label(__('panel.crawl_sources.enabled'))
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label(__('panel.common.updated_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
