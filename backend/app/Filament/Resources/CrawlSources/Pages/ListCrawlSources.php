<?php

namespace App\Filament\Resources\CrawlSources\Pages;

use App\Filament\Resources\CrawlSources\CrawlSourceResource;
use App\Support\CrawlSourceImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListCrawlSources extends ListRecords
{
    protected static string $resource = CrawlSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Add many sources at once, from a CSV or from a pasted list.
     *
     * Adding them one at a time is the wrong shape for the job: the lists
     * that exist are lists of PAGES — hundreds of arucad.edu.tr URLs — while
     * a crawl source is a HOST. The parser collapses one to the other, so an
     * operator can paste what they have instead of first working out which
     * distinct sites it covers.
     */
    private function importAction(): Action
    {
        return Action::make('bulkImport')
            ->label(__('panel.crawl_sources.import'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading(__('panel.crawl_sources.import'))
            ->modalDescription(__('panel.crawl_sources.import_hint'))
            ->modalSubmitActionLabel(__('panel.crawl_sources.import_submit'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('panel.crawl_sources.import_file'))
                    /*
                     * Deliberately broad. A .csv has no single MIME type: the
                     * browser reports text/csv, application/csv or
                     * application/vnd.ms-excel depending on what is installed
                     * to open it on that machine, and Windows with Excel
                     * present often sends something else again. A narrow list
                     * makes the file picker grey the file out, which looks
                     * like the feature is broken rather than like a rejected
                     * upload. Nothing is trusted on the strength of this: the
                     * size is capped and the content has to parse into hosts
                     * before a single row is written.
                     */
                    ->acceptedFileTypes([
                        'text/csv', 'application/csv', 'text/plain',
                        'text/comma-separated-values', 'text/x-comma-separated-values',
                        'application/vnd.ms-excel', 'application/octet-stream',
                    ])
                    ->maxSize(4096)
                    // Kept out of permanent storage: the file is read once
                    // here and has no life after the import.
                    ->storeFiles(false),

                Textarea::make('text')
                    ->label(__('panel.crawl_sources.import_paste'))
                    ->rows(8)
                    ->helperText(__('panel.crawl_sources.import_paste_hint')),
            ])
            ->action(function (array $data): void {
                $text = $this->textFrom($data);

                if (trim($text) === '') {
                    Notification::make()
                        ->warning()
                        ->title(__('panel.crawl_sources.import_empty'))
                        ->send();

                    return;
                }

                $result = app(CrawlSourceImport::class)->apply($text);

                if (array_sum($result) === 0) {
                    Notification::make()
                        ->warning()
                        ->title(__('panel.crawl_sources.import_none'))
                        ->body(__('panel.crawl_sources.import_none_body'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('panel.crawl_sources.import_done'))
                    ->body(__('panel.crawl_sources.import_summary', $result))
                    ->send();
            });
    }

    /**
     * The text to import: the uploaded file, the pasted box, or both.
     *
     * @param  array<string, mixed>  $data
     */
    private function textFrom(array $data): string
    {
        $parts = [];

        $file = $data['file'] ?? null;
        // storeFiles(false) hands back the upload itself; Filament wraps a
        // single upload in an array keyed by its own id.
        $file = is_array($file) ? reset($file) : $file;
        if ($file instanceof TemporaryUploadedFile) {
            $parts[] = (string) file_get_contents($file->getRealPath());
        }

        if (is_string($data['text'] ?? null)) {
            $parts[] = $data['text'];
        }

        return implode("\n", $parts);
    }
}
