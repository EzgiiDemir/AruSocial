<?php

namespace App\Filament\Resources\AdminPages\Tables;

use App\Filament\Resources\AdminPages\AdminPageResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdminPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('title')->label(__('panel.pages.internal_title'))->searchable()->sortable()->weight('medium'),
            TextColumn::make('slug')->searchable()->copyable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('blocks')->label(__('panel.pages.blocks'))->formatStateUsing(fn ($state) => count($state ?? [])),
            TextColumn::make('updated_by')->label(__('panel.pages.updated_by'))->toggleable(),
            TextColumn::make('updated_at')->label(__('panel.pages.updated_at'))->dateTime()->sortable(),
            TextColumn::make('deleted_at')->label(__('panel.common.deleted_at'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->options([
                'draft' => __('panel.pages.draft'), 'review' => __('panel.pages.review'),
                'published' => __('panel.pages.published'), 'archived' => __('panel.pages.archived'),
            ]),
            ...AdminPageResource::contentFilters(),
        ])->recordActions(AdminPageResource::contentRecordActions())
            ->toolbarActions(AdminPageResource::contentToolbarActions());
    }
}
