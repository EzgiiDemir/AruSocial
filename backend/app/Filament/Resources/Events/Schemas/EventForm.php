<?php

namespace App\Filament\Resources\Events\Schemas;

use App\Models\AcademicYear;
use App\Models\Event;
use App\Models\Place;
use App\Models\StaffProfile;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.events.section'))
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),

                    TextInput::make('category')
                        ->required()
                        ->maxLength(60)
                        ->datalist(fn () => Event::query()
                            ->whereNotNull('category')
                            ->distinct()
                            ->orderBy('category')
                            ->pluck('category')
                            ->all()),

                    DatePicker::make('event_date')->label(__('panel.events.date')),

                    // Free text rather than a time picker: the campus
                    // calendar prints things like "18:00 - 20:00".
                    TextInput::make('time')
                        ->required()
                        ->maxLength(60)
                        ->helperText(__('panel.events.time_help'))
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),

                    Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull()
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),
                ])
                ->columns(3),

            Section::make(__('panel.events.where'))
                ->schema([
                    Select::make('place_id')
                        ->label(__('panel.events.campus_place'))
                        ->options(fn () => Place::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->helperText(__('panel.events.campus_place_help')),

                    // Kept alongside `place_id` because plenty of events are
                    // off campus, where there is no place row to point at.
                    TextInput::make('place_name')
                        ->label(__('panel.events.place_name'))
                        ->required()
                        ->maxLength(200)
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),
                ])
                ->columns(2),

            Section::make(__('panel.events.who'))
                ->schema([
                    TextInput::make('organizer')
                        ->maxLength(200)
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?? ''),

                    TextInput::make('organizer_email')
                        ->label(__('panel.events.organizer_email'))
                        ->email()
                        ->maxLength(200),

                    Select::make('responsible_staff_id')
                        ->label(__('panel.common.responsible_staff'))
                        ->options(fn () => StaffProfile::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable(),

                    TextInput::make('audience')
                        ->maxLength(120)
                        ->default('Tümü')
                        ->dehydrateStateUsing(fn (?string $state): string => $state ?: 'Tümü'),

                    Select::make('academic_year_id')
                        ->label(__('panel.events.academic_year'))
                        // `label`, not `name`: academic_years has no name column. SQLite
                        // never evaluated this closure so the mistake was invisible
                        // until the suite ran against PostgreSQL.
                        ->options(fn () => AcademicYear::query()
                            ->orderByDesc('id')
                            ->pluck('label', 'id')
                            ->all())
                        ->searchable(),

                    TextInput::make('xp')
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->helperText(__('panel.events.xp_help')),
                ])
                ->columns(3),

            Section::make(__('panel.events.publishing'))
                ->description(__('panel.events.publishing_help'))
                ->schema([
                    Toggle::make('draft')
                        ->helperText(__('panel.events.draft_help')),

                    Select::make('workflow_status')
                        ->options([
                            'draft' => __('panel.events.workflow.draft'),
                            'pending' => __('panel.events.workflow.pending'),
                            'published' => __('panel.events.workflow.published'),
                            'rejected' => __('panel.events.workflow.rejected'),
                        ])
                        ->default('published')
                        ->required(),

                    DateTimePicker::make('publish_at')
                        ->label(__('panel.events.publish_at'))
                        ->helperText(__('panel.events.publish_at_help')),

                    DateTimePicker::make('expires_at')
                        ->label(__('panel.events.expires_at'))
                        ->helperText(__('panel.events.expires_at_help')),

                    Textarea::make('review_note')
                        ->label(__('panel.events.review_note'))
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
