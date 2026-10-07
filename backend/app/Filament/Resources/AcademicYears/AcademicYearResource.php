<?php

namespace App\Filament\Resources\AcademicYears;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\AcademicYears\Pages\CreateAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\EditAcademicYear;
use App\Filament\Resources\AcademicYears\Pages\ListAcademicYears;
use App\Filament\Resources\AcademicYears\Schemas\AcademicYearForm;
use App\Filament\Resources\AcademicYears\Tables\AcademicYearsTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AcademicYear;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Academic years. The in-app staff screens were removed (one staff
 * surface), which left no screen for this table: an ended year stayed
 * "active" and new events were tagged with it. Term DATES come from the
 * official calendar page (AcademicCalendar); this record decides which
 * year new events belong to and what counts as stale evidence.
 */
class AcademicYearResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = AcademicYear::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.operations';

    protected static ?string $navigationLabel = 'panel.academic_years.nav';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?int $navigationSort = 70;

    public static function permissionKey(): string
    {
        return 'academicYears.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return AcademicYearForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AcademicYearsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAcademicYears::route('/'),
            'create' => CreateAcademicYear::route('/create'),
            'edit' => EditAcademicYear::route('/{record}/edit'),
        ];
    }
}
