<?php

namespace App\Filament\Resources\AiEvaluationCases;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Resources\AiEntityAliases\AiEntityAliasResource;
use App\Filament\Resources\AiEvaluationCases\Pages\CreateAiEvaluationCase;
use App\Filament\Resources\AiEvaluationCases\Pages\EditAiEvaluationCase;
use App\Filament\Resources\AiEvaluationCases\Pages\ListAiEvaluationCases;
use App\Filament\Resources\AiEvaluationRuns\AiEvaluationRunResource;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Jobs\RunAiEvaluationJob;
use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationResult;
use App\Services\Ai\Evaluation\AssertionEvaluator;
use App\Services\Ai\Evaluation\EvaluationRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * AICAD Tests: persistent evaluation cases with structured assertions.
 * Tests, not training data — nothing here reaches the router or the prompt.
 */
class AiEvaluationCaseResource extends Resource
{
    use AuthorizesAicadKnowledge;

    protected static ?string $model = AiEvaluationCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.aicad_tests.nav';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.aicad_tests.section'))
                ->schema([
                    TextInput::make('name')->label(__('panel.aicad_tests.name'))->required()->maxLength(191)->columnSpanFull(),
                    Textarea::make('question')->label(__('panel.playground.question'))->required()->rows(2)->maxLength(1000)->columnSpanFull(),
                    Select::make('locale')->label(__('panel.aicad_aliases.locale'))
                        ->options(['tr' => 'Türkçe', 'en' => 'English', 'ru' => 'Русский'])->placeholder('—'),
                    Select::make('mode')->label(__('panel.playground.mode'))->required()->default(AiEvaluationCase::MODE_RETRIEVAL)
                        ->options(self::modeOptions()),
                    TagsInput::make('tags')->label(__('panel.aicad_tests.tags'))
                        ->helperText(__('panel.aicad_tests.tags_hint')),
                    Toggle::make('active')->label(__('panel.aicad_aliases.active'))->default(true),
                    TextInput::make('context_user_email')->label(__('panel.aicad_tests.context_user'))
                        ->email()->maxLength(191)->helperText(__('panel.aicad_tests.context_user_hint')),
                    Textarea::make('notes')->label(__('panel.knowledge_sources.notes'))->rows(2)->maxLength(2000)->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('panel.playground.previous'))
                ->schema([
                    Repeater::make('previous_turns')->hiddenLabel()
                        ->schema([
                            Select::make('role')->options(['user' => 'user', 'assistant' => 'assistant'])->default('user')->required(),
                            Textarea::make('content')->rows(1)->required()->maxLength(2000),
                        ])
                        ->columns(2)->defaultItems(0)->reorderable(),
                ])
                ->collapsible(),
            Section::make(__('panel.aicad_tests.assertions'))
                ->description(__('panel.aicad_tests.assertions_hint'))
                ->schema([self::assertionsRepeater()]),
        ]);
    }

    /** Shared with Search Playground → Save as Evaluation Test. */
    public static function assertionsRepeater(): Repeater
    {
        $has = fn (string $param) => fn (Get $get): bool => in_array(
            $param, AssertionEvaluator::TYPES[(string) $get('type')][2] ?? [], true,
        );

        return Repeater::make('assertions')->hiddenLabel()
            ->schema([
                Select::make('type')->label(__('panel.aicad_tests.assertion_type'))->required()->live()
                    ->options(self::typeOptions())->searchable()->columnSpan(2),
                TagsInput::make('values')->label(__('panel.aicad_tests.values'))->visible($has('values')),
                TextInput::make('value')->label(__('panel.aicad_tests.value'))->visible($has('value'))
                    ->helperText(__('panel.aicad_tests.value_hint')),
                TextInput::make('match')->label(__('panel.aicad_tests.match'))->visible($has('match'))
                    ->helperText(__('panel.aicad_tests.match_hint')),
                TextInput::make('n')->label('N')->numeric()->minValue(1)->maxValue(10)->default(3)->visible($has('n')),
                Toggle::make('expect')->label(__('panel.aicad_tests.expect'))->default(true)->visible($has('expect')),
                Select::make('entity_type')->label(__('panel.aicad_aliases.entity_type'))->visible($has('entity_type'))
                    ->options(AiEntityAliasResource::typeOptions()),
                TextInput::make('entity_id')->label(__('panel.aicad_aliases.entity'))->visible($has('entity_id')),
                TextInput::make('target')->label(__('panel.aicad_tests.target'))->visible($has('target'))
                    ->helperText(__('panel.aicad_tests.target_hint')),
            ])
            ->columns(4)
            ->minItems(1)
            ->itemLabel(fn (array $state): ?string => $state['type'] ?? null)
            ->collapsible();
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        $out = [];
        foreach (AssertionEvaluator::TYPES as $type => [$stage]) {
            $out[$type] = $type.'  ·  '.str_replace('_', ' ', $stage);
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function modeOptions(): array
    {
        return [
            AiEvaluationCase::MODE_RETRIEVAL => __('panel.playground.mode_retrieval'),
            AiEvaluationCase::MODE_FULL => __('panel.playground.mode_full'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latestResult'))
            ->columns([
                TextColumn::make('name')->label(__('panel.aicad_tests.name'))->searchable()->limit(45)
                    ->description(fn (AiEvaluationCase $r) => mb_strimwidth((string) $r->question, 0, 70, '…')),
                TextColumn::make('locale')->label(__('panel.aicad_aliases.locale'))->badge()->placeholder('—'),
                TextColumn::make('mode')->label(__('panel.playground.mode'))->badge()
                    ->color(fn (string $state) => $state === AiEvaluationCase::MODE_FULL ? 'warning' : 'gray'),
                TextColumn::make('tags')->label(__('panel.aicad_tests.tags'))->badge()->limitList(3)->expandableLimitedList()->toggleable(),
                TextColumn::make('latestResult.status')->label(__('panel.aicad_tests.last_result'))->badge()->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        AiEvaluationResult::STATUS_PASSED => 'success',
                        AiEvaluationResult::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('latestResult.failure_stage')->label(__('panel.aicad_tests.failure_stage'))
                    ->formatStateUsing(fn (?string $state) => $state ? str_replace('_', ' ', $state) : null)
                    ->color('danger')->placeholder('—'),
                TextColumn::make('latestResult.duration_ms')->label(__('panel.aicad_tests.duration'))->suffix(' ms')->placeholder('—')->toggleable(),
                TextColumn::make('latestResult.created_at')->label(__('panel.aicad_tests.last_run'))->since()->placeholder('—'),
                IconColumn::make('active')->label(__('panel.aicad_aliases.active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('mode')->label(__('panel.playground.mode'))->options(self::modeOptions()),
                SelectFilter::make('locale')->label(__('panel.aicad_aliases.locale'))
                    ->options(['tr' => 'Türkçe', 'en' => 'English', 'ru' => 'Русский']),
                SelectFilter::make('tag')->label(__('panel.aicad_tests.tags'))
                    ->options(fn () => AiEvaluationCase::query()->pluck('tags')->flatten()->filter()->unique()->sort()->mapWithKeys(fn ($t) => [$t => $t])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereJsonContains('tags', $data['value']) : $query),
                SelectFilter::make('last_stage')->label(__('panel.aicad_tests.failure_stage'))
                    ->options(array_combine(AssertionEvaluator::STAGES, array_map(fn ($s) => str_replace('_', ' ', $s), AssertionEvaluator::STAGES)))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('latestResult', fn (Builder $r) => $r->where('failure_stage', $data['value'])) : $query),
                TernaryFilter::make('active')->label(__('panel.aicad_aliases.active')),
            ])
            ->recordActions([
                Action::make('run')->label(__('panel.playground.run'))->icon('heroicon-o-play')
                    ->action(fn (AiEvaluationCase $record) => self::startRun($record->mode, ['case_ids' => [$record->id], 'label' => $record->name])),
                Action::make('duplicate')->label(__('panel.aicad_tests.duplicate'))->icon('heroicon-o-document-duplicate')->color('gray')
                    ->visible(fn () => self::aicadCanManage())
                    ->action(function (AiEvaluationCase $record): void {
                        $copy = $record->replicate();
                        $copy->name = mb_substr($record->name.' (copy)', 0, 191);
                        $copy->created_by = auth()->user()?->email;
                        $copy->save();
                    }),
                Action::make('toggle')->label(fn (AiEvaluationCase $r) => $r->active ? __('panel.aicad_tests.disable') : __('panel.aicad_tests.enable'))
                    ->icon('heroicon-o-power')->color('gray')->visible(fn () => self::aicadCanManage())
                    ->action(fn (AiEvaluationCase $record) => $record->update(['active' => ! $record->active])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('runSelected')->label(__('panel.aicad_tests.run_selected'))->icon('heroicon-o-play')
                        ->action(fn (Collection $records) => self::startRun('all', ['case_ids' => $records->pluck('id')->all(), 'label' => 'selected'])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Queue a run and tell the operator where to watch it. */
    public static function startRun(string $mode, array $scope): void
    {
        $run = app(EvaluationRunner::class)->createRun($mode, $scope, auth()->user()?->email);
        RunAiEvaluationJob::dispatch($run->id);

        Notification::make()->success()
            ->title(__('panel.aicad_tests.run_queued', ['id' => $run->id]))
            ->body(__('panel.aicad_tests.run_queued_body'))
            ->actions([Action::make('open')->label(__('panel.aicad_tests.open_run'))
                ->url(AiEvaluationRunResource::getUrl('view', ['record' => $run]))])
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiEvaluationCases::route('/'),
            'create' => CreateAiEvaluationCase::route('/create'),
            'edit' => EditAiEvaluationCase::route('/{record}/edit'),
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
