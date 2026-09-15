<?php

namespace App\Filament\Resources\AdminPages;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\AdminPages\Pages\CreateAdminPage;
use App\Filament\Resources\AdminPages\Pages\EditAdminPage;
use App\Filament\Resources\AdminPages\Pages\ListAdminPages;
use App\Filament\Resources\AdminPages\Pages\ViewAdminPage;
use App\Filament\Resources\AdminPages\Schemas\AdminPageForm;
use App\Filament\Resources\AdminPages\Tables\AdminPagesTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\AdminPage;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AdminPageResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = AdminPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.content_management';

    protected static ?string $navigationLabel = 'panel.pages.nav';

    protected static ?string $modelLabel = 'page';

    protected static ?string $pluralModelLabel = 'pages';

    protected static ?int $navigationSort = 10;

    public static function permissionKey(): string
    {
        return 'pages.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return AdminPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminPagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdminPages::route('/'),
            'create' => CreateAdminPage::route('/create'),
            'view' => ViewAdminPage::route('/{record}'),
            'edit' => EditAdminPage::route('/{record}/edit'),
        ];
    }
}
