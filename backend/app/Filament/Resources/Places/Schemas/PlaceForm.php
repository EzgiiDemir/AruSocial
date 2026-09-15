<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Models\Place;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.places.section'))
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(160)
                        ->columnSpanFull(),

                    TextInput::make('category')
                        ->required()
                        ->maxLength(60)
                        ->datalist(fn () => Place::query()
                            ->whereNotNull('category')
                            ->distinct()
                            ->orderBy('category')
                            ->pluck('category')
                            ->all())
                        ->helperText(__('panel.places.category_help')),

                    // `street`, `description` and `distance` are NOT NULL with
                    // an empty-string default. A column default only applies when
                    // the column is left out of the INSERT, and an empty field
                    // here sends an explicit null — which the constraint rejects.
                    TextInput::make('street')
                        ->maxLength(200)
                        ->label(__('panel.places.street'))
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),

                    Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull()
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),
                ])
                ->columns(2),

            Section::make(__('panel.places.map'))
                ->description(__('panel.places.map_help'))
                ->schema([
                    // Kyrenia is around 35.3 / 33.3. A transposed pair puts
                    // the building in the Indian Ocean, and nothing later in
                    // the app notices — so the bounds are checked here.
                    TextInput::make('lat')
                        ->label(__('panel.places.latitude'))
                        ->required()
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90)
                        ->step('any'),

                    TextInput::make('lng')
                        ->label(__('panel.places.longitude'))
                        ->required()
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180)
                        ->step('any'),

                    Toggle::make('accessible')
                        ->label(__('panel.places.accessible'))
                        ->helperText(
                            'Shown to students who filter for it, so an optimistic '
                            .'answer here sends someone to a door they cannot use.'
                        ),

                    TextInput::make('distance')
                        ->maxLength(40)
                        ->label(__('panel.places.distance'))
                        ->helperText(__('panel.places.distance_help'))
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),
                ])
                ->columns(2),

            Section::make(__('panel.places.tour'))
                ->description(__('panel.places.tour_help'))
                ->schema([
                    TextInput::make('tour_url')
                        ->label(__('panel.places.tour_url'))
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),

                    TextInput::make('tour_target')
                        ->label(__('panel.places.tour_target'))
                        ->maxLength(160)
                        ->helperText(__('panel.places.tour_target_help')),
                ])
                ->columns(2)
                ->collapsed(),

            // `photos` and `rating` are deliberately absent: `photos` is an
            // integer count maintained elsewhere, and `rating` is the average
            // of student reviews. Both are derived, and a form field over a
            // derived value is a way to write a number that disagrees with
            // the thing it describes.
            Section::make(__('panel.places.media'))
                ->schema([
                    TextInput::make('cover_url')
                        ->label(__('panel.places.cover_url'))
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ]);
    }
}
