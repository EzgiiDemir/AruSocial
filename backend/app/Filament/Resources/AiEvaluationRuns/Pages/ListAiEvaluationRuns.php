<?php

namespace App\Filament\Resources\AiEvaluationRuns\Pages;

use App\Filament\Pages\AskEvaluationCompare;
use App\Filament\Resources\AiEvaluationRuns\AiEvaluationRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListAiEvaluationRuns extends ListRecords
{
    protected static string $resource = AiEvaluationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('compare')->label(__('panel.aicad_runs.compare'))->icon('heroicon-o-arrows-right-left')
                ->url(AskEvaluationCompare::getUrl()),
        ];
    }
}
