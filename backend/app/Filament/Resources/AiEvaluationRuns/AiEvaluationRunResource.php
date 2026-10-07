<?php

namespace App\Filament\Resources\AiEvaluationRuns;

use App\Filament\Concerns\AuthorizesAicadKnowledge;
use App\Filament\Pages\AskEvaluationCompare;
use App\Filament\Resources\AiEvaluationRuns\Pages\ListAiEvaluationRuns;
use App\Filament\Resources\AiEvaluationRuns\Pages\ViewAiEvaluationRun;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AiEvaluationRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Evaluation runs: read-only history, written by the runner. */
class AiEvaluationRunResource extends Resource
{
    use AuthorizesAicadKnowledge;

    protected static ?string $model = AiEvaluationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.aicad';

    protected static ?string $navigationLabel = 'panel.aicad_runs.nav';

    protected static ?int $navigationSort = 24;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('created_at')->label(__('panel.aicad_tests.last_run'))->dateTime()->sortable(),
                TextColumn::make('mode')->label(__('panel.playground.mode'))->badge(),
                TextColumn::make('scope.label')->label(__('panel.aicad_runs.scope'))->placeholder('—')->limit(30),
                TextColumn::make('status')->label(__('panel.aicad_runs.status'))->badge()
                    ->color(fn (string $state) => match ($state) {
                        AiEvaluationRun::STATUS_FINISHED => 'success',
                        AiEvaluationRun::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('passed')->label(__('panel.aicad_runs.passed'))->color('success'),
                TextColumn::make('failed')->label(__('panel.aicad_runs.failed'))->color(fn (int $state) => $state > 0 ? 'danger' : null),
                TextColumn::make('skipped')->label(__('panel.aicad_runs.skipped')),
                TextColumn::make('duration_ms')->label(__('panel.aicad_tests.duration'))
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : round($state / 1000, 1).' s'),
                TextColumn::make('git_commit')->label('commit')->formatStateUsing(fn (?string $s) => $s ? substr($s, 0, 8) : '—')->toggleable(),
                TextColumn::make('local_model')->label(__('panel.aicad_runs.model'))->toggleable(),
                TextColumn::make('initiated_by')->label(__('panel.aicad_aliases.created_by'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('compare')->label(__('panel.aicad_runs.compare'))->icon('heroicon-o-arrows-right-left')->color('gray')
                    ->url(fn (AiEvaluationRun $record) => AskEvaluationCompare::getUrl(['current' => $record->id])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiEvaluationRuns::route('/'),
            'view' => ViewAiEvaluationRun::route('/{record}'),
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
        return self::aicadCanManage();
    }
}
