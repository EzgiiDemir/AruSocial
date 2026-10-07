<?php

namespace App\Filament\Resources\KnowledgeDocuments\Pages;

use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use App\Jobs\CrawlKnowledgeUrlJob;
use App\Jobs\EmbedKnowledgeDocumentJob;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AskTrace;
use App\Services\Knowledge\KnowledgeBase;
use App\Support\Vector;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Inspect one crawled page: what the extractor kept, how it was chunked,
 * and whether each passage has a vector — the first place to look when a
 * page "is crawled" but never reaches an answer.
 */
class ViewKnowledgeDocument extends ViewRecord
{
    protected static string $resource = KnowledgeDocumentResource::class;

    protected string $view = 'filament.pages.knowledge-document';

    /** @return list<array{position: int, chars: int, text: string, model: ?string, dimensions: ?int}> */
    public function chunkRows(): array
    {
        /** @var KnowledgeDocument $document */
        $document = $this->getRecord();

        return $document->chunks()->get()->map(fn ($chunk) => [
            'position' => (int) $chunk->position,
            'chars' => mb_strlen((string) $chunk->text),
            'text' => (string) $chunk->text,
            'model' => $chunk->model,
            'dimensions' => $chunk->embedding === null ? null : count(Vector::unpack($chunk->embedding) ?? []),
        ])->all();
    }

    public function extractedText(): string
    {
        /** @var KnowledgeDocument $document */
        $document = $this->getRecord();

        return (string) ($document->content_clean ?: $document->content);
    }

    protected function getHeaderActions(): array
    {
        $manage = KnowledgeDocumentResource::aicadCanManage();

        return [
            Action::make('testSearch')
                ->label(__('panel.knowledge_documents.test_search'))
                ->icon('heroicon-o-magnifying-glass')
                ->schema([
                    TextInput::make('question')
                        ->label(__('panel.playground.question'))
                        ->required()
                        ->maxLength(500),
                ])
                ->action(fn (array $data) => $this->testSearch((string) $data['question'])),
            Action::make('recrawl')
                ->label(__('panel.knowledge_sources.crawl_now'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible($manage)
                ->action(function (): void {
                    CrawlKnowledgeUrlJob::dispatch((string) $this->getRecord()->url);
                    Notification::make()->success()->title(__('panel.knowledge_sources.crawl_queued'))->send();
                }),
            Action::make('reembed')
                ->label(__('panel.knowledge_documents.reembed'))
                ->icon('heroicon-o-cpu-chip')
                ->color('gray')
                ->visible($manage)
                ->action(function (): void {
                    EmbedKnowledgeDocumentJob::dispatch((string) $this->getRecord()->getKey());
                    Notification::make()->success()->title(__('panel.knowledge_documents.reembed_queued'))->send();
                }),
        ];
    }

    /** Where this page ranks for a question, and why. */
    private function testSearch(string $question): void
    {
        $trace = new AskTrace;
        $trace->setVerbose();
        app()->instance(AskTrace::class, $trace);

        app(KnowledgeBase::class)->relevant($question);

        $url = (string) $this->getRecord()->url;
        $candidates = $trace->get('knowledge.candidates')['candidates'] ?? [];
        foreach ($candidates as $candidate) {
            if ($candidate['url'] === $url) {
                $parts = implode(', ', array_map(
                    fn ($k, $v) => "{$k} {$v}", array_keys($candidate['parts']), $candidate['parts'],
                ));
                Notification::make()
                    ->title(__('panel.knowledge_documents.rank', ['rank' => $candidate['rank'], 'score' => $candidate['score']]))
                    ->body($parts)
                    ->color($candidate['selected'] ? 'success' : 'warning')
                    ->persistent()
                    ->send();

                return;
            }
        }

        Notification::make()->warning()
            ->title(__('panel.knowledge_documents.not_ranked', ['count' => count($candidates)]))
            ->persistent()
            ->send();
    }
}
