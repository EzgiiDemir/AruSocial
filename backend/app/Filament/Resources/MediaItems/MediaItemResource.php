<?php

namespace App\Filament\Resources\MediaItems;

use App\Filament\Concerns\ManagesCampusContent;
use App\Filament\Resources\MediaItems\Pages\ListMediaItems;
use App\Filament\Resources\MediaItems\Tables\MediaItemsTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\MediaItem;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Media Library.
 *
 * Listing only — there is no create page and no edit form, deliberately.
 * Media arrives by being uploaded with a post or a place; a row typed in by
 * hand would name a file that does not exist, and editing the path of an
 * existing row breaks every post pointing at it. What staff need here is to
 * see what is stored, see what is using it, and remove what should not be.
 */
class MediaItemResource extends Resource
{
    use ManagesCampusContent;

    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.content_management';

    protected static ?string $navigationLabel = 'panel.media.nav';

    protected static ?string $recordTitleAttribute = 'file_name';

    protected static ?int $navigationSort = 10;

    public static function permissionKey(): string
    {
        return 'media.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return MediaItemsTable::configure($table);
    }

    /** Media is uploaded, never typed in. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaItems::route('/'),
        ];
    }
}
