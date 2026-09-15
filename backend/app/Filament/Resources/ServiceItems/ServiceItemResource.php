<?php

namespace App\Filament\Resources\ServiceItems;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\ServiceItems\Pages\CreateServiceItem;
use App\Filament\Resources\ServiceItems\Pages\EditServiceItem;
use App\Filament\Resources\ServiceItems\Pages\ListServiceItems;
use App\Filament\Resources\ServiceItems\Pages\ViewServiceItem;
use App\Filament\Resources\ServiceItems\Schemas\ServiceItemForm;
use App\Filament\Resources\ServiceItems\Tables\ServiceItemsTable;
use App\Models\ServiceItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServiceItemResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = ServiceItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.campus';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 50;

    public static function permissionKey(): string
    {
        return 'services.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceItems::route('/'),
            'create' => CreateServiceItem::route('/create'),
            'view' => ViewServiceItem::route('/{record}'),
            'edit' => EditServiceItem::route('/{record}/edit'),
        ];
    }
}
