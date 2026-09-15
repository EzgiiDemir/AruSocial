<?php

namespace App\Filament\Resources\Sports;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\Sports\Pages\CreateSport;
use App\Filament\Resources\Sports\Pages\EditSport;
use App\Filament\Resources\Sports\Pages\ListSports;
use App\Filament\Resources\Sports\Pages\ViewSport;
use App\Filament\Resources\Sports\Schemas\SportForm;
use App\Filament\Resources\Sports\Tables\SportsTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\Sport;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SportResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = Sport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.operations';

    protected static ?string $navigationLabel = 'panel.sports.nav';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 40;

    public static function permissionKey(): string
    {
        return 'sports.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return SportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSports::route('/'),
            'create' => CreateSport::route('/create'),
            'view' => ViewSport::route('/{record}'),
            'edit' => EditSport::route('/{record}/edit'),
        ];
    }
}
