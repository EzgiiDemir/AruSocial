<?php

namespace App\Filament\Resources\CrawlSources\Schemas;

use App\Models\CrawlSource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CrawlSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.crawl_sources.section'))
                ->schema([
                    // The bare host, e.g. "arucad.edu.tr". Not a full URL — the
                    // crawler builds https://domain/ itself and follows links.
                    TextInput::make('domain')
                        ->label(__('panel.crawl_sources.domain'))
                        ->required()
                        ->maxLength(190)
                        ->helperText(__('panel.crawl_sources.domain_hint'))
                        // Domain is the primary key: editable only on create.
                        ->disabledOn('edit'),

                    TextInput::make('label')
                        ->label(__('panel.crawl_sources.label'))
                        ->maxLength(190),

                    // Routing keywords. A question containing one of these
                    // lifts this source's pages in retrieval, which is far
                    // cheaper than letting the ranker rediscover the
                    // association from the page text on every question.
                    Textarea::make('keys')
                        ->label(__('panel.crawl_sources.keys'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->helperText(__('panel.crawl_sources.keys_hint'))
                        ->columnSpanFull(),

                    Select::make('access')
                        ->label(__('panel.crawl_sources.access'))
                        ->options([
                            CrawlSource::ACCESS_GLOBAL => __('panel.crawl_sources.access_global'),
                            CrawlSource::ACCESS_LOCAL => __('panel.crawl_sources.access_local'),
                        ])
                        ->default(CrawlSource::ACCESS_GLOBAL)
                        ->required()
                        ->helperText(__('panel.crawl_sources.access_hint')),

                    Toggle::make('enabled')
                        ->label(__('panel.crawl_sources.enabled'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }
}
