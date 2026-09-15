<?php

namespace App\Filament\Resources\FoodVenues;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\FoodVenues\Pages\CreateFoodVenue;
use App\Filament\Resources\FoodVenues\Pages\EditFoodVenue;
use App\Filament\Resources\FoodVenues\Pages\ListFoodVenues;
use App\Filament\Resources\FoodVenues\Pages\ViewFoodVenue;
use App\Filament\Resources\FoodVenues\Schemas\FoodVenueForm;
use App\Filament\Resources\FoodVenues\Tables\FoodVenuesTable;
use App\Models\FoodVenue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FoodVenueResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = FoodVenue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.campus';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 60;

    public static function permissionKey(): string
    {
        return 'food.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return FoodVenueForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FoodVenuesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFoodVenues::route('/'),
            'create' => CreateFoodVenue::route('/create'),
            'view' => ViewFoodVenue::route('/{record}'),
            'edit' => EditFoodVenue::route('/{record}/edit'),
        ];
    }
}
