<?php

namespace App\Filament\Resources\ServiceItems\Schemas;

use App\Models\ServiceItem;
use App\Models\StaffProfile;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.services.section'))
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(160)
                        ->columnSpanFull(),

                    TextInput::make('category')
                        ->required()
                        ->maxLength(60)
                        ->datalist(fn () => ServiceItem::query()
                            ->whereNotNull('category')
                            ->distinct()
                            ->orderBy('category')
                            ->pluck('category')
                            ->all()),

                    TextInput::make('hours')
                        ->maxLength(120)
                        ->label(__('panel.services.hours')),

                    // Both NOT NULL with an empty-string default.
                    Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull()
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),
                ])
                ->columns(2),

            Section::make(__('panel.services.where'))
                ->schema([
                    TextInput::make('building')->maxLength(120),
                    TextInput::make('floor')->maxLength(60),
                    TextInput::make('room')->maxLength(60),

                    TextInput::make('contact_person')
                        ->label(__('panel.services.contact_person'))
                        ->maxLength(160),

                    TextInput::make('contact')
                        ->maxLength(160)
                        ->helperText(__('panel.services.contact_help'))
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),

                    // Kept exactly as typed: AICAD repeats it digit for digit.
                    TextInput::make('phone')
                        ->label(__('panel.services.phone'))
                        ->helperText(__('panel.services.phone_help'))
                        // Not ->tel(): its built-in pattern rejects "+90 (392) …".
                        ->inputMode('tel')
                        ->maxLength(40)
                        ->rule(ServiceItem::PHONE_RULE)
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (filled($value) && strlen((string) preg_replace('/\D+/', '', (string) $value)) < 7) {
                                $fail(__('panel.services.phone_invalid'));
                            }
                        }),

                    Select::make('responsible_staff_id')
                        ->label(__('panel.common.responsible_staff'))
                        ->options(fn () => StaffProfile::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable(),
                ])
                ->columns(3),

            Section::make(__('panel.services.topics'))
                ->description(__('panel.services.topics_help'))
                ->schema([
                    Repeater::make('topics')
                        ->simple(TextInput::make('topic')->maxLength(80))
                        ->default([])
                        ->addActionLabel(__('panel.services.add_topic'))
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ]);
    }
}
