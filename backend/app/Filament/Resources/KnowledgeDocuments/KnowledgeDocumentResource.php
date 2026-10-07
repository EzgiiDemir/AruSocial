<?php

namespace App\Filament\Resources\KnowledgeDocuments;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments;
use App\Filament\Resources\KnowledgeDocuments\Pages\ViewKnowledgeDocument;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\KnowledgeDocument;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Every page the crawler holds, with what it extracted and whether its
 * passages are embedded. Read-only: pages are written by the crawler, and
 * the recrawl / re-embed actions on the view page queue that work.
 */
class KnowledgeDocumentResource extends Resource
{
    use AuthorizesAicadKnowledge;

    protected static ?string $model = KnowledgeDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.knowledge_documents.nav';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 22;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('fetched_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'chunks',
                'chunks as embedded_count' => fn (Builder $q) => $q->whereNotNull('embedding'),
            ]))
            ->columns([
                TextColumn::make('title')
                    ->label(__('panel.knowledge_documents.title'))
                    ->searchable()
                    ->limit(50)
                    ->description(fn (KnowledgeDocument $record) => $record->url),
                TextColumn::make('domain')
                    ->label(__('panel.knowledge_documents.domain'))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('language')
                    ->label(__('panel.knowledge_documents.language'))
                    ->badge(),
                TextColumn::make('http_status')
                    ->label(__('panel.knowledge_documents.http_status'))
                    ->sortable(),
                TextColumn::make('content_length')
                    ->label(__('panel.knowledge_documents.chars'))
                    ->numeric()
                    ->sortable()
                    // Under a few hundred characters the extractor almost
                    // certainly kept only navigation, or the page is JS-built.
                    ->color(fn (?int $state) => ($state ?? 0) < 300 ? 'danger' : null),
                TextColumn::make('chunks_count')
                    ->label(__('panel.knowledge_documents.chunks'))
                    ->formatStateUsing(fn (KnowledgeDocument $record) => __('panel.knowledge_documents.chunk_summary', [
                        'embedded' => (int) $record->getAttribute('embedded_count'),
                        'total' => (int) $record->getAttribute('chunks_count'),
                    ]))
                    ->sortable(),
                IconColumn::make('is_stale')
                    ->label(__('panel.knowledge_documents.stale'))
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('fetched_at')
                    ->label(__('panel.knowledge_documents.fetched_at'))
                    ->since()
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label(__('panel.knowledge_documents.last_error'))
                    ->limit(40)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_stale')->label(__('panel.knowledge_documents.stale')),
                Filter::make('thin')
                    ->label(__('panel.knowledge_documents.filter_thin'))
                    ->query(fn (Builder $query) => $query->where('content_length', '<', 300)),
                Filter::make('unembedded')
                    ->label(__('panel.knowledge_documents.filter_unembedded'))
                    ->query(fn (Builder $query) => $query->whereDoesntHave('chunks', fn (Builder $c) => $c->whereNotNull('embedding'))),
                Filter::make('errors')
                    ->label(__('panel.knowledge_documents.filter_errors'))
                    ->query(fn (Builder $query) => $query->whereNotNull('last_error')->where('last_error', '!=', '')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeDocuments::route('/'),
            'view' => ViewKnowledgeDocument::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return self::aicadCanRead();
    }

    public static function canView($record): bool
    {
        return self::aicadCanRead();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
