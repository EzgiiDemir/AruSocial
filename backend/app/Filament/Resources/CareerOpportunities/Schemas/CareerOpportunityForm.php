<?php

namespace App\Filament\Resources\CareerOpportunities\Schemas;

use App\Models\CareerOpportunity;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CareerOpportunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.career.section'))
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),

                    TextInput::make('kind')
                        ->required()
                        ->maxLength(60)
                        ->label(__('panel.career.kind'))
                        ->datalist(fn () => CareerOpportunity::query()
                            ->whereNotNull('kind')
                            ->distinct()
                            ->orderBy('kind')
                            ->pluck('kind')
                            ->all())
                        ->helperText(__('panel.career.kind_help')),

                    TextInput::make('organization')
                        ->maxLength(200)
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),

                    TextInput::make('department')->maxLength(160),
                    TextInput::make('location')->maxLength(160),
                    TextInput::make('work_type')->label(__('panel.career.work_type'))->maxLength(60),

                    TextInput::make('url')
                        ->label(__('panel.career.url'))
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Section::make(__('panel.career.dates'))
                ->schema([
                    DatePicker::make('posted_at')->label(__('panel.career.posted')),

                    // Past this date the listing stops accepting applications,
                    // so it is the field most worth getting right.
                    DatePicker::make('deadline'),

                    Toggle::make('published')
                        ->default(true)
                        ->helperText(__('panel.career.published_help')),
                ])
                ->columns(3),

            Section::make(__('panel.career.details'))
                ->schema([
                    Textarea::make('description')->rows(4)->columnSpanFull(),
                    Textarea::make('purpose')->rows(3)->columnSpanFull(),
                    Textarea::make('skills')->rows(3),
                    Textarea::make('experience')->rows(3),
                    Textarea::make('education')->rows(3),
                    Textarea::make('extra_info')->label(__('panel.career.extra_info'))->rows(3),
                ])
                ->columns(2)
                ->collapsed(),
        ]);
    }
}
