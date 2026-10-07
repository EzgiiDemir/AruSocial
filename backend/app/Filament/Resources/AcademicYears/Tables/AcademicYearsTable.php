<?php

namespace App\Filament\Resources\AcademicYears\Tables;

use App\Filament\Resources\AcademicYears\AcademicYearResource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcademicYearsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('label')->label(__('panel.academic_years.label'))->searchable()->sortable()->weight('medium'),
                IconColumn::make('is_active')->label(__('panel.academic_years.is_active'))->boolean(),
                TextColumn::make('starts_on')->label(__('panel.academic_years.starts_on'))->date()->sortable(),
                TextColumn::make('ends_on')->label(__('panel.academic_years.ends_on'))->date()->sortable()
                    // An "active" year that has ended is the data-quality problem to fix.
                    ->color(fn ($record) => $record->is_active && $record->ends_on?->isPast() ? 'danger' : null),
                TextColumn::make('deleted_at')->label(__('panel.common.deleted_at'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(AcademicYearResource::contentFilters())
            ->recordActions(AcademicYearResource::contentRecordActions())
            ->toolbarActions(AcademicYearResource::contentToolbarActions());
    }
}
