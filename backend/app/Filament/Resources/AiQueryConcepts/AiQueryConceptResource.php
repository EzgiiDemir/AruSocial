<?php

namespace App\Filament\Resources\AiQueryConcepts;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Resources\AiQueryConcepts\Pages\CreateAiQueryConcept;
use App\Filament\Resources\AiQueryConcepts\Pages\EditAiQueryConcept;
use App\Filament\Resources\AiQueryConcepts\Pages\ListAiQueryConcepts;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AiQueryConcept;
use App\Services\Ai\QueryPlanner;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Query concepts: wordings → domains, retrieval terms, preferred page paths.
 * They widen routing and retrieval for questions that name the concept;
 * they never supply an answer. Keep the list small — it is not a phrasebook.
 */
class AiQueryConceptResource extends Resource
{
    use AuthorizesAicadKnowledge;

    protected static ?string $model = AiQueryConcept::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.aicad_concepts.nav';

    protected static ?string $recordTitleAttribute = 'concept';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        $domains = array_keys(QueryPlanner::TOOLS_FOR_DOMAIN);

        return $schema->components([
            Section::make(__('panel.aicad_concepts.section'))
                ->description(__('panel.aicad_concepts.hint'))
                ->schema([
                    TextInput::make('concept')->label(__('panel.aicad_concepts.concept'))->required()->maxLength(64)
                        ->alphaDash()->unique(ignoreRecord: true),
                    TextInput::make('label')->label(__('panel.knowledge_sources.label'))->maxLength(191),
                    TagsInput::make('phrases')->label(__('panel.aicad_concepts.phrases'))->required()
                        ->helperText(__('panel.aicad_concepts.phrases_hint'))->columnSpanFull(),
                    Select::make('domains')->label(__('panel.playground.domain'))->multiple()
                        ->options(array_combine($domains, $domains)),
                    TagsInput::make('retrieval_terms')->label(__('panel.aicad_concepts.retrieval_terms'))
                        ->helperText(__('panel.aicad_concepts.retrieval_terms_hint')),
                    TagsInput::make('preferred_paths')->label(__('panel.aicad_concepts.preferred_paths'))
                        ->helperText(__('panel.aicad_concepts.preferred_paths_hint')),
                    Toggle::make('active')->label(__('panel.aicad_aliases.active'))->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('concept')
            ->columns([
                TextColumn::make('concept')->label(__('panel.aicad_concepts.concept'))->searchable()->weight('medium')
                    ->description(fn (AiQueryConcept $r) => $r->label),
                TextColumn::make('phrases')->label(__('panel.aicad_concepts.phrases'))->badge()->limitList(4)->expandableLimitedList(),
                TextColumn::make('domains')->label(__('panel.playground.domain'))->badge(),
                TextColumn::make('retrieval_terms')->label(__('panel.aicad_concepts.retrieval_terms'))->badge()->toggleable(),
                IconColumn::make('active')->label(__('panel.aicad_aliases.active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiQueryConcepts::route('/'),
            'create' => CreateAiQueryConcept::route('/create'),
            'edit' => EditAiQueryConcept::route('/{record}/edit'),
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
