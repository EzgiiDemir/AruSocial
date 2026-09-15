<?php

namespace App\Filament\Resources\RoleGrants;

use App\Filament\Resources\RoleGrants\Pages\CreateRoleGrant;
use App\Filament\Resources\RoleGrants\Pages\EditRoleGrant;
use App\Filament\Resources\RoleGrants\Pages\ListRoleGrants;
use App\Filament\Resources\RoleGrants\Pages\ViewRoleGrant;
use App\Filament\Resources\RoleGrants\Schemas\RoleGrantForm;
use App\Filament\Resources\RoleGrants\Tables\RoleGrantsTable;
use App\Filament\Resources\TranslatedResource as Resource;
use App\Models\RoleGrant;
use App\Models\User;
use App\Services\GranularPermissions;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RoleGrantResource extends Resource
{
    protected static ?string $model = RoleGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.users';

    protected static ?string $navigationLabel = 'panel.roles.nav';

    protected static ?int $navigationSort = 20;

    private static function mayManage(): bool
    {
        return auth()->user() instanceof User && (
            GranularPermissions::allows(auth()->user(), 'users.manage')
            || GranularPermissions::allows(auth()->user(), 'users.role.manage_settings')
        );
    }

    public static function canViewAny(): bool
    {
        return self::mayManage();
    }

    public static function canView($record): bool
    {
        return self::mayManage();
    }

    public static function canCreate(): bool
    {
        return self::mayManage();
    }

    public static function canEdit($record): bool
    {
        return self::mayManage() && $record?->user_id !== auth()->id()
            && ($record?->role !== GranularPermissions::SUPER_ROLE || GranularPermissions::legacyAllows(auth()->user(), 'users.manage'));
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        if (! self::canEdit($record)) {
            return false;
        }

        return $record->role !== GranularPermissions::SUPER_ROLE
            || RoleGrant::query()->active()->where('role', GranularPermissions::SUPER_ROLE)->count() > 1;
    }

    public static function form(Schema $schema): Schema
    {
        return RoleGrantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoleGrantsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListRoleGrants::route('/'), 'create' => CreateRoleGrant::route('/create'), 'view' => ViewRoleGrant::route('/{record}'), 'edit' => EditRoleGrant::route('/{record}/edit')];
    }
}
