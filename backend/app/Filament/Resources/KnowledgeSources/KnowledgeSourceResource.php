<?php

namespace App\Filament\Resources\KnowledgeSources;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use App\Filament\Resources\KnowledgeSources\Pages\CreateKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\EditKnowledgeSource;
use App\Filament\Resources\KnowledgeSources\Pages\ListKnowledgeSources;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Jobs\CrawlKnowledgeUrlJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Page URLs an operator wants AICAD to read, without a deploy. A URL is
 * only accepted inside an enabled crawl domain (AICAD Crawl Sites), and the
 * fetch result is the crawled page with the same URL.
 */
class KnowledgeSourceResource extends Resource
{
    use AuthorizesAicadKnowledge;

    protected static ?string $model = KnowledgeSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.knowledge_sources.nav';

    protected static ?string $recordTitleAttribute = 'url';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.knowledge_sources.section'))
                ->description(__('panel.knowledge_sources.hint'))
                ->schema([
                    TextInput::make('url')
                        ->label(__('panel.knowledge_sources.url'))
                        ->required()
                        ->maxLength(700)
                        ->unique(ignoreRecord: true)
                        ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                            $reason = KnowledgeSource::rejectionFor((string) $value);
                            if ($reason !== null) {
                                $fail($reason);
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('label')
                        ->label(__('panel.knowledge_sources.label'))
                        ->maxLength(255),
                    Select::make('locale')
                        ->label(__('panel.knowledge_sources.locale'))
                        ->options(['tr' => 'Türkçe', 'en' => 'English', 'ru' => 'Русский'])
                        ->placeholder('—'),
                    Toggle::make('enabled')
                        ->label(__('panel.knowledge_sources.enabled'))
                        ->default(true),
                    Textarea::make('notes')
                        ->label(__('panel.knowledge_sources.notes'))
                        ->rows(2)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('document'))
            ->columns([
                TextColumn::make('url')
                    ->label(__('panel.knowledge_sources.url'))
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn (KnowledgeSource $record) => $record->url)
                    ->description(fn (KnowledgeSource $record) => $record->label),
                IconColumn::make('enabled')
                    ->label(__('panel.knowledge_sources.enabled'))
                    ->boolean(),
                TextColumn::make('last_crawl_status')
                    ->label(__('panel.knowledge_sources.crawl_status'))
                    ->badge()
                    ->placeholder(__('panel.knowledge_sources.never'))
                    ->color(fn (?string $state) => match ($state) {
                        KnowledgeSource::STATUS_OK => 'success',
                        KnowledgeSource::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('document.http_status')
                    ->label(__('panel.knowledge_documents.http_status'))
                    ->placeholder('—'),
                TextColumn::make('document.content_length')
                    ->label(__('panel.knowledge_documents.chars'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('chunks')
                    ->label(__('panel.knowledge_documents.chunks'))
                    ->state(fn (KnowledgeSource $record) => self::chunkSummary($record->document)),
                TextColumn::make('document.fetched_at')
                    ->label(__('panel.knowledge_documents.fetched_at'))
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('last_crawl_error')
                    ->label(__('panel.knowledge_documents.last_error'))
                    ->state(fn (KnowledgeSource $record) => $record->last_crawl_error ?: $record->document?->last_error)
                    ->limit(50)
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('crawlNow')
                    ->label(__('panel.knowledge_sources.crawl_now'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn () => self::aicadCanManage())
                    ->action(function (KnowledgeSource $record): void {
                        $record->forceFill([
                            'last_crawl_status' => KnowledgeSource::STATUS_QUEUED,
                            'last_crawl_error' => null,
                            'last_crawl_requested_at' => now(),
                        ])->save();
                        CrawlKnowledgeUrlJob::dispatch($record->url, (int) $record->getKey());
                        Notification::make()->success()
                            ->title(__('panel.knowledge_sources.crawl_queued'))
                            ->send();
                    }),
                Action::make('inspect')
                    ->label(__('panel.knowledge_sources.inspect'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->visible(fn (KnowledgeSource $record) => $record->document !== null)
                    ->url(fn (KnowledgeSource $record) => $record->document === null
                        ? null
                        : KnowledgeDocumentResource::getUrl('view', ['record' => $record->document])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** "12 / 12 embedded", or a dash before the first crawl. */
    public static function chunkSummary(?KnowledgeDocument $document): string
    {
        if ($document === null) {
            return '—';
        }
        $total = KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->count();
        $embedded = KnowledgeChunk::query()->where('knowledge_document_id', $document->id)
            ->whereNotNull('embedding')->count();

        return __('panel.knowledge_documents.chunk_summary', ['embedded' => $embedded, 'total' => $total]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeSources::route('/'),
            'create' => CreateKnowledgeSource::route('/create'),
            'edit' => EditKnowledgeSource::route('/{record}/edit'),
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
        return self::aicadCanManage();
    }

    public static function canEdit($record): bool
    {
        return self::aicadCanManage();
    }

    public static function canDelete($record): bool
    {
        return self::aicadCanManage();
    }

    public static function canDeleteAny(): bool
    {
        return self::aicadCanManage();
    }
}
