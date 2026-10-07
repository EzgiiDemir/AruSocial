<?php

namespace App\Filament\Resources\AiEvaluationCases\Pages;

use App\Filament\Resources\AiEvaluationCases\AiEvaluationCaseResource;
use App\Filament\Resources\AiEvaluationRuns\AiEvaluationRunResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiEvaluationCases extends ListRecords
{
    protected static string $resource = AiEvaluationCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runRetrieval')->label(__('panel.aicad_tests.run_retrieval'))->icon('heroicon-o-bolt')
                ->action(fn () => AiEvaluationCaseResource::startRun('retrieval', ['label' => 'all retrieval'])),
            Action::make('runFull')->label(__('panel.aicad_tests.run_full'))->icon('heroicon-o-cpu-chip')->color('warning')
                ->requiresConfirmation()->modalDescription(__('panel.aicad_tests.run_full_confirm'))
                ->action(fn () => AiEvaluationCaseResource::startRun('full', ['label' => 'all full-answer'])),
            Action::make('runs')->label(__('panel.aicad_runs.nav'))->icon('heroicon-o-queue-list')->color('gray')
                ->url(AiEvaluationRunResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
