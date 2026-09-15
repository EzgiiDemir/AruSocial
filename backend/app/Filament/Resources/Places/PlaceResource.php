<?php

namespace App\Filament\Resources\Places;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\Places\Pages\CreatePlace;
use App\Filament\Resources\Places\Pages\EditPlace;
use App\Filament\Resources\Places\Pages\ListPlaces;
use App\Filament\Resources\Places\Pages\ViewPlace;
use App\Filament\Resources\Places\Schemas\PlaceForm;
use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\Place;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Buildings, studios and the rest of the campus map.
 *
 * Edited here as well as through `/api/v1/admin/places` — deliberately the
 * same model and the same permission key, so the two surfaces cannot drift
 * into disagreeing about who may change a building.
 */
class PlaceResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = Place::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.campus_map';

    protected static ?string $navigationLabel = 'panel.places.nav';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function permissionKey(): string
    {
        return 'places.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return PlaceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlacesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaces::route('/'),
            'create' => CreatePlace::route('/create'),
            'view' => ViewPlace::route('/{record}'),
            'edit' => EditPlace::route('/{record}/edit'),
        ];
    }
}
