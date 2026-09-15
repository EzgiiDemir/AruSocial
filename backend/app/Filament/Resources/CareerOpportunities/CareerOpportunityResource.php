<?php

namespace App\Filament\Resources\CareerOpportunities;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\CareerOpportunities\Pages\CreateCareerOpportunity;
use App\Filament\Resources\CareerOpportunities\Pages\EditCareerOpportunity;
use App\Filament\Resources\CareerOpportunities\Pages\ListCareerOpportunities;
use App\Filament\Resources\CareerOpportunities\Pages\ViewCareerOpportunity;
use App\Filament\Resources\CareerOpportunities\Schemas\CareerOpportunityForm;
use App\Filament\Resources\CareerOpportunities\Tables\CareerOpportunitiesTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\CareerOpportunity;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CareerOpportunityResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = CareerOpportunity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.operations';

    protected static ?string $navigationLabel = 'panel.career.nav';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    public static function permissionKey(): string
    {
        return 'career.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return CareerOpportunityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CareerOpportunitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCareerOpportunities::route('/'),
            'create' => CreateCareerOpportunity::route('/create'),
            'view' => ViewCareerOpportunity::route('/{record}'),
            'edit' => EditCareerOpportunity::route('/{record}/edit'),
        ];
    }
}
