<?php

namespace App\Filament\Resources\ShuttleRoutes;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\ShuttleRoutes\Pages\CreateShuttleRoute;
use App\Filament\Resources\ShuttleRoutes\Pages\EditShuttleRoute;
use App\Filament\Resources\ShuttleRoutes\Pages\ListShuttleRoutes;
use App\Filament\Resources\ShuttleRoutes\Pages\ViewShuttleRoute;
use App\Filament\Resources\ShuttleRoutes\Schemas\ShuttleRouteForm;
use App\Filament\Resources\ShuttleRoutes\Tables\ShuttleRoutesTable;
use App\Models\ShuttleRoute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShuttleRouteResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = ShuttleRoute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.campus';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 70;

    public static function permissionKey(): string
    {
        return 'shuttle.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return ShuttleRouteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShuttleRoutesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShuttleRoutes::route('/'),
            'create' => CreateShuttleRoute::route('/create'),
            'view' => ViewShuttleRoute::route('/{record}'),
            'edit' => EditShuttleRoute::route('/{record}/edit'),
        ];
    }
}
