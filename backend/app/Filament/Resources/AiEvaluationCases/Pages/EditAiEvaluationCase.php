<?php

namespace App\Filament\Resources\AiEvaluationCases\Pages;

use App\Filament\Resources\AiEvaluationCases\AiEvaluationCaseResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiEvaluationCase extends EditRecord
{
    protected static string $resource = AiEvaluationCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')->label(__('panel.playground.run'))->icon('heroicon-o-play')
                ->action(fn () => AiEvaluationCaseResource::startRun($this->getRecord()->mode, [
                    'case_ids' => [$this->getRecord()->id], 'label' => $this->getRecord()->name,
                ])),
            DeleteAction::make(),
        ];
    }
}
