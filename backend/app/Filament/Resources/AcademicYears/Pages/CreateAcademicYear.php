<?php

namespace App\Filament\Resources\AcademicYears\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Models\AcademicYear;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateAcademicYear extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = AcademicYearResource::class;

    protected function idPrefix(): string
    {
        return 'year';
    }

    protected function afterCreate(): void
    {
        AcademicYear::keepOnlyActive($this->record);
        AuditLogger::logAsCurrentUser('create', 'academic_year', $this->record->label);
    }
}
