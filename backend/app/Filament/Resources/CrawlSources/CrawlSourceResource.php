<?php

namespace App\Filament\Resources\CrawlSources;

use App\Filament\Resources\CrawlSources\Pages\CreateCrawlSource;
use App\Filament\Resources\CrawlSources\Pages\EditCrawlSource;
use App\Filament\Resources\CrawlSources\Pages\ListCrawlSources;
use App\Filament\Resources\CrawlSources\Schemas\CrawlSourceForm;
use App\Filament\Resources\CrawlSources\Tables\CrawlSourcesTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\CrawlSource;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin area for the ARUCAD web sites the AICAD knowledge crawler reads.
 *
 * The crawler's allow-list and seed URLs come from these rows (config is only
 * a fallback), so adding a site here is all it takes to bring it into the
 * knowledge base. `access` marks whether a site is reachable globally or only
 * from inside the ARUCAD network. Gated by the same `system.integration`
 * permission as the integrations panel.
 */
class CrawlSourceResource extends Resource
{
    protected static ?string $model = CrawlSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.system';

    protected static ?string $navigationLabel = 'panel.crawl_sources.nav';

    protected static ?string $recordTitleAttribute = 'domain';

    protected static ?int $navigationSort = 35;

    public static function form(Schema $schema): Schema
    {
        return CrawlSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CrawlSourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrawlSources::route('/'),
            'create' => CreateCrawlSource::route('/create'),
            'edit' => EditCrawlSource::route('/{record}/edit'),
        ];
    }

    // --- Authorisation: read to view, manage_settings to change ---

    private static function may(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && GranularPermissions::allows($user, $permission);
    }

    public static function canViewAny(): bool
    {
        return self::may('system.integration.read');
    }

    public static function canView($record): bool
    {
        return self::may('system.integration.read');
    }

    public static function canCreate(): bool
    {
        return self::may('system.integration.manage_settings');
    }

    public static function canEdit($record): bool
    {
        return self::may('system.integration.manage_settings');
    }

    public static function canDelete($record): bool
    {
        return self::may('system.integration.manage_settings');
    }

    public static function canDeleteAny(): bool
    {
        return self::may('system.integration.manage_settings');
    }
}
