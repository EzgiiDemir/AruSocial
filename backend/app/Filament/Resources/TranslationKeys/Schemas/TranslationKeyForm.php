<?php

namespace App\Filament\Resources\TranslationKeys\Schemas;

use App\Models\TranslationKey;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TranslationKeyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The string')
                ->description('What this text is, independent of language.')
                ->schema([
                    TextInput::make('key')
                        ->required()
                        ->maxLength(120)
                        ->unique(ignoreRecord: true)
                        ->regex('/^[a-z][a-z0-9_]*$/')
                        ->helperText(
                            'Lowercase, underscores. This is what the app calls — '
                            .'renaming it breaks every installed build until they update.'
                        )
                        // The app looks strings up by this. Changing it on an
                        // existing key would silently blank that text on every
                        // phone already out there.
                        ->disabledOn('edit'),

                    TextInput::make('description')
                        ->maxLength(300)
                        ->helperText(
                            'Where it appears and anything a translator needs to know '
                            .'— button width, tone, who reads it.'
                        ),

                    TextInput::make('area')
                        ->maxLength(60)
                        ->datalist(fn () => TranslationKey::query()
                            ->whereNotNull('area')
                            ->distinct()
                            ->orderBy('area')
                            ->pluck('area')
                            ->all())
                        ->helperText('Groups the list. e.g. profile, chat, legal.'),

                    Toggle::make('is_plural')
                        ->label('Has plural forms')
                        ->helperText(
                            'Russian needs one / few / many / other. Turkish and '
                            .'English need one / other.'
                        ),

                    Repeater::make('placeholders')
                        ->label('Placeholders')
                        ->simple(TextInput::make('name')->regex('/^[a-zA-Z][a-zA-Z0-9_]*$/'))
                        ->helperText(
                            'Names used as {name} in the text. Every language must '
                            .'contain all of them — a translation that drops one '
                            .'renders a gap where the value should be.'
                        )
                        ->default([])
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Translations')
                ->description(
                    'Editing a draft changes nothing students see. Publish from the '
                    .'list or the row action when it is ready.'
                )
                ->schema([
                    Repeater::make('translations')
                        ->relationship()
                        ->schema([
                            Select::make('locale')
                                ->options(array_combine(
                                    TranslationKey::LOCALES,
                                    ['Türkçe', 'English', 'Русский'],
                                ))
                                ->required()
                                ->distinct()
                                ->disabledOn('edit'),

                            Textarea::make('draft')
                                ->label('Draft')
                                ->rows(3)
                                ->columnSpanFull(),

                            Textarea::make('published')
                                ->label('Published — what students see now')
                                ->rows(2)
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                        ])
                        ->columns(1)
                        ->itemLabel(fn (array $state): ?string => strtoupper($state['locale'] ?? '?'))
                        ->addActionLabel('Add a language')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
