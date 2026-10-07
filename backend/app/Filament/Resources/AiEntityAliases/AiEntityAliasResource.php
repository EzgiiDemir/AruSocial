<?php

namespace App\Filament\Resources\AiEntityAliases;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Resources\AiEntityAliases\Pages\CreateAiEntityAlias;
use App\Filament\Resources\AiEntityAliases\Pages\EditAiEntityAlias;
use App\Filament\Resources\AiEntityAliases\Pages\ListAiEntityAliases;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AiEntityAlias;
use App\Models\KnowledgeFact;
use App\Support\TextFold;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Alternative names for campus entities ("gym" → Sports Center). They feed
 * EntityResolver, PlaceResolver and QueryPlanner — never the prompt text.
 */
class AiEntityAliasResource extends Resource
{
    use AuthorizesAicadKnowledge;

    protected static ?string $model = AiEntityAlias::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.aicad_aliases.nav';

    protected static ?string $recordTitleAttribute = 'alias';

    protected static ?int $navigationSort = 20;

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        $out = [];
        foreach (array_keys(AiEntityAlias::TYPES) as $type) {
            $out[$type] = __('panel.aicad_aliases.types.'.$type);
        }

        return $out;
    }

    /** @return array<string, string> id => display name, for one entity type */
    public static function entityOptions(?string $type): array
    {
        if ($type === AiEntityAlias::TYPE_PROGRAMME) {
            // Programmes are the subjects of the extracted programme facts.
            return KnowledgeFact::query()->where('subject_type', KnowledgeFact::SUBJECT_PROGRAMME)->orderBy('subject')
                ->pluck('subject', 'subject_folded')->map(fn ($s) => (string) $s)->all();
        }
        $class = AiEntityAlias::TYPES[$type] ?? null;
        if ($class === null) {
            return [];
        }
        $column = $type === AiEntityAlias::TYPE_SERVICE ? 'title' : 'name';

        return $class::query()->orderBy($column)->pluck($column, 'id')
            ->map(fn ($label, $id) => trim((string) $label) !== '' ? (string) $label : (string) $id)
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.aicad_aliases.section'))
                ->description(__('panel.aicad_aliases.hint'))
                ->schema([
                    Select::make('entity_type')
                        ->label(__('panel.aicad_aliases.entity_type'))
                        ->options(self::typeOptions())
                        ->required()
                        ->live(),
                    Select::make('entity_id')
                        ->label(__('panel.aicad_aliases.entity'))
                        ->options(fn (Get $get) => self::entityOptions($get('entity_type')))
                        ->searchable()
                        ->required(),
                    TextInput::make('alias')
                        ->label(__('panel.aicad_aliases.alias'))
                        ->helperText(__('panel.aicad_aliases.alias_hint'))
                        ->required()
                        ->maxLength(191)
                        ->rule(fn (Get $get, ?AiEntityAlias $record) => self::uniqueAliasRule($get, $record)),
                    Select::make('locale')
                        ->label(__('panel.aicad_aliases.locale'))
                        ->options(['tr' => 'Türkçe', 'en' => 'English', 'ru' => 'Русский'])
                        ->placeholder(__('panel.aicad_aliases.locale_any')),
                    Toggle::make('active')
                        ->label(__('panel.aicad_aliases.active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    /** Same entity, same folded alias: "Gym" and "gym" are one alias. */
    private static function uniqueAliasRule(Get $get, ?AiEntityAlias $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $normalized = trim(preg_replace('/\s+/u', ' ', TextFold::fold((string) $value)) ?? '');
            $exists = AiEntityAlias::query()
                ->where('entity_type', (string) $get('entity_type'))
                ->where('entity_id', (string) $get('entity_id'))
                ->where('normalized_alias', $normalized)
                ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                ->exists();
            if ($exists) {
                $fail(__('panel.aicad_aliases.duplicate'));
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('alias')
                    ->label(__('panel.aicad_aliases.alias'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('entity_type')
                    ->label(__('panel.aicad_aliases.entity_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::typeOptions()[$state] ?? $state),
                TextColumn::make('entity_id')
                    ->label(__('panel.aicad_aliases.entity'))
                    ->formatStateUsing(function (AiEntityAlias $record): string {
                        $entity = $record->entity();

                        return $entity === null
                            ? __('panel.aicad_aliases.entity_missing', ['id' => $record->entity_id])
                            : (string) ($entity->getAttribute('name') ?? $entity->getAttribute('title'));
                    })
                    ->searchable(),
                TextColumn::make('locale')
                    ->label(__('panel.aicad_aliases.locale'))
                    ->placeholder('—'),
                IconColumn::make('active')
                    ->label(__('panel.aicad_aliases.active'))
                    ->boolean(),
                TextColumn::make('created_by')
                    ->label(__('panel.aicad_aliases.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('panel.aicad_aliases.updated_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->label(__('panel.aicad_aliases.entity_type'))
                    ->options(self::typeOptions()),
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

    public static function getPages(): array
    {
        return [
            'index' => ListAiEntityAliases::route('/'),
            'create' => CreateAiEntityAlias::route('/create'),
            'edit' => EditAiEntityAlias::route('/{record}/edit'),
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
